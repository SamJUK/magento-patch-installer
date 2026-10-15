<?php

// Copyright (c) 2026 Sam James. Licensed under the Open Software License version 3.0.
// SPDX-License-Identifier: OSL-3.0

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller\Tests;

use Composer\IO\BufferIO;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Util\ProcessExecutor;
use SamJUK\MagentoPatchInstaller\Applier;
use SamJUK\MagentoPatchInstaller\Collector;
use SamJUK\MagentoPatchInstaller\Fragment;
use SamJUK\MagentoPatchInstaller\Patcher;
use SamJUK\MagentoPatchInstaller\Renderer;
use SamJUK\MagentoPatchInstaller\TargetResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @return array<string, callable>
 */
return [

'collector' => static function (Sandbox $sandbox): void {
    $patch = "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1,2 @@\n one\n+two\n";

    $meta = static function (array $extra): array {
        return ['magento-patches' => $extra];
    };

    /** @var callable(string): Project $project */
    $project = static function (string $name) use ($sandbox): Project {
        return new Project($sandbox, $name);
    };

    Assert::case('nothing is read from an untrusted package');
    $p = $project('untrusted')
        ->root($meta([]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'A-1', 'source' => 'a.patch']]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    Assert::same([], $collector->collect(), 'no declarations');
    Assert::same(1, count($collector->errors()), 'but it is reported, not ignored');
    Assert::contains($collector->errors()[0], 'is not trusted', 'and says why');

    Assert::case('a trusted package is read');
    $p = $project('trusted')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'A-1', 'source' => 'a.patch']]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same([], $collector->errors(), 'no complaints');
    Assert::same(1, count($declarations), 'one declaration');
    Assert::same('A-1', $declarations[0]['id'], 'the id survives');
    Assert::same(null, $declarations[0]['skipped'], 'and nothing is skipped');

    Assert::case('include: a manifest beside its patches');
    // The whole point of splitting: source paths resolve against the file that
    // names them, so a manifest can sit in the directory it describes.
    $p = $project('include')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['include' => ['patches/isolated/2026.json']]));
    $p->write('vendor/acme/patches/patches/isolated/x.patch', $patch);
    $p->write('vendor/acme/patches/patches/isolated/2026.json', (string) json_encode([
        'lines' => [
            '2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'cumulative' => true, 'patches' => [['id' => 'X-1', 'source' => 'x.patch']],
            ],
        ],
    ]));

    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same([], $collector->errors(), 'no complaints');
    Assert::same(1, count($declarations), 'the included patch is found');
    Assert::same('X-1', $declarations[0]['id'], 'with its id');
    Assert::same('2.4.8-p5', $declarations[0]['line'], 'and its base line');
    Assert::true(
        substr((string) $declarations[0]['source'], -strlen('patches/isolated/x.patch'))
            === 'patches/isolated/x.patch',
        'resolved next to the manifest, not to the package root'
    );

    Assert::case('include: inline and included declarations merge');
    $p = $project('include-merge')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta([
            'patches' => [['id' => 'INLINE', 'source' => 'a.patch']],
            'include' => ['more.json'],
        ]));
    $p->write('vendor/acme/patches/a.patch', $patch);
    $p->write('vendor/acme/patches/b.patch', $patch);
    $p->write('vendor/acme/patches/more.json', (string) json_encode([
        'patches' => [['id' => 'INCLUDED', 'source' => 'b.patch']],
    ]));

    $ids = [];
    foreach ($p->collector()->collect() as $declaration) {
        $ids[] = $declaration['id'];
    }
    Assert::same(['INLINE', 'INCLUDED'], $ids, 'both, in order');

    Assert::case('include: a file that cannot be read is an error, not a shorter list');
    $p = $project('include-broken')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['include' => ['missing.json', 'garbage.json']]));
    $p->write('vendor/acme/patches/garbage.json', 'not json');

    $collector = $p->collector();
    $collector->collect();
    $errors = implode("\n", $collector->errors());
    Assert::contains($errors, 'missing.json', 'a missing file is named');
    Assert::contains($errors, 'not readable JSON', 'and so is an unparseable one');

    Assert::case('include: no escaping the package, and no nesting');
    $p = $project('include-escape')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['include' => ['../../../outside.json']]));
    $p->write('outside.json', (string) json_encode(['patches' => []]));

    $collector = $p->collector();
    $collector->collect();
    Assert::contains(implode("\n", $collector->errors()), 'outside its own package', 'traversal refused');

    $p = $project('include-nested')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['include' => ['one.json']]));
    $p->write('vendor/acme/patches/one.json', (string) json_encode(['include' => ['two.json']]));

    $collector = $p->collector();
    $collector->collect();
    Assert::contains(
        implode("\n", $collector->errors()),
        'cannot include others',
        'one level only, so the whole set is readable in one place'
    );

    // ---------------------------------------------------------------- selection

    /** @var callable(string, array): Project $chain */
    $chain = static function (string $name, array $sources) use ($project, $meta, $patch): Project {
        $p = $project($name)
            ->root($meta(['trust' => ['acme/*'], 'sources' => $sources]))
            ->package('acme/patches', $meta([
                'patches' => [['id' => 'FREE-1', 'label' => 'Standalone', 'source' => 'a.patch']],
                'lines' => [
                    '2.4.8-p5' => [
                        'base' => ['magento/magento2-base' => '2.4.8-p5'],
                        // Adobe's stipulation, written down rather than inferred
                        // from the order of this array.
                        'patches' => [
                            ['id' => '2026-07-001', 'source' => 'a.patch'],
                            ['id' => '2026-08-001', 'source' => 'a.patch', 'depends' => ['2026-07-001']],
                            ['id' => '2026-09-001', 'source' => 'a.patch', 'depends' => ['2026-08-001']],
                            // The tail of both lines: present twice, depended on
                            // by nothing, so a bare skip of it is allowed and has
                            // two places to reach.
                            ['id' => '2026-10-001', 'source' => 'a.patch', 'depends' => ['2026-09-001']],
                            ['id' => 'APSB26-146', 'label' => 'StyleSmuggler', 'source' => 'a.patch',
                             'depends' => ['2026-08-001']],
                        ],
                    ],
                    '2.4.6-p15' => [
                        'base' => ['magento/magento2-base' => '2.4.6-p15'],
                        'cumulative' => true, 'patches' => [
                            ['id' => '2026-08-001', 'source' => 'a.patch', 'depends' => ['2026-07-001']],
                            ['id' => '2026-10-001', 'source' => 'a.patch', 'depends' => ['2026-09-001']],
                        ],
                    ],
                ],
            ]));
        $p->write('vendor/acme/patches/a.patch', $patch);

        return $p;
    };

    /** @var callable(array): array<string, string|null> $skips */
    $skips = static function (array $declarations): array {
        $out = [];
        foreach ($declarations as $declaration) {
            $out[$declaration['label']] = $declaration['skipped'];
        }

        return $out;
    };

    Assert::case('an emergency patch can depend on an isolated one');
    // The case that index-derived ordering could not express at all:
    // StyleSmuggler comes after that month's isolated patch, and the two live
    // in different blocks.
    $order = [];
    foreach ($chain('cross-kind', [])->collector()->collect() as $declaration) {
        $order[] = (string) $declaration['id'];
    }
    Assert::same(
        true,
        array_search('APSB26-146', $order, true) > array_search('2026-08-001', $order, true),
        'StyleSmuggler is ordered after the patch it names'
    );

    // An emergency patch that forgets `depends` must not sail in front of the
    // chain on declaration order alone. Adobe ships it on top of that month's
    // isolated patch, so `isolated` is emitted first and the tiebreak agrees.
    Assert::same(
        true,
        array_search('APSB26-146', $order, true) > array_search('2026-09-001', $order, true),
        'and after the whole chain, even though it only names the middle of it'
    );

    Assert::case('selection: nothing skipped by default');
    $states = $skips($chain('sel-default', [])->collector()->collect());
    Assert::same(8, count($states), 'every declaration');
    Assert::same(0, count(array_filter($states)), 'and none switched off');

    Assert::case('selection: a bare id hits every line it appears in');
    // Ids are unique only within a line. Quietly picking one of several would
    // be worse than either matching all or requiring a scope.
    $states = $skips($chain('sel-bare', [
        'acme/patches' => ['skip' => ['FREE-1' => 'breaks checkout']],
    ])->collector()->collect());
    Assert::contains((string) $states['FREE-1 Standalone'], 'breaks checkout', 'the reason is kept');

    // FREE-1 exists in exactly one place, so on its own it proves nothing about
    // matching every line. 2026-10-001 is declared in both, and nothing depends
    // on it in either, so the bare form is allowed and has to reach both.
    $states = $skips($chain('sel-bare-both', [
        'acme/patches' => ['skip' => ['2026-10-001' => 'breaks checkout']],
    ])->collector()->collect());

    $reached = 0;
    foreach ($states as $label => $reason) {
        if (strpos((string) $label, '2026-10-001') === 0) {
            Assert::contains((string) $reason, 'breaks checkout', 'reached ' . $label);
            $reached++;
        }
    }
    Assert::same(2, $reached, 'both lines, not whichever one came first');

    Assert::case('selection: a bare id naming a depended-on patch is refused');
    // The cascade multiplies a bare id: a base line published later that reuses
    // it would go dark under a reason written about a different Magento
    // release, months after anyone last read the config.
    $collector = $chain('sel-bare-chain', [
        'acme/patches' => ['skip' => ['2026-08-001' => 'breaks checkout']],
    ])->collector();
    $states = $skips($collector->collect());
    Assert::contains(implode("\n", $collector->errors()), 'other patches are built on', 'and says why');
    Assert::contains(implode("\n", $collector->errors()), '2026-08-001@', 'and how to write it instead');
    Assert::same(null, $states['2026-08-001 [2.4.8-p5]'], 'nothing is switched off on a refused rule');

    Assert::case('selection: id@line scopes it');
    $states = $skips($chain('sel-scoped', [
        'acme/patches' => ['skip' => ['2026-08-001@2.4.8-p5' => 'breaks checkout']],
    ])->collector()->collect());
    Assert::contains((string) $states['2026-08-001 [2.4.8-p5]'], 'breaks checkout', 'the named line');
    Assert::same(null, $states['2026-08-001 [2.4.6-p15]'], 'and only that one');

    Assert::case('selection: everything built on a skipped patch goes with it');
    Assert::contains(
        (string) $states['2026-09-001 [2.4.8-p5]'],
        'depends on 2026-08-001 [2.4.8-p5]',
        'the next link, naming its direct prerequisite'
    );
    Assert::contains(
        (string) $states['APSB26-146 StyleSmuggler [2.4.8-p5]'],
        'depends on 2026-08-001 [2.4.8-p5]',
        'including across the emergency/isolated split'
    );
    Assert::same(null, $states['2026-07-001 [2.4.8-p5]'], 'what it was built on is untouched');

    Assert::case('selection: skip is the only rule there is');
    // `only` and `mode` are gone: opt-in is the wrong default for a security
    // package, and requiring one link left its prerequisites skipped so the
    // cascade took away the very patch that had been asked for.
    $collector = $chain('sel-unknown', ['acme/patches' => ['mode' => 'none', 'only' => ['FREE-1']]])->collector();
    $collector->collect();
    Assert::contains(implode("\n", $collector->errors()), 'unknown key "mode"', 'a stale mode is reported');
    Assert::contains(implode("\n", $collector->errors()), 'only "skip" is supported', 'and what is supported');

    Assert::case('selection: typos are errors, not silent no-ops');
    $collector = $chain('sel-typo', [
        'acme/patches' => ['skip' => ['2026-99-001' => 'nope']],
    ])->collector();
    $collector->collect();
    Assert::contains(implode("\n", $collector->errors()), 'no patch matching skip', 'an id that matches nothing');

    $collector = $chain('sel-owner', [
        'acme/nope' => ['skip' => ['FREE-1' => 'nope']],
    ])->collector();
    $collector->collect();
    Assert::contains(implode("\n", $collector->errors()), 'declares no patches here', 'a package that has none');

    Assert::case('depends must name something real, and cannot loop');
    $broken = static function (string $name, array $isolated) use ($project, $meta, $patch): array {
        $p = $project($name)
            ->root($meta(['trust' => ['acme/*']]))
            ->package('acme/patches', $meta(['lines' => ['2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'patches' => $isolated,
            ]]]));
        $p->write('vendor/acme/patches/a.patch', $patch);
        $c = $p->collector();
        $c->collect();

        return $c->errors();
    };

    Assert::contains(
        implode("\n", $broken('dep-missing', [
            ['id' => 'A-1', 'source' => 'a.patch'],
            ['id' => 'A-2', 'source' => 'a.patch', 'depends' => ['A-9']],
        ])),
        'depends on "A-9", which is not declared',
        'a prerequisite that does not exist'
    );

    Assert::contains(
        implode("\n", $broken('dep-cycle', [
            ['id' => 'A-1', 'source' => 'a.patch', 'depends' => ['A-2']],
            ['id' => 'A-2', 'source' => 'a.patch', 'depends' => ['A-1']],
        ])),
        'dependency cycle',
        'a loop'
    );

    Assert::contains(
        implode("\n", $broken('dep-self', [
            ['id' => 'A-1', 'source' => 'a.patch', 'depends' => ['A-1']],
        ])),
        'depends on itself',
        'a patch that depends on itself'
    );

    Assert::case('a forgotten depends cannot happen in a cumulative line');
    // This used to be a guard: two patches with no prerequisite between them
    // was an error, because a monthly chain with a missing edge applies out of
    // order and says nothing. A cumulative line derives its edges from the
    // order given, so there is no longer an edge to leave out.
    $p = $project('no-forgetting')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => ['2.4.8-p5' => [
            'base' => ['magento/magento2-base' => '2.4.8-p5'],
            'cumulative' => true,
            'patches' => [
                ['id' => 'A-1', 'source' => 'a.patch'],
                ['id' => 'A-2', 'source' => 'a.patch'],
            ],
        ]]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $collector->collect();
    Assert::same([], $collector->errors(), 'nothing to complain about');

    $entries = [];
    foreach ($p->patcher()->catalogue() as $entry) {
        $entries[(string) $entry['id']] = $entry;
    }
    Assert::same(['A-1'], $entries['A-2']['depends'], 'and the edge is there regardless');

    Assert::case('include: one base line split across two manifests is still one chain');
    // Splitting one base line across two manifests, by year or by type. Taking
    // position from the array index within each file restarts the chain at zero
    // in the second one, so every position exists twice — and a skip then
    // cascades into the wrong half of the line while leaving the right half live.
    $p = $project('include-chain')
        ->root($meta(['trust' => ['acme/*'], 'sources' => [
            'acme/patches' => ['skip' => ['2025-07-001@2.4.8-p5' => 'breaks checkout']],
        ]]))
        // Listed newest-first on purpose: with declared edges the order of this
        // list carries no meaning at all, which is the point of the change.
        ->package('acme/patches', $meta(['include' => ['2026.json', '2025.json']]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $chainOf = [
        '2025' => [
            ['id' => '2025-07-001', 'source' => 'a.patch'],
            ['id' => '2025-08-001', 'source' => 'a.patch', 'depends' => ['2025-07-001']],
        ],
        '2026' => [
            ['id' => '2026-01-001', 'source' => 'a.patch', 'depends' => ['2025-08-001']],
            ['id' => '2026-02-001', 'source' => 'a.patch', 'depends' => ['2026-01-001']],
        ],
    ];

    foreach ($chainOf as $year => $isolated) {
        $p->write('vendor/acme/patches/' . $year . '.json', (string) json_encode([
            'lines' => ['2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'patches' => $isolated,
            ]],
        ]));
    }

    $order = [];
    $states = [];
    foreach ($p->collector()->collect() as $declaration) {
        $order[] = (string) $declaration['id'];
        $states[(string) $declaration['id']] = $declaration['skipped'];
    }

    Assert::same(
        ['2025-07-001', '2025-08-001', '2026-01-001', '2026-02-001'],
        $order,
        'ordered by what depends on what, not by which file came first'
    );
    Assert::contains((string) $states['2025-07-001'], 'breaks checkout', 'the skip lands');
    Assert::contains((string) $states['2025-08-001'], 'depends on', 'and takes the rest of the line');
    Assert::contains((string) $states['2026-01-001'], 'depends on', 'including the next file');
    Assert::contains((string) $states['2026-02-001'], 'depends on', 'all the way down');

    Assert::case('malformed config is reported, never thrown');
    // Composer turns PHP warnings into exceptions, so an array where a string
    // belongs would abort composer install for every consumer of a trusted
    // package rather than being reported here.
    $p = $project('sel-types')
        ->root($meta(['trust' => ['acme/*'], 'sources' => [
            'acme/patches' => ['skip' => ['A-1' => ['why' => 'x']]],
        ]]))
        ->package('acme/patches', $meta([
            'patches' => [['id' => 'A-1', 'source' => 'a.patch']],
            'include' => [['not-a-string.json']],
        ]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $collector->collect();
    $errors = implode("\n", $collector->errors());
    Assert::contains($errors, 'non-string entry', 'an array in the include list');
    Assert::contains($errors, 'non-string reason', 'an array where a reason belongs');

    Assert::case('an included file that parses but declares nothing is an error');
    // A one-character typo in "lines" would otherwise drop a whole year of
    // patches, exit 0, and print the green banner.
    $p = $project('include-typo')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['include' => ['typo.json', 'list.json']]));
    $p->write('vendor/acme/patches/typo.json', (string) json_encode(['patchs' => []]));
    $p->write('vendor/acme/patches/list.json', (string) json_encode([1, 2, 3]));

    $collector = $p->collector();
    Assert::same([], $collector->collect(), 'nothing collected');
    $errors = implode("\n", $collector->errors());
    Assert::contains($errors, 'typo.json', 'the misspelled key is caught');
    Assert::contains($errors, 'neither "patches" nor "lines"', 'and said plainly');
    Assert::contains($errors, 'list.json', 'so is a JSON list');

    Assert::case('malformed paths are reported, never thrown');
    // realpath() throws ValueError on a null byte and nothing above catches it,
    // so one JSON string in a compromised trusted package would abort composer
    // install for every consumer with a stack trace.
    $p = $project('null-byte')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta([
            'patches' => [
                ['id' => 'NUL', 'source' => "a.patch\0x"],
                ['id' => 'ARR', 'source' => ['a.patch']],
            ],
            'include' => ["m.json\0"],
        ]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $collector->collect();
    $errors = implode("\n", $collector->errors());
    Assert::contains($errors, 'null byte', 'a null byte in a source');
    Assert::contains($errors, 'non-string source', 'an array where a path belongs');

    Assert::case('a FIFO is refused rather than read');
    // It passes realpath() and confinement, and reading it blocks for ever with
    // no output at all — a silent hang in the middle of composer install.
    $fifoDir = $sandbox->mkdir('projects/fifo-pkg');
    $fifo = $fifoDir . '/manifest.json';

    if (function_exists('posix_mkfifo') ? @posix_mkfifo($fifo, 0600) : false) {
        $p = $project('fifo')
            ->root($meta(['trust' => ['acme/*']]))
            ->package('acme/patches', $meta(['include' => ['manifest.json']]));
        @symlink($fifo, $p->root . '/vendor/acme/patches/manifest.json');

        $collector = $p->collector();
        $collector->collect();
        Assert::contains(implode("\n", $collector->errors()), 'not a regular file', 'refused');
    } else {
        Assert::true(true, 'skipped, no posix_mkfifo here');
    }

    Assert::case('two packages do not share each other\'s chains');
    // origin used to restart at 0 for every package while every consumer looked
    // it up in the merged array, so the last package silently owned everyone
    // else's edges — a cascade could reach into a package nobody wrote a rule
    // about and switch its security patches off.
    $twoPacks = static function (string $name, array $sources) use ($project, $meta, $patch): Project {
        $p = $project($name)
            ->root($meta(['trust' => ['*/*'], 'sources' => $sources]))
            ->package('aaa/patches', $meta(['lines' => ['2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'cumulative' => true, 'patches' => [
                    ['id' => 'SHARED-1', 'source' => 'a.patch'],
                    ['id' => 'SHARED-2', 'source' => 'a.patch', 'depends' => ['SHARED-1']],
                ],
            ]]]))
            ->package('zzz/patches', $meta(['lines' => ['2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                // Deliberately the same ids, so labels collide.
                'patches' => [
                    ['id' => 'SHARED-1', 'source' => 'a.patch'],
                    ['id' => 'SHARED-2', 'source' => 'a.patch', 'depends' => ['SHARED-1']],
                ],
            ]]]));
        $p->write('vendor/aaa/patches/a.patch', $patch);
        $p->write('vendor/zzz/patches/a.patch', $patch);

        return $p;
    };

    $states = [];
    foreach ($twoPacks('two-packs', [
        'aaa/patches' => ['skip' => ['SHARED-1@2.4.8-p5' => 'aaa only']],
    ])->collector()->collect() as $declaration) {
        $states[$declaration['owner'] . ' ' . $declaration['id']] = $declaration['skipped'];
    }

    Assert::contains((string) $states['aaa/patches SHARED-1'], 'aaa only', 'the skip lands where it was aimed');
    Assert::contains((string) $states['aaa/patches SHARED-2'], 'depends on', 'and cascades within that package');
    Assert::same(null, $states['zzz/patches SHARED-1'], 'the other package is untouched');
    Assert::same(null, $states['zzz/patches SHARED-2'], 'including everything built on it');

    Assert::case('a chain declared back to front still resolves');
    // The topological sort used to copy its path into every frame, so a chain
    // declared in reverse cost O(n^2) memory — a fatal, which nothing can
    // catch, in every consumer's composer install.
    $long = [];
    for ($i = 400; $i >= 1; $i--) {
        $entry = ['id' => 'L-' . $i, 'source' => 'a.patch'];

        if ($i > 1) {
            $entry['depends'] = ['L-' . ($i - 1)];
        }

        $long[] = $entry;
    }

    $p = $project('deep-chain')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => ['2.4.8-p5' => [
            'base' => ['magento/magento2-base' => '2.4.8-p5'],
            'patches' => $long,
        ]]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $resolved = $collector->collect();
    Assert::same(400, count($resolved), 'all of them survive');
    Assert::same('L-1', (string) $resolved[0]['id'], 'ordered by dependency, not by declaration');
    Assert::same('L-400', (string) $resolved[399]['id'], 'all the way to the end');
    Assert::same([], $collector->errors(), 'and it is not an error to declare them backwards');

    Assert::case('a tangle of cycles is reported once, not once per edge');
    // The message used to carry the whole path and be emitted per back-edge, so
    // the error text alone grew cubically and exhausted memory before printing.
    $tangle = [];
    foreach (range(1, 40) as $i) {
        $tangle[] = [
            'id' => 'C-' . $i,
            'source' => 'a.patch',
            'depends' => array_map(static function (int $j): string {
                return 'C-' . $j;
            }, array_diff(range(1, 40), [$i])),
        ];
    }

    $p = $project('tangle')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => ['2.4.8-p5' => [
            'base' => ['magento/magento2-base' => '2.4.8-p5'],
            'patches' => $tangle,
        ]]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $collector->collect();
    $errors = $collector->errors();
    Assert::true(count($errors) <= 2, 'a handful of errors, not one per back-edge');
    Assert::contains(implode("\n", $errors), 'dependency cycle', 'and it still says what is wrong');
    Assert::true(strlen(implode('', $errors)) < 4096, 'and the text stays a readable size');

    Assert::case('a numeric or list-shaped "lines" key does not throw');
    // Array keys that look like integers arrive as int, and entries() is
    // strict-typed — this was a TypeError out of composer install.
    $p = $project('numeric-line')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => [
            '2024' => ['base' => ['magento/magento2-base' => '2.4.8-p5'],
                       'cumulative' => true, 'patches' => [['id' => 'N-1', 'source' => 'a.patch']]],
        ]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $declarations = $p->collector()->collect();
    Assert::same(1, count($declarations), 'read without throwing');
    Assert::same('2024', (string) $declarations[0]['line'], 'and the line keeps its name');

    Assert::case('selection: only the root package decides');
    // A dependency must not be able to switch off a sibling's patches.
    $p = $project('sel-dependency')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta([
            'patches' => [['id' => 'A-1', 'source' => 'a.patch']],
            'sources' => ['acme/patches' => ['mode' => 'none']],
        ]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $declarations = $p->collector()->collect();
    Assert::same(1, count($declarations), 'the patch is still there');
    Assert::same(null, $declarations[0]['skipped'], 'the dependency did not get to switch it off');

    Assert::case('one package cannot claim a file another package creates');
    // supersede exists because Adobe regenerates vendor/bin/patch-status every
    // month, so month two's new-file hunk replaces month one's — within the
    // package shipping both. Keyed by target alone, any trusted package that
    // sorted later could claim a path another package creates, and the real
    // security patch reported "superseded by a later patch in this line":
    // not covered, exit 0, green banner.
    $p = $project('cross-supersede')
        ->root($meta(['trust' => ['*/*']]))
        ->package('good/patches', $meta(['patches' => [['id' => 'REAL', 'source' => 'good.patch']]]))
        ->package('zz/evil', $meta(['patches' => [['id' => 'EVIL', 'source' => 'evil.patch']]]));
    $p->write('vendor/good/patches/good.patch', "--- /dev/null\n+++ b/fix.php\n@@ -0,0 +1 @@\n+<?php // the real fix\n");
    $p->write('vendor/zz/evil/evil.patch', "--- /dev/null\n+++ b/fix.php\n@@ -0,0 +1 @@\n+<?php // attacker content\n");

    $results = [];
    foreach ($p->patcher()->run(true) as $result) {
        $results[(string) $result['id']] = $result;
    }

    Assert::false(
        $results['REAL']['fragments'][0]->verdict() === Fragment::NOT_APPLICABLE,
        'the real patch is not written off as superseded by a stranger'
    );
    Assert::false(
        Renderer::exitCode($results) === Renderer::OK,
        'and the run does not report a clean store'
    );

    Assert::case('an untrusted package that declares no patches is not an error');
    // Reacting to any key under extra.magento-patches let a package nobody
    // trusts fail every consumer's composer install with one junk key, and the
    // only ways out were trusting it or switching the guarantee off.
    $p = $project('untrusted-noise')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'A-1', 'source' => 'a.patch']]]))
        ->package('noise/analytics', $meta(['note' => 'we use this key too']));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same([], $collector->errors(), 'the noise is ignored');
    Assert::same(1, count($declarations), 'and the real package still applies');

    Assert::case('an untrusted package that really declares patches still is');
    $p = $project('untrusted-real')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('sneaky/patches', $meta(['patches' => [['id' => 'S-1', 'source' => 'a.patch']]]));
    $p->write('vendor/sneaky/patches/a.patch', $patch);

    $collector = $p->collector();
    $collector->collect();
    Assert::same(1, count($collector->errors()), 'silence would read as a patched store');
    Assert::contains($collector->errors()[0], 'is not trusted', 'so it is named');

    Assert::case('a patch is all or nothing across its files');
    // git apply is atomic within one invocation; splitting a patch into
    // per-file fragments throws that away. A security fix on three of its four
    // files is not three quarters protected — Adobe's patches move
    // interdependent code, and a half-applied change fatals the site.
    $twoFile = "--- a/one.txt\n+++ b/one.txt\n@@ -1 +1,2 @@\n one\n+two\n"
        . "--- a/two.txt\n+++ b/two.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n";

    $p = $project('all-or-nothing')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'T-1', 'source' => 'two.patch']]]));
    $p->write('vendor/acme/patches/two.patch', $twoFile);
    $p->write('one.txt', "one\n");
    // Drifted, so the second fragment cannot apply.
    $p->write('two.txt', "something else\n");

    $results = $p->patcher()->run(true);
    Assert::same(
        "one\n",
        (string) file_get_contents($p->root . '/one.txt'),
        'the file that could have been patched was left alone'
    );
    Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'and the run says so');

    // Never written beats written-then-undone: rollback is the safety net for a
    // tree that changes underneath us, not the mechanism. If this assertion is
    // the only thing holding, the pre-flight is not running.
    $reasons = [];
    foreach ($results[0]['fragments'] as $fragment) {
        $reasons[$fragment->target()] = (string) $fragment->reason();
    }
    Assert::false(
        strpos($reasons['one.txt'], 'rolled back') !== false,
        'and it was never written in the first place, not written and undone'
    );

    Assert::case('but a file this store does not have never blocks the rest');
    // The replaced-package case, and the commonest shape on a real store: a
    // patch spans files from several modules and this install does not carry
    // one of them. That is not a refusal, and it must not take the patch down.
    $p = $project('all-or-nothing-absent')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'T-2', 'source' => 'two.patch']]]));
    $p->write('vendor/acme/patches/two.patch', $twoFile);
    $p->write('one.txt', "one\n");
    // two.txt is simply not there — nothing to patch, nothing to complain about.

    $results = $p->patcher()->run(true);
    Assert::same(
        "one\ntwo\n",
        (string) file_get_contents($p->root . '/one.txt'),
        'the file that is here still gets patched'
    );

    $verdicts = [];
    foreach ($results[0]['fragments'] as $fragment) {
        $verdicts[$fragment->target()] = $fragment->verdict();
    }
    Assert::same(Fragment::APPLIED, $verdicts['one.txt'], 'applied');
    Assert::same(Fragment::NOT_APPLICABLE, $verdicts['two.txt'], 'and the absent one is not a failure');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'so the run stays clean');

    // ------------------------------------------------- patches from several sources

    // The case behind all of these: a month a package cannot ship (Adobe keeps
    // it for licence holders) added by the store itself, on top of that
    // package's chain. The root is read first, and ordering each source on its
    // own put the store's month beneath the months it rewrites.
    $order = static function (array $declarations): array {
        return array_map(static function (array $declaration): string {
            return $declaration['owner'] . ' ' . $declaration['id'];
        }, $declarations);
    };
    $keyed = static function (array $declarations): array {
        $out = [];
        foreach ($declarations as $declaration) {
            $out[$declaration['owner'] . ' ' . $declaration['id']] = $declaration;
        }

        return $out;
    };
    $line = static function (array $patches, bool $cumulative = false): array {
        $definition = ['base' => ['magento/magento2-base' => '2.4.8-p5'], 'patches' => $patches];
        if ($cumulative) {
            $definition['cumulative'] = true;
        }

        return ['2.4.8-p5' => $definition];
    };
    $chain = [['id' => 'PKG-1', 'source' => 'a.patch'], ['id' => 'PKG-2', 'source' => 'a.patch']];
    $ten = "1\n2\n3\n4\n5\n6\n7\n8\n9\n10\n";
    $hunk = static function (int $line, string $from, string $to): string {
        return sprintf(
            "--- a/f.txt\n+++ b/f.txt\n@@ -%d,3 +%d,3 @@\n %d\n-%s\n+%s\n %d\n",
            $line - 1,
            $line - 1,
            $line - 1,
            $from,
            $to,
            $line + 1
        );
    };
    $outcome = static function (array $results): array {
        $out = [];
        foreach ($results as $result) {
            $out[$result['owner'] . ' ' . $result['id']] = $result;
        }

        return $out;
    };
    // A base on a package the harness really installs, so a line applies here.
    $here = ['acme/patches' => '*'];

    Assert::case('sources: the store\'s own patch builds on a trusted package\'s chain');
    $p = $project('root-on-package')
        ->root($meta(['trust' => ['acme/*'], 'lines' => $line([
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['PKG-2']],
        ])]))
        ->package('acme/patches', $meta(['lines' => $line($chain, true)]));
    $p->write('own.patch', $patch)->write('vendor/acme/patches/a.patch', $patch);
    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same([], $collector->errors(), 'no complaints');
    Assert::same(
        ['acme/patches PKG-1', 'acme/patches PKG-2', 'project/root OWN-1'],
        $order($declarations),
        'the store\'s patch goes on top, though it was read first'
    );
    $byKey = $keyed($declarations);
    Assert::same(
        [$byKey['acme/patches PKG-2']['origin']],
        $byKey['project/root OWN-1']['depends'],
        'and is built on that package\'s link'
    );

    Assert::case('sources: one package builds on another, whatever order they install in');
    // zzz installs first, so declaration order alone would put it under aaa.
    $p = $project('package-on-package')
        ->root($meta(['trust' => ['*/*'], 'lines' => $line([
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['Z-1']],
        ])]))
        ->package('zzz/extra', $meta(['lines' => $line([
            ['id' => 'Z-1', 'source' => 'a.patch', 'depends' => ['PKG-2']],
        ])]))
        ->package('aaa/patches', $meta(['lines' => $line($chain, true)]));
    $p->write('own.patch', $patch)
        ->write('vendor/zzz/extra/a.patch', $patch)
        ->write('vendor/aaa/patches/a.patch', $patch);
    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same([], $collector->errors(), 'no complaints');
    Assert::same(
        ['aaa/patches PKG-1', 'aaa/patches PKG-2', 'zzz/extra Z-1', 'project/root OWN-1'],
        $order($declarations),
        'three sources, one chain, in dependency order'
    );

    Assert::case('sources: an id two packages declare must be named');
    $twoOwners = static function (string $name, string $needs) use ($project, $meta, $line, $patch): Collector {
        $p = $project($name)
            ->root($meta(['trust' => ['*/*'], 'lines' => $line([
                ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => [$needs]],
            ])]))
            ->package('aaa/patches', $meta(['lines' => $line([['id' => 'SHARED-1', 'source' => 'a.patch']])]))
            ->package('zzz/patches', $meta(['lines' => $line([['id' => 'SHARED-1', 'source' => 'a.patch']])]));
        $p->write('own.patch', $patch)
            ->write('vendor/aaa/patches/a.patch', $patch)
            ->write('vendor/zzz/patches/a.patch', $patch);

        return $p->collector();
    };

    $collector = $twoOwners('ambiguous', 'SHARED-1');
    $byKey = $keyed($collector->collect());
    $errors = implode("\n", $collector->errors());
    Assert::contains($errors, 'aaa/patches, zzz/patches all declare', 'both owners are named');
    Assert::contains($errors, '"aaa/patches:SHARED-1"', 'with the way to name one');
    Assert::same(['SHARED-1'], $byKey['project/root OWN-1']['unresolved'], 'and the patch is left unplaced, not guessed');
    Assert::same([], $byKey['project/root OWN-1']['depends'], 'with no edge to either');

    $collector = $twoOwners('qualified', 'zzz/patches:SHARED-1');
    $byKey = $keyed($collector->collect());
    Assert::same([], $collector->errors(), 'naming the owner settles it');
    Assert::same(
        [$byKey['zzz/patches SHARED-1']['origin']],
        $byKey['project/root OWN-1']['depends'],
        'on the package it named'
    );

    Assert::case('sources: a prerequisite nothing declares is unplaced, not dropped');
    $p = $project('nothing-declares')
        ->root($meta(['trust' => ['acme/*'], 'lines' => $line([
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['PKG-9']],
        ])]))
        // The id exists, on another base line. Lines are never borrowed from.
        ->package('acme/patches', $meta(['lines' => ['2.4.9' => [
            'base' => ['magento/magento2-base' => '2.4.9'],
            'patches' => [['id' => 'PKG-9', 'source' => 'a.patch']],
        ]]]));
    $p->write('own.patch', $patch)->write('vendor/acme/patches/a.patch', $patch);
    $collector = $p->collector();
    $byKey = $keyed($collector->collect());
    Assert::contains(
        implode("\n", $collector->errors()),
        'depends on "PKG-9", which is only declared for 2.4.9',
        'it is a configuration error that says where it is declared'
    );
    Assert::same(['PKG-9'], $byKey['project/root OWN-1']['unresolved'], 'and the patch knows it');

    $p = $project('qualified-to-self')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => $line([
            ['id' => 'P-1', 'source' => 'a.patch', 'depends' => ['acme/patches:GHOST']],
        ])]));
    $p->write('vendor/acme/patches/a.patch', $patch);
    $collector = $p->collector();
    $collector->collect();
    Assert::contains(
        implode("\n", $collector->errors()),
        'depends on "acme/patches:GHOST", which is not declared',
        'naming your own package does not reach into anyone else\'s'
    );

    Assert::case('sources: a cycle across packages is a cycle');
    $p = $project('cross-cycle')
        ->root($meta(['trust' => ['*/*']]))
        ->package('aaa/patches', $meta(['lines' => $line([['id' => 'A-1', 'source' => 'a.patch', 'depends' => ['B-1']]])]))
        ->package('bbb/patches', $meta(['lines' => $line([['id' => 'B-1', 'source' => 'a.patch', 'depends' => ['A-1']]])]));
    $p->write('vendor/aaa/patches/a.patch', $patch)->write('vendor/bbb/patches/a.patch', $patch);
    $collector = $p->collector();
    $collector->collect();
    Assert::contains(implode("\n", $collector->errors()), 'dependency cycle', 'reported');

    Assert::case('sources: skipping a package\'s link takes the store\'s patch built on it');
    $skipping = static function (string $name, array $skip) use ($project, $meta, $line, $patch): Collector {
        $p = $project($name)
            ->root($meta([
                'trust' => ['acme/*'],
                'sources' => ['acme/patches' => ['skip' => $skip]],
                'lines' => $line([['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['P-1']]]),
            ]))
            // Nothing in acme is built on P-1. Only the store's patch is.
            ->package('acme/patches', $meta(['lines' => $line([['id' => 'P-1', 'source' => 'a.patch']])]));
        $p->write('own.patch', $patch)->write('vendor/acme/patches/a.patch', $patch);

        return $p->collector();
    };

    $collector = $skipping('skip-cascades', ['P-1@2.4.8-p5' => 'not wanted']);
    $byKey = $keyed($collector->collect());
    Assert::same([], $collector->errors(), 'no complaints');
    Assert::contains((string) $byKey['project/root OWN-1']['skipped'], 'which this project skips', 'the store\'s patch goes too');

    // Only the package's own dependents make a bare id ambiguous. Counting
    // another source's let any trusted package cancel the store's skip just by
    // depending on the patch, and the skipped patch was then applied.
    $collector = $skipping('skip-bare', ['P-1' => 'not wanted']);
    $byKey = $keyed($collector->collect());
    Assert::same([], $collector->errors(), 'a bare id is fine when only another source builds on it');
    Assert::contains((string) $byKey['project/root OWN-1']['skipped'], 'which this project skips', 'and still cascades');

    Assert::case('sources: a chain across owners is applied, and stays applied');
    // The failure on a real store, in miniature: the package adds a line, the
    // store's patch rewrites it. Without the edge the store's patch went first,
    // the package's then conflicted, and every later run said so.
    $p = $project('cross-owner-stack')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['P-1']],
        ]]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]));
    $p->write('f.txt', "one\n")
        ->write('vendor/acme/patches/p.patch', "--- a/f.txt\n+++ b/f.txt\n@@ -1 +1,2 @@\n one\n+two\n")
        ->write('own.patch', "--- a/f.txt\n+++ b/f.txt\n@@ -1,2 +1,2 @@\n one\n-two\n+three\n");

    $results = $p->patcher()->run(true);
    Assert::same([], $p->collector()->errors(), 'no complaints');
    Assert::same("one\nthree\n", (string) file_get_contents($p->root . '/f.txt'), 'both land, in order');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'and the apply says so');
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'the next verify is clean');

    $written = 0;
    foreach ($p->patcher()->run(true) as $result) {
        foreach ($result['fragments'] as $fragment) {
            $written += $fragment->wasWritten() ? 1 : 0;
        }
    }
    Assert::same(0, $written, 'and a further apply writes nothing');

    Assert::case('sources: a patch with nowhere to go is never written');
    $p = $project('unplaced')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['MISSING']],
            ['id' => 'OWN-2', 'source' => 'own2.patch', 'depends' => ['OWN-1']],
        ]]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]));
    $p->write('f.txt', "one\n")
        ->write('g.txt', "alpha\n")
        ->write('vendor/acme/patches/p.patch', "--- a/f.txt\n+++ b/f.txt\n@@ -1 +1,2 @@\n one\n+two\n")
        ->write('own.patch', "--- a/g.txt\n+++ b/g.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n")
        ->write('own2.patch', "--- a/g.txt\n+++ b/g.txt\n@@ -1,2 +1,3 @@\n alpha\n beta\n+gamma\n");

    $outcomes = [];
    foreach ($p->patcher()->run(true) as $result) {
        $outcomes[(string) $result['id']] = $result;
    }
    Assert::same("alpha\n", (string) file_get_contents($p->root . '/g.txt'), 'nothing is written for it');
    Assert::true(!empty($outcomes['OWN-1']['blocked']), 'it is blocked');
    Assert::contains((string) $outcomes['OWN-1']['reason'], 'MISSING, which nothing declares', 'and says why');
    Assert::true(!empty($outcomes['OWN-2']['blocked']), 'so is what is built on it');
    Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/f.txt'), 'while an unrelated patch still applies');

    $reported = [];
    foreach ($p->patcher()->run(false) as $result) {
        $reported[(string) $result['id']] = $result;
    }
    Assert::true(!empty($reported['OWN-1']['blocked']) && !empty($reported['OWN-2']['blocked']), 'and status says so too, not just apply');

    Assert::case('sources: the catalogue names a prerequisite in another package');
    $p = $project('catalogue-cross')
        ->root($meta(['trust' => ['acme/*'], 'lines' => $line([
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['PKG-2']],
            ['id' => 'OWN-2', 'source' => 'own.patch', 'depends' => ['OWN-1']],
        ])]))
        ->package('acme/patches', $meta(['lines' => $line($chain, true)]));
    $p->write('own.patch', $patch)->write('vendor/acme/patches/a.patch', $patch);
    $entries = [];
    foreach ($p->patcher()->catalogue() as $entry) {
        $entries[$entry['owner'] . ' ' . $entry['id']] = $entry;
    }
    Assert::same(['acme/patches:PKG-2'], $entries['project/root OWN-1']['depends'], 'by owner and id');
    Assert::same(['OWN-1'], $entries['project/root OWN-2']['depends'], 'and one in its own package by id alone');

    // ------------------------------------------------- a missing depends across sources

    Assert::case('sources: the store\'s patch with no order against a package\'s is refused, the package\'s is not');
    // The same mistake as the 2.4.6-p15 store: its own patch rewrites a line the
    // package's adds, and forgets `depends`. Read first, it was applied first.
    $p = $project('missing-depends')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]));
    $p->write('f.txt', $ten)
        ->write('vendor/acme/patches/p.patch', $hunk(2, '2', '2a'))
        ->write('own.patch', $hunk(2, '2a', '2b'));

    $patcher = $p->patcher();
    $results = $outcome($patcher->run(true));
    Assert::same(
        str_replace("2\n", "2a\n", $ten),
        (string) file_get_contents($p->root . '/f.txt'),
        'the package\'s patch applies, and the store\'s is not written over or under it'
    );
    Assert::true(!empty($results['project/root OWN-1']['blocked']), 'the store\'s patch is refused');
    Assert::contains((string) $results['project/root OWN-1']['reason'], 'no order against', 'and says why');
    Assert::contains(implode("\n", $patcher->errors()), 'add "depends": ["acme/patches:P-1"]', 'with the fix spelled out');
    Assert::true(empty($results['acme/patches P-1']['blocked']), 'and the package\'s is never the one refused');

    $results = $outcome($p->patcher()->run(false));
    Assert::true(!empty($results['project/root OWN-1']['blocked']), 'status refuses it too');

    Assert::case('sources: naming the prerequisite settles it');
    $p = $project('depends-given')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['P-1']]]]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]));
    $p->write('f.txt', $ten)
        ->write('vendor/acme/patches/p.patch', $hunk(2, '2', '2a'))
        ->write('own.patch', $hunk(2, '2a', '2b'));
    $patcher = $p->patcher();
    $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no complaints');
    Assert::same(str_replace("2\n", "2b\n", $ten), (string) file_get_contents($p->root . '/f.txt'), 'both land, in order');

    Assert::case('sources: a sibling of the store\'s prerequisite still needs an order');
    // The store builds on P-1; the package later adds P-2, also on P-1, to the
    // same file. Nothing says which of P-2 and the store's patch goes first.
    $p = $project('sibling')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['P-1']]]]))
        ->package('acme/patches', $meta(['patches' => [
            ['id' => 'P-1', 'source' => 'p1.patch'],
            ['id' => 'P-2', 'source' => 'p2.patch', 'depends' => ['P-1']],
        ]]));
    $p->write('f.txt', $ten)
        ->write('vendor/acme/patches/p1.patch', $hunk(2, '2', '2a'))
        ->write('vendor/acme/patches/p2.patch', $hunk(5, '5', '5p'))
        ->write('own.patch', $hunk(9, '9', '9o'));
    $patcher = $p->patcher();
    $results = $outcome($patcher->run(true));
    Assert::contains(implode("\n", $patcher->errors()), '"acme/patches:P-2"', 'the newer link is the one to build on');
    Assert::true(!empty($results['project/root OWN-1']['blocked']), 'and until then the store\'s patch waits');
    Assert::true(empty($results['acme/patches P-2']['blocked']), 'while the package\'s new link applies');

    Assert::case('sources: no shared file, no order needed');
    $p = $project('separate-files')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]));
    $p->write('f.txt', $ten)
        ->write('g.txt', "alpha\n")
        ->write('vendor/acme/patches/p.patch', $hunk(2, '2', '2a'))
        ->write('own.patch', "--- a/g.txt\n+++ b/g.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n");
    $patcher = $p->patcher();
    $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no complaints');
    Assert::same("alpha\nbeta\n", (string) file_get_contents($p->root . '/g.txt'), 'and the store\'s patch applies');

    Assert::case('sources: a skipped package patch is not an order problem');
    $p = $project('skipped-other-side')
        ->root($meta([
            'trust' => ['acme/*'],
            'sources' => ['acme/patches' => ['skip' => ['P-1' => 'the store carries its own']]],
            'patches' => [['id' => 'OWN-1', 'source' => 'own.patch']],
        ]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]));
    $p->write('f.txt', $ten)
        ->write('vendor/acme/patches/p.patch', $hunk(2, '2', '2a'))
        ->write('own.patch', $hunk(2, '2', '2b'));
    $patcher = $p->patcher();
    $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no complaints');
    Assert::same(str_replace("2\n", "2b\n", $ten), (string) file_get_contents($p->root . '/f.txt'), 'the store\'s patch applies');

    Assert::case('sources: two packages sharing a file with no order is a warning, not a refusal');
    // Neither vendor knows about the other, and the store cannot edit their
    // declarations. Refusing would make them impossible to install together.
    $p = $project('package-pair')
        ->root($meta(['trust' => ['*/*']]))
        ->package('aaa/patches', $meta(['patches' => [['id' => 'A-1', 'source' => 'a.patch']]]))
        ->package('zzz/patches', $meta(['patches' => [['id' => 'Z-1', 'source' => 'z.patch']]]));
    $p->write('f.txt', $ten)
        ->write('vendor/aaa/patches/a.patch', $hunk(2, '2', '2a'))
        ->write('vendor/zzz/patches/z.patch', $hunk(9, '9', '9z'));
    $patcher = $p->patcher();
    $results = $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no configuration error');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'both apply');
    Assert::same(
        str_replace(["2\n", "9\n"], ["2a\n", "9z\n"], $ten),
        (string) file_get_contents($p->root . '/f.txt'),
        'and both are on disk'
    );
    $warnings = implode("\n", array_column($patcher->advisories(), 'message'));
    Assert::contains($warnings, 'neither depends on the other', 'but the store is told the order is undefined');

    Assert::case('sources: a package goes on top of the package it requires, without "depends"');
    // A store's private package adding a month the public one cannot ship. It
    // sorts first, so with no order it went first: the month landed on the
    // unpatched file and the public chain it rewrites then conflicted, on
    // every run, blamed on the package that did nothing wrong.
    $addOn = static function (string $name, array $requires) use ($project, $meta, $here, $ten, $hunk): Project {
        $p = $project($name)
            ->root($meta(['trust' => ['*/*']]))
            ->package('aaa/licensed', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
                ['id' => 'X-1', 'source' => 'x.patch'],
            ]]]]), $requires)
            ->package('zzz/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => [
                ['id' => 'PKG-1', 'source' => 'one.patch'],
                ['id' => 'PKG-2', 'source' => 'two.patch'],
            ]]]]))
            ->package('acme/patches', []);
        $p->write('f.txt', $ten)
            ->write('vendor/zzz/patches/one.patch', $hunk(2, '2', '2a'))
            ->write('vendor/zzz/patches/two.patch', $hunk(2, '2a', '2b'))
            ->write('vendor/aaa/licensed/x.patch', $hunk(2, '2b', '2x'));

        return $p;
    };

    $p = $addOn('add-on-on-top', ['zzz/patches']);
    $patcher = $p->patcher();
    Assert::same(
        ['zzz/patches PKG-1', 'zzz/patches PKG-2', 'aaa/licensed X-1'],
        $order($p->collector()->collect()),
        'the package it requires goes first'
    );
    $results = $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no configuration error');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'everything applies');
    Assert::same(str_replace("2\n", "2x\n", $ten), (string) file_get_contents($p->root . '/f.txt'), 'in order');
    Assert::false(
        strpos(implode("\n", array_column($patcher->advisories(), 'message')), 'neither depends on the other') !== false,
        'requiring the package is the order, so there is nothing to warn about'
    );
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'the next verify is clean');
    $written = 0;
    foreach ($p->patcher()->run(true) as $result) {
        foreach ($result['fragments'] as $fragment) {
            $written += $fragment->wasWritten() ? 1 : 0;
        }
    }
    Assert::same(0, $written, 'and a further apply writes nothing');

    $p = $addOn('add-on-unrelated', []);
    $patcher = $p->patcher();
    $patcher->run(false);
    Assert::contains(
        implode("\n", array_column($patcher->advisories(), 'message')),
        'neither depends on the other',
        'control: without the require, the order is still undefined and said so'
    );

    Assert::case('sources: building on an add-on does not pull it beneath what it requires');
    // require is a constraint, not a starting point. The store, or a sibling
    // package, depending on the add-on's patch reached it first, and it went
    // beneath the chain it extends.
    $builtOn = static function (string $name, bool $bySibling) use ($project, $meta, $here, $ten, $hunk): Project {
        $patch = ['id' => 'ON-1', 'source' => 'on.patch', 'depends' => ['aaa/licensed:X-1']];
        $on = "--- a/g.txt\n+++ b/g.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n";
        $p = $project($name)->root($meta(['trust' => ['*/*']]));

        // Read first, with nothing it requires, so it is where the walk starts.
        if ($bySibling) {
            $p->package('ccc/extra', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [$patch]]]]))
                ->write('vendor/ccc/extra/on.patch', $on);
        } else {
            $p->root($meta(['trust' => ['*/*'], 'lines' => ['L1' => ['base' => $here, 'patches' => [$patch]]]]))
                ->write('on.patch', $on);
        }

        return $p->package('aaa/licensed', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
                ['id' => 'X-1', 'source' => 'x.patch'],
            ]]]]), ['zzz/patches'])
            ->package('zzz/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => [
                ['id' => 'PKG-1', 'source' => 'one.patch'],
                ['id' => 'PKG-2', 'source' => 'two.patch'],
            ]]]]))
            ->package('acme/patches', [])
            ->write('f.txt', $ten)
            ->write('g.txt', "alpha\n")
            ->write('vendor/zzz/patches/one.patch', $hunk(2, '2', '2a'))
            ->write('vendor/zzz/patches/two.patch', $hunk(2, '2a', '2b'))
            ->write('vendor/aaa/licensed/x.patch', $hunk(2, '2b', '2x'));
    };
    foreach (['the store' => false, 'a sibling package' => true] as $who => $bySibling) {
        $p = $builtOn('built-on-add-on-' . ($bySibling ? 'sibling' : 'store'), $bySibling);
        $ids = array_map(static function (array $d): string {
            return $d['id'];
        }, $p->collector()->collect());
        Assert::same(['PKG-1', 'PKG-2', 'X-1', 'ON-1'], $ids, $who . ': the add-on stays on top of what it requires');
        $patcher = $p->patcher();
        $results = $patcher->run(true);
        Assert::same(Renderer::OK, Renderer::exitCode($results), $who . ': everything applies');
        Assert::same(str_replace("2\n", "2x\n", $ten), (string) file_get_contents($p->root . '/f.txt'), $who . ': in order');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), $who . ': and the next verify is clean');
    }

    Assert::case('sources: an add-on\'s top-level patch is built on the line it requires');
    // The order came from require, but the edge needed to check it beneath
    // the add-on on later runs only crossed within one line: a green apply,
    // then a conflict blamed on the package underneath, on every run after.
    $p = $addOn('add-on-top-level', ['zzz/patches']);
    $p->package('aaa/licensed', $meta(['patches' => [['id' => 'X-1', 'source' => 'x.patch', 'base' => $here]]]), ['zzz/patches']);
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'it applies');
    $patcher = $p->patcher();
    Assert::same(Renderer::OK, Renderer::exitCode($patcher->run(false)), 'and the next verify is clean');
    Assert::false(
        strpos(implode("\n", array_column($patcher->advisories(), 'message')), 'neither depends on the other') !== false,
        'with nothing to warn about'
    );

    Assert::case('sources: an add-on requiring a fork of the package it builds on');
    // The fork replaces the package by name, which is what Composer installs
    // for the add-on's require.
    $p = $project('add-on-fork')
        ->root($meta(['trust' => ['*/*']]))
        ->package('aaa/licensed', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [['id' => 'X-1', 'source' => 'x.patch']]]]]), ['zzz/patches'])
        ->package('fork/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => [
            ['id' => 'PKG-1', 'source' => 'one.patch'],
            ['id' => 'PKG-2', 'source' => 'two.patch'],
        ]]]]), [], 'library', ['zzz/patches'])
        ->package('acme/patches', []);
    $p->write('f.txt', $ten)
        ->write('vendor/fork/patches/one.patch', $hunk(2, '2', '2a'))
        ->write('vendor/fork/patches/two.patch', $hunk(2, '2a', '2b'))
        ->write('vendor/aaa/licensed/x.patch', $hunk(2, '2b', '2x'));
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'it applies on top of the fork');
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'and stays clean');

    Assert::case('sources: two unrelated packages sharing files get one warning, not one per pair of patches');
    $p = $project('package-pair-many')
        ->root($meta(['trust' => ['*/*']]))
        ->package('aaa/patches', $meta(['patches' => [
            ['id' => 'A-1', 'source' => 'a1.patch'], ['id' => 'A-2', 'source' => 'a2.patch'], ['id' => 'A-3', 'source' => 'a3.patch'],
        ]]))
        ->package('zzz/patches', $meta(['patches' => [
            ['id' => 'Z-1', 'source' => 'z1.patch'], ['id' => 'Z-2', 'source' => 'z2.patch'], ['id' => 'Z-3', 'source' => 'z3.patch'],
        ]]));
    // Three patches each on f.txt, and one each on g.txt and h.txt.
    $other = static function (string $file, string $from, string $to): string {
        return "--- a/$file\n+++ b/$file\n@@ -1 +1 @@\n-$from\n+$to\n";
    };
    $p->write('f.txt', $ten)->write('g.txt', "g\n")->write('h.txt', "h\n");
    foreach ([1, 2, 3] as $n) {
        $p->write("vendor/aaa/patches/a$n.patch", $hunk($n + 1, (string) ($n + 1), ($n + 1) . 'a'))
            ->write("vendor/zzz/patches/z$n.patch", $hunk($n + 6, (string) ($n + 6), ($n + 6) . 'z'));
    }
    $p->write('vendor/aaa/patches/a1.patch', $hunk(2, '2', '2a') . $other('g.txt', 'g', 'ga'))
        ->write('vendor/zzz/patches/z1.patch', $hunk(7, '7', '7z') . $other('h.txt', 'h', 'hz'))
        ->write('vendor/aaa/patches/a2.patch', $hunk(3, '3', '3a') . $other('h.txt', 'h', 'ha'))
        ->write('vendor/zzz/patches/z2.patch', $hunk(8, '8', '8z') . $other('g.txt', 'g', 'gz'));
    $patcher = $p->patcher();
    $patcher->run(false);
    $warnings = array_values(array_filter(array_column($patcher->advisories(), 'message'), static function (string $m): bool {
        return strpos($m, 'neither depends on the other') !== false;
    }));
    Assert::same(1, count($warnings), 'one warning for the pair of packages');
    Assert::contains($warnings[0], '2 more files', 'which counts the other files they share');

    Assert::case('sources: another version\'s patch is not blocked here, whatever its depends');
    $p = $project('other-version-unplaced')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => [
            '2.4.9' => ['base' => ['magento/magento2-base' => '2.4.9'], 'patches' => [
                ['id' => 'P-1', 'source' => 'p.patch', 'depends' => ['GHOST']],
            ]],
        ]]));
    $p->write('vendor/acme/patches/p.patch', $patch);
    $patcher = $p->patcher();
    $results = $patcher->run(false);
    Assert::true(empty($results[0]['blocked']), 'status does not count it as blocked');
    Assert::contains((string) $results[0]['reason'], 'is not installed', 'it is another version\'s, as list says');
    Assert::true($patcher->errors() !== [], 'the bad depends is still a configuration error');

    Assert::case('sources: a store\'s cumulative line is told how to build on a package');
    // depends cannot be declared in a cumulative line, so advice to add one
    // there could not be followed.
    $p = $project('cumulative-root')
        ->root($meta(['trust' => ['acme/*'], 'lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => [
            ['id' => 'OWN-1', 'source' => 'own.patch'],
        ]]]]))
        ->package('acme/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => [
            ['id' => 'P-1', 'source' => 'p.patch'],
        ]]]]));
    $p->write('f.txt', $ten)
        ->write('vendor/acme/patches/p.patch', $hunk(2, '2', '2a'))
        ->write('own.patch', $hunk(2, '2a', '2b'));
    $patcher = $p->patcher();
    $patcher->run(false);
    Assert::contains(implode("\n", $patcher->errors()), 'a line without "cumulative"', 'the advice can be followed');

    Assert::case('sources: a depends against require gets one verdict whatever the install order');
    // vend/addon requires vend/base, and base's patch depends on the add-on's.
    // Which was read first decided between a cycle and A-2 quietly beneath base.
    $against = static function (string $name, bool $addonFirst, bool $contradicts) use ($project, $meta, $here, $ten, $hunk): array {
        $p = $project($name)->root($meta(['trust' => ['*/*']]));
        $b = ['id' => 'B-1', 'source' => 'b1.patch'];
        if ($contradicts) {
            $b['depends'] = ['vend/addon:A-2'];
        }
        $packages = [
            'vend/addon' => [$meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
                ['id' => 'A-1', 'source' => 'a1.patch'],
                ['id' => 'A-2', 'source' => 'a2.patch'],
            ]]]]), ['vend/base']],
            'vend/base' => [$meta(['lines' => ['L1' => ['base' => $here, 'patches' => [$b]]]]), []],
        ];
        foreach ($addonFirst ? $packages : array_reverse($packages, true) as $package => [$extra, $requires]) {
            $p->package($package, $extra, $requires);
        }
        $p->package('acme/patches', [])
            ->write('f.txt', $ten)->write('g.txt', $ten)
            ->write('vendor/vend/addon/a1.patch', $hunk(2, '2', '2a'))
            ->write('vendor/vend/addon/a2.patch', $hunk(5, '5', '5a'))
            ->write('vendor/vend/base/b1.patch', $hunk(8, '8', '8b'));
        $patcher = $p->patcher();
        $results = $patcher->run(false);
        $blocked = [];
        foreach ($results as $result) {
            if (!empty($result['blocked'])) {
                $blocked[] = $result['id'];
            }
        }
        sort($blocked);

        return [$patcher->errors() === [] ? Renderer::exitCode($results) : Renderer::CONFIG_ERROR, $blocked];
    };
    Assert::same($against('against-base-first', false, true), $against('against-addon-first', true, true), 'both install orders agree');
    Assert::same(Renderer::CONFIG_ERROR, $against('against-addon-first-2', true, true)[0], 'and it is a configuration error');
    Assert::same($against('against-control-a', false, false), $against('against-control-b', true, false), 'control: without it, both orders agree too');

    Assert::case('sources: list agrees with status about a skipped patch on a contested file');
    $p = $project('skipped-contested-list')
        ->root($meta(['trust' => ['acme/*'], 'sources' => ['acme/patches' => ['skip' => ['P-1' => 'not wanted']]]]))
        ->package('aaa/analytics', ['map' => [['decoy', 'nginx.conf.sample']]], [], 'magento2-component')
        ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch', 'base' => $here]]]))
        ->write('nginx.conf.sample', $ten)
        ->write('vendor/magento/magento2-base/nginx.conf.sample', $ten)
        ->write('vendor/aaa/analytics/decoy', $ten)
        ->write('vendor/acme/patches/p.patch', "--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n");
    $list = $p->patcher();
    $list->catalogue();
    $status = $p->patcher();
    $status->run(false);
    Assert::same($status->errors(), $list->errors(), 'neither calls a patch the store skips a configuration error');

    Assert::case('sources: package warnings are counted per pair, whichever comes first on each file');
    $p = $project('pair-count')
        ->root($meta(['trust' => ['*/*']]))
        ->package('zzz/patches', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
            ['id' => 'Z-X', 'source' => 'zx.patch', 'depends' => ['aaa/patches:A-F']],
            ['id' => 'Z-F', 'source' => 'zf.patch'],
            ['id' => 'Z-G', 'source' => 'zg.patch'],
            ['id' => 'Z-H', 'source' => 'zh.patch'],
        ]]]]))
        ->package('aaa/patches', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
            ['id' => 'A-F', 'source' => 'af.patch'],
            ['id' => 'A-G', 'source' => 'ag.patch'],
        ]]]]))
        ->package('acme/patches', [])
        ->write('f.txt', $ten)->write('g.txt', $ten)->write('h.txt', $ten)->write('x.txt', $ten)
        ->write('vendor/zzz/patches/zx.patch', $hunk(5, '5', '5z'))
        ->write('vendor/zzz/patches/zf.patch', str_replace('f.txt', 'x.txt', $hunk(8, '8', '8z')))
        ->write('vendor/zzz/patches/zg.patch', str_replace('f.txt', 'g.txt', $hunk(8, '8', '8z')))
        ->write('vendor/zzz/patches/zh.patch', str_replace('f.txt', 'h.txt', $hunk(8, '8', '8z')))
        ->write('vendor/aaa/patches/af.patch', str_replace('f.txt', 'x.txt', $hunk(2, '2', '2a')))
        ->write('vendor/aaa/patches/ag.patch', str_replace('f.txt', 'g.txt', $hunk(2, '2', '2a')) . str_replace('f.txt', 'h.txt', $hunk(2, '2', '2a')));
    $patcher = $p->patcher();
    $patcher->run(false);
    $warnings = array_values(array_filter(array_column($patcher->advisories(), 'message'), static function (string $m): bool {
        return strpos($m, 'neither depends on the other') !== false;
    }));
    Assert::same(1, count($warnings), 'one warning for the pair of packages');
    Assert::contains($warnings[0] ?? '', '2 more files', 'counting every file they share with no order: g.txt and h.txt beside x.txt');

    Assert::case('sources: packages that require each other are not told to require the other');
    $p = $project('mutual-requires')
        ->root($meta(['trust' => ['*/*']]))
        ->package('aaa/addon', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [['id' => 'X-1', 'source' => 'x.patch']]]]]), ['zzz/base'])
        ->package('zzz/base', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [['id' => 'B-1', 'source' => 'b.patch']]]]]), ['aaa/addon'])
        ->package('acme/patches', [])
        ->write('f.txt', $ten)
        ->write('vendor/zzz/base/b.patch', $hunk(2, '2', '2b'))
        ->write('vendor/aaa/addon/x.patch', $hunk(8, '8', '8x'));
    $patcher = $p->patcher();
    $patcher->run(false);
    $warnings = implode("\n", array_column($patcher->advisories(), 'message'));
    Assert::contains($warnings, 'require each other', 'the warning says why require settles nothing here');
    Assert::false(strpos($warnings, 'should require the other') !== false, 'rather than advice they already follow');

    Assert::case('sources: the store\'s patch against a chain is one problem, naming the link that settles it');
    $links = [];
    foreach ([1, 2, 3, 4, 5] as $i) {
        $links[] = ['id' => 'PKG-' . $i, 'source' => 'p' . $i . '.patch'];
    }
    $againstChain = static function (string $name, array $root) use ($project, $meta, $here, $ten, $hunk, $links): Patcher {
        $p = $project($name)
            ->root($meta(array_merge(['trust' => ['acme/*']], $root)))
            ->package('acme/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => $links]]]))
            ->write('f.txt', $ten)->write('own.patch', $hunk(9, '9', '9o'));
        $current = '2';
        foreach ([1, 2, 3, 4, 5] as $i) {
            $p->write('vendor/acme/patches/p' . $i . '.patch', $hunk(2, $current, $current . 'x'));
            $current .= 'x';
        }
        $patcher = $p->patcher();
        $patcher->run(false);

        return $patcher;
    };
    $patcher = $againstChain('chain-same-line', ['lines' => ['L1' => ['base' => $here, 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]]]);
    $errors = array_values(array_filter($patcher->errors(), static function (string $e): bool {
        return strpos($e, 'neither depends on the other') !== false;
    }));
    Assert::same(1, count($errors), 'one error, not one per link');
    Assert::contains($errors[0] ?? '', '"depends": ["acme/patches:PKG-5"]', 'naming the link that settles it');
    $patcher = $againstChain('chain-cross-line', ['patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'base' => $here]]]);
    $warnings = array_values(array_filter(array_column($patcher->advisories(), 'message'), static function (string $m): bool {
        return strpos($m, 'nothing orders them') !== false;
    }));
    Assert::same(1, count($warnings), 'and one warning across lines, not one per link');

    Assert::case('sources: a store\'s top-level patch against a package\'s line is a warning, not a refusal');
    // Top-level `patches` is where a store puts a fix gated on a version range.
    // `depends` cannot reach from there into a line, so refusing it would ask for
    // a fix that does not exist, and break a store that works today.
    $p = $project('top-level-vs-line')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
        ->package('acme/patches', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
            ['id' => 'P-1', 'source' => 'p.patch'],
        ]]]]));
    $p->write('f.txt', $ten)
        ->write('vendor/acme/patches/p.patch', $hunk(2, '2', '2a'))
        ->write('own.patch', $hunk(9, '9', '9o'));
    $patcher = $p->patcher();
    $results = $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no configuration error');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'both apply');
    Assert::contains(
        implode("\n", array_column($patcher->advisories(), 'message')),
        'declare yours in the "L1" line',
        'and the store is told how it could order them'
    );

    $p = $project('top-level-depends-into-line')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['P-1']]]]))
        ->package('acme/patches', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
            ['id' => 'P-1', 'source' => 'p.patch'],
        ]]]]));
    $p->write('vendor/acme/patches/p.patch', $patch)->write('own.patch', $patch);
    $collector = $p->collector();
    $collector->collect();
    Assert::contains(
        implode("\n", $collector->errors()),
        'only declared for L1 — a patch can only build on one in its own base line',
        'depending across lines says where to declare it, rather than that it does not exist'
    );

    Assert::case('sources: a third package shipping the same id does not strand an add-on');
    // zzz builds on aaa by bare id. Installing bbb, which also ships SHARED-1,
    // must not refuse zzz's patch: the store has no way to fix that.
    $addOn = static function (string $name, bool $requiresAaa) use ($project, $meta, $here, $patch): Project {
        $p = $project($name)
            ->root($meta(['trust' => ['*/*']]))
            ->package('aaa/patches', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [['id' => 'SHARED-1', 'source' => 'p.patch']]]]]))
            ->package('bbb/also', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [['id' => 'SHARED-1', 'source' => 'p.patch']]]]]))
            ->package('zzz/extra', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
                ['id' => 'X-1', 'source' => 'x.patch', 'depends' => ['SHARED-1']],
            ]]]]), $requiresAaa ? ['aaa/patches'] : [])
            ->package('acme/patches', []);
        foreach (['aaa/patches', 'bbb/also'] as $owner) {
            $p->write('vendor/' . $owner . '/p.patch', $patch);
        }
        $p->write('vendor/zzz/extra/x.patch', $patch);

        return $p;
    };

    $collector = $addOn('add-on-requires', true)->collector();
    $byKey = $keyed($collector->collect());
    Assert::same([], $collector->errors(), 'an add-on that requires what it builds on is unambiguous');
    Assert::same(
        [$byKey['aaa/patches SHARED-1']['origin']],
        $byKey['zzz/extra X-1']['depends'],
        'and it builds on the package it requires'
    );

    $collector = $addOn('add-on-does-not-require', false)->collector();
    $byKey = $keyed($collector->collect());
    $errors = implode("\n", $collector->errors());
    Assert::contains($errors, 'zzz/extra should require the package it builds on', 'otherwise its author is told, not the store');
    Assert::false(strpos($errors, 'name one, e.g.') !== false, 'and the store is not told to edit what it cannot');
    Assert::contains((string) ($byKey['zzz/extra X-1']['unresolvedWhy'] ?? ''), 'aaa/patches, bbb/also all declare', 'the reason is the real one');

    Assert::case('sources: one owner declaring an id twice is not "two owners"');
    $p = $project('declared-twice')
        ->root($meta(['trust' => ['aaa/*'], 'lines' => ['L1' => ['base' => $here, 'patches' => [
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['aaa/patches:X']],
        ]]]]))
        ->package('aaa/patches', $meta(['lines' => ['L1' => ['base' => $here, 'patches' => [
            ['id' => 'X', 'source' => 'p.patch'],
            ['id' => 'X', 'source' => 'p.patch'],
        ]]]]))
        ->package('acme/patches', []);
    $p->write('vendor/aaa/patches/p.patch', $patch)->write('own.patch', $patch);
    $collector = $p->collector();
    $collector->collect();
    $errors = implode("\n", $collector->errors());
    Assert::contains($errors, '"X" is declared twice', 'the real problem is reported');
    Assert::false(strpos($errors, 'aaa/patches, aaa/patches') !== false, 'not a contradiction about who declares it');

    Assert::case('sources: a cycle across sources writes nothing');
    $p = $project('cross-cycle-writes')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['P-1']]]]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch', 'depends' => ['OWN-1']]]]));
    $p->write('f.txt', $ten)
        ->write('vendor/acme/patches/p.patch', $hunk(2, '2', '2a'))
        ->write('own.patch', $hunk(9, '9', '9o'));
    $patcher = $p->patcher();
    $results = $outcome($patcher->run(true));
    Assert::contains(implode("\n", $patcher->errors()), 'dependency cycle', 'reported');
    Assert::same($ten, (string) file_get_contents($p->root . '/f.txt'), 'and neither member is written');
    Assert::true(
        !empty($results['project/root OWN-1']['blocked']) && !empty($results['acme/patches P-1']['blocked']),
        'both are refused'
    );

    Assert::case('sources: list and status agree about what a refused patch takes with it');
    $p = $project('list-agrees')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['MISSING']],
            ['id' => 'OWN-2', 'source' => 'own2.patch', 'depends' => ['OWN-1']],
        ]]))
        ->package('acme/patches', []);
    $p->write('g.txt', "alpha\n")
        ->write('own.patch', "--- a/g.txt\n+++ b/g.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n")
        ->write('own2.patch', "--- a/g.txt\n+++ b/g.txt\n@@ -1,2 +1,3 @@\n alpha\n beta\n+gamma\n");
    $entries = [];
    foreach ($p->patcher()->catalogue() as $entry) {
        $entries[(string) $entry['id']] = $entry;
    }
    Assert::false($entries['OWN-2']['applicable'], 'the catalogue does not say the dependent applies');
    Assert::contains((string) $entries['OWN-2']['reason'], 'requires OWN-1', 'and says why, as status does');

    Assert::case('sources: the root and magento2-base copies of a file are one file to the order check');
    // A package's patch aimed at magento2-base's copy and the store's aimed at
    // the project root's are the same file once deployed, so the same question.
    $p = $project('mirror-order')
        ->root($meta(['trust' => ['acme/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'base' => $here]]]))
        ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch', 'base' => $here]]]));
    $p->write('nginx.conf.sample', $ten)
        ->write('vendor/magento/magento2-base/nginx.conf.sample', $ten)
        ->write(
            'vendor/acme/patches/p.patch',
            "--- a/vendor/magento/magento2-base/nginx.conf.sample\n+++ b/vendor/magento/magento2-base/nginx.conf.sample\n"
            . "@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n"
        )
        ->write('own.patch', "--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n@@ -8,3 +8,3 @@\n 8\n-9\n+9o\n 10\n");
    $patcher = $p->patcher();
    $results = $outcome($patcher->run(false));
    Assert::contains(implode("\n", $patcher->errors()), 'add "depends": ["acme/patches:P-1"]', 'the overlap is found across the copies');
    Assert::true(!empty($results['project/root OWN-1']['blocked']), 'and the store\'s patch waits for an order');

    Assert::case('sources: the order check stays linear on a long chain');
    // It used to hold every patch's whole ancestry, and magento-patches:list ran
    // out of memory on an 8,000-link chain.
    // The memory measure needs PHP 8.2; the rest of it runs everywhere.
    $measure = function_exists('memory_reset_peak_usage');
    $long = [];
    for ($i = 1; $i <= 4000; $i++) {
        $long[] = ['id' => 'L-' . $i, 'source' => 'a.patch'];
    }
    $p = $project('long-chain')
        ->root($meta(['trust' => ['acme/*'], 'lines' => ['L1' => ['base' => $here, 'patches' => [
            ['id' => 'OWN-1', 'source' => 'own.patch', 'depends' => ['L-4000']],
        ]]]]))
        ->package('acme/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => $long]]]));
    $p->write('vendor/acme/patches/a.patch', $patch)->write('own.patch', $patch);
    $patcher = $p->patcher();
    if ($measure) {
        memory_reset_peak_usage();
    }
    $before = memory_get_usage();
    $patcher->catalogue();
    if ($measure) {
        Assert::true(memory_get_peak_usage() - $before < 96 * 1024 * 1024, 'the catalogue of a 4,000-link chain stays under 96 MB');
    }
    Assert::same([], $patcher->errors(), 'and finds nothing wrong with it');

    Assert::case('sources: two long unrelated chains on one file stay cheap to check');
    // One warning per pair of patches was a million of them at 1,000 links
    // each, and a fatal at 2,000.
    if (function_exists('memory_reset_peak_usage')) {
        $chain = static function (string $prefix): array {
            $links = [];
            for ($i = 1; $i <= 2000; $i++) {
                $links[] = ['id' => $prefix . $i, 'source' => 'a.patch'];
            }

            return $links;
        };
        $p = $project('long-unrelated')
            ->root($meta(['trust' => ['*/*']]))
            ->package('aaa/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => $chain('A-')]]]))
            ->package('zzz/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => $chain('Z-')]]]))
            ->package('acme/patches', []);
        $p->write('vendor/aaa/patches/a.patch', $patch)->write('vendor/zzz/patches/a.patch', $patch);
        $patcher = $p->patcher();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = microtime(true);
        $warnings = array_filter(array_column($patcher->advisories(), 'message'), static function (string $m): bool {
            return strpos($m, 'neither depends on the other') !== false;
        });
        Assert::same(1, count($warnings), 'one warning for the two packages');
        Assert::true(memory_get_peak_usage() - $before < 96 * 1024 * 1024, 'checking 2,000 links against 2,000 stays under 96 MB');
        Assert::true(microtime(true) - $started < 30, 'and finishes well inside a Composer run');

        // The same, with one built on the other by require: an edge from every
        // link to every link was quadratic.
        $p = $project('long-required')
            ->root($meta(['trust' => ['*/*']]))
            ->package('aaa/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => $chain('A-')]]]), ['zzz/patches'])
            ->package('zzz/patches', $meta(['lines' => ['L1' => ['base' => $here, 'cumulative' => true, 'patches' => $chain('Z-')]]]))
            ->package('acme/patches', []);
        $p->write('vendor/aaa/patches/a.patch', $patch)->write('vendor/zzz/patches/a.patch', $patch);
        $patcher = $p->patcher();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = microtime(true);
        $patcher->catalogue();
        Assert::same([], array_filter(array_column($patcher->advisories(), 'message'), static function (string $m): bool {
            return strpos($m, 'neither depends on the other') !== false;
        }), 'requiring the other is the order, at any length');
        Assert::true(memory_get_peak_usage() - $before < 96 * 1024 * 1024, 'and checking it stays under 96 MB');
        Assert::true(microtime(true) - $started < 30, 'and quick');
    }

},

'plugin' => static function (Sandbox $sandbox): void {
    $p = (new Project($sandbox, 'plugin'))
        ->root(['magento-patches' => ['patches' => [['id' => 'A-1', 'source' => 'a.patch']]]])
        ->write('a.patch', "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1,2 @@\n one\n+two\n")
        ->write('a.txt', "one\n");

    $event = static function (string $name): CommandEvent {
        return new CommandEvent(PluginEvents::COMMAND, $name, new ArrayInput([]), new NullOutput());
    };

    $reported = static function (?string $command) use ($p, $event): bool {
        $io = new BufferIO();
        $plugin = $p->plugin($io);

        if ($command !== null) {
            $plugin->onCommand($event($command));
        }

        $plugin->onAutoloadDump();

        return strpos($io->getOutput(), 'Magento security patches') !== false;
    };

    Assert::case('the autoload dump heals when dump-autoload is the command');
    Assert::true($reported('dump-autoload'), 'runs on dump-autoload');

    Assert::case('the autoload dump inside other commands waits for their own event');
    Assert::false($reported('install'), 'not inside install');
    Assert::false($reported('reinstall'), 'not inside reinstall');

    Assert::case('a plugin installed partway through a run waits too');
    Assert::false($reported(null), 'no command heard, no run');
},

'mirror' => static function (Sandbox $sandbox): void {
    $meta = static function (array $extra): array {
        return ['magento-patches' => $extra];
    };

    $mirror = 'vendor/magento/magento2-base/nginx.conf.sample';

    /**
     * A store whose served nginx.conf.sample and magento2-base's copy have
     * diverged, so the mirror is patched on its own terms rather than copied.
     *
     * @param string[] $lines
     */
    $store = static function (string $name, array $lines, string $patch) use ($sandbox, $meta, $mirror): Project {
        $p = (new Project($sandbox, $name))
            ->root($meta(['trust' => ['acme/*']]))
            ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
            ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]));

        return $p->write('nginx.conf.sample', implode("\n", $lines) . "\n")
            ->write($mirror, implode("\n", $lines) . "\n# customised\n")
            ->write('vendor/acme/patches/p.patch', $patch);
    };

    $header = "diff --git a/nginx.conf.sample b/nginx.conf.sample\n--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n";

    Assert::case('an added line that reads like a header reaches the mirror as written');
    // The bug the review found: retarget() rewrote every occurrence of the
    // header text, so the served copy and the package copy got different code
    // and verify, asking each copy about its own rewritten diff, said both
    // were patched.
    $p = $store('mirror-added', ['a', 'b', 'c'], $header . "@@ -1,3 +1,4 @@\n a\n b\n+++ b/nginx.conf.sample\n c\n");
    $results = $p->patcher()->run(true);
    Assert::same("a\nb\n++ b/nginx.conf.sample\nc\n", (string) file_get_contents($p->root . '/nginx.conf.sample'), 'the served copy');
    Assert::same(
        "a\nb\n++ b/nginx.conf.sample\nc\n# customised\n",
        (string) file_get_contents($p->root . '/' . $mirror),
        'the package copy gets the same line, and keeps its customisation'
    );
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'the apply is clean');
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'and so is verify');

    Assert::case('removed and context lines that read like headers still match the mirror');
    $p = $store(
        'mirror-removed',
        ['a', '# diff --git a/nginx.conf.sample b/nginx.conf.sample', '-- a/nginx.conf.sample', 'd', 'e'],
        $header . "@@ -1,4 +1,4 @@\n a\n # diff --git a/nginx.conf.sample b/nginx.conf.sample\n--- a/nginx.conf.sample\n+ x\n d\n"
    );
    $results = $p->patcher()->run(true);
    Assert::same(
        "a\n# diff --git a/nginx.conf.sample b/nginx.conf.sample\n x\nd\ne\n# customised\n",
        (string) file_get_contents($p->root . '/' . $mirror),
        'the package copy is patched'
    );
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'not refused as diverged');

    Assert::case('a quoted path is patched in both copies');
    // git quotes a path with non-ASCII in it, and git apply is handed the
    // quoted header as written for the served copy and a retargeted one for
    // the package copy.
    $p = (new Project($sandbox, 'mirror-quoted'))
        ->root($meta(['trust' => ['acme/*']]))
        ->package('magento/magento2-base', ['map' => [["caf\u{e9}.conf", "caf\u{e9}.conf"]]], [], 'magento2-component')
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]))
        ->write("caf\u{e9}.conf", "a\nb\n")
        ->write("vendor/magento/magento2-base/caf\u{e9}.conf", "a\nb\n# customised\n")
        ->write(
            'vendor/acme/patches/p.patch',
            "diff --git \"a/caf\\303\\251.conf\" \"b/caf\\303\\251.conf\"\n"
            . "--- \"a/caf\\303\\251.conf\"\n+++ \"b/caf\\303\\251.conf\"\n@@ -1,2 +1,3 @@\n a\n+x\n b\n"
        );
    $results = $p->patcher()->run(true);
    Assert::same("a\nx\nb\n", (string) file_get_contents($p->root . "/caf\u{e9}.conf"), 'the served copy');
    Assert::same("a\nx\nb\n# customised\n", (string) file_get_contents($p->root . "/vendor/magento/magento2-base/caf\u{e9}.conf"), 'the package copy');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'cleanly');
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'and verify agrees');

    Assert::case('a mirror whose customisation is in the way is still refused');
    // The control: a copy that really has diverged under the hunk.
    $p = (new Project($sandbox, 'mirror-diverged'))
        ->root($meta(['trust' => ['acme/*']]))
        ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]))
        ->write('nginx.conf.sample', "a\nb\nc\n")
        ->write($mirror, "a\nB\nc\n")
        ->write('vendor/acme/patches/p.patch', $header . "@@ -1,3 +1,4 @@\n a\n b\n+x\n c\n");
    $results = $p->patcher()->run(true);
    Assert::same("a\nB\nc\n", (string) file_get_contents($p->root . '/' . $mirror), 'the customised copy is left alone');
    Assert::true(Renderer::exitCode($results) !== Renderer::OK, 'and the run says so');

    Assert::case('copies in step are still copied across');
    $p = (new Project($sandbox, 'mirror-lockstep'))
        ->root($meta(['trust' => ['acme/*']]))
        ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
        ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]))
        ->write('nginx.conf.sample', "a\nb\nc\n")
        ->write($mirror, "a\nb\nc\n")
        ->write('vendor/acme/patches/p.patch', $header . "@@ -1,3 +1,4 @@\n a\n b\n+++ b/nginx.conf.sample\n c\n");
    $results = $p->patcher()->run(true);
    Assert::same((string) file_get_contents($p->root . '/nginx.conf.sample'), (string) file_get_contents($p->root . '/' . $mirror), 'both copies match');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'cleanly');

    Assert::case('retarget rewrites the headers and nothing else');
    $applier = new Applier(new ProcessExecutor(), $sandbox->root());
    $body = "@@ -1,4 +1,4 @@\n diff --git a/x.txt b/x.txt\n--- a/x.txt\n+++ b/x.txt\n keep\n";
    Assert::same(
        "diff --git a/y.txt b/y.txt\n--- a/y.txt\n+++ b/y.txt\n" . $body,
        $applier->retarget("diff --git a/x.txt b/x.txt\n--- a/x.txt\n+++ b/x.txt\n" . $body, 'x.txt', 'y.txt'),
        'header-like body lines are untouched'
    );
    Assert::same(
        "diff --git a/y.txt b/y.txt\r\n--- a/y.txt\t2026-10-14 12:00:00\r\n+++ b/y.txt\t2026-10-14 12:00:00\r\n@@ -1 +1 @@\r\n-a\r\n+b\r\n",
        $applier->retarget(
            "diff --git a/x.txt b/x.txt\r\n--- a/x.txt\t2026-10-14 12:00:00\r\n+++ b/x.txt\t2026-10-14 12:00:00\r\n@@ -1 +1 @@\r\n-a\r\n+b\r\n",
            'x.txt',
            'y.txt'
        ),
        'line endings and timestamps survive'
    );
    Assert::same(
        "diff --git a/y.txt b/y.txt\nold mode 100644\nnew mode 100755\n",
        $applier->retarget("diff --git \"a/x.txt\" \"b/x.txt\"\nold mode 100644\nnew mode 100755\n", 'x.txt', 'y.txt'),
        'a quoted diff --git line, which is all a mode change has'
    );
    Assert::same(
        "--- a/y.txt\n+++ b/y.txt\n@@ -1 +1 @@\n-a\n+b\n",
        $applier->retarget("--- \"a/x.txt\"\n+++ \"b/x.txt\"\n@@ -1 +1 @@\n-a\n+b\n", 'x.txt', 'y.txt'),
        'and quoted header pairs'
    );
    Assert::same(
        "diff --git a/x.txt.dist b/x.txt.dist\n--- a/x.txt.dist\n+++ b/x.txt.dist\n@@ -1 +1 @@\n-a\n+b\n",
        $applier->retarget("diff --git a/x.txt.dist b/x.txt.dist\n--- a/x.txt.dist\n+++ b/x.txt.dist\n@@ -1 +1 @@\n-a\n+b\n", 'x.txt', 'y.txt'),
        'a longer path that starts with the same name is not this file'
    );
    Assert::same(
        "diff --git a/y.txt b/y.txt\nnew file mode 100644\n--- /dev/null\n+++ b/y.txt\n@@ -0,0 +1 @@\n+a\n",
        $applier->retarget("diff --git a/x.txt b/x.txt\nnew file mode 100644\n--- /dev/null\n+++ b/x.txt\n@@ -0,0 +1 @@\n+a\n", 'x.txt', 'y.txt'),
        '/dev/null is left as it is'
    );
},

'require-against' => static function (Sandbox $sandbox): void {
    $meta = static function (array $extra): array {
        return ['magento-patches' => $extra];
    };
    $patch = "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1,2 @@\n one\n+two\n";
    $patch2 = "--- a/b.txt\n+++ b/b.txt\n@@ -1 +1,2 @@\n one\n+two\n";
    Assert::case('a library the security package requires declares depends on its patch');
    foreach (['control' => [], 'lib depends on base' => ['vendor/base:SEC-1']] as $how => $depends) {
        $p = (new Project($sandbox, 'contra-' . md5($how)))
            ->root($meta(['trust' => ['vendor/*']]))
            ->package('vendor/base', $meta(['patches' => [['id' => 'SEC-1', 'source' => 'a.patch', 'base' => ['vendor/base' => '*']]]]), ['vendor/lib'])
            ->package('vendor/lib', $meta(['patches' => [['id' => 'LIB-1', 'source' => 'b.patch', 'base' => ['vendor/base' => '*'], 'depends' => $depends]]]))
            ->write('vendor/vendor/base/a.patch', $patch)->write('vendor/vendor/lib/b.patch', $patch2)
            ->write('a.txt', "one\n")->write('b.txt', "one\n");
        $patcher = $p->patcher();
        $r = $patcher->run(true);
        $sec = null;
        foreach ($r as $x) { if ($x['id'] === 'SEC-1') { $sec = $x; } }
        Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/a.txt'), $how . ': SEC-1 applied; errors=' . implode(' / ', $patcher->errors()) . ' reason=' . (string) ($sec['reason'] ?? ''));
    }
},

];
