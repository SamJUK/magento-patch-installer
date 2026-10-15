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

    // ------------------------------------------------- all or nothing, mirrors and writes

    $nginxHunk = "--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n";
    $lockedHunk = "--- a/locked/two.txt\n+++ b/locked/two.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n";
    $mirrored = static function (string $name, string $source) use ($project, $meta): Project {
        return $project($name)
            ->root($meta(['trust' => ['acme/*']]))
            ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
            ->package('acme/patches', $meta(['patches' => [['id' => 'W-1', 'source' => $source]]]));
    };
    $file = static function (Project $p, string $relative): array {
        $path = $p->root . '/' . $relative;
        clearstatcache();

        return is_file($path) ? [(string) file_get_contents($path), fileperms($path) & 0777] : [null, null];
    };
    $recorded = static function (Project $p): array {
        $state = json_decode((string) @file_get_contents($p->root . '/var/composer-patches/state.json'), true);

        return array_keys($state['patches'] ?? []);
    };
    $reasons = static function (array $result): array {
        $reasons = [];
        foreach ($result['fragments'] as $fragment) {
            $reasons[$fragment->target()] = (string) $fragment->reason();
        }

        return $reasons;
    };

    Assert::case('a mirror that will not take the patch is found before anything is written');
    // The primary needed patching, so the pre-flight used to stop there and
    // never looked at the mirror. The conflict turned up after one.txt and the
    // root nginx.conf.sample were written, and the rollback only knew about one.
    $p = $mirrored('mirror-preflight', 'w.patch')
        ->write('vendor/acme/patches/w.patch', "--- a/one.txt\n+++ b/one.txt\n@@ -1 +1,2 @@\n one\n+two\n" . $nginxHunk)
        ->write('one.txt', "one\n")
        ->write('nginx.conf.sample', "1\n2\n3\n")
        ->write('vendor/magento/magento2-base/nginx.conf.sample', "customised\n");
    chmod($p->root . '/vendor/magento/magento2-base/nginx.conf.sample', 0640);
    chmod($p->root . '/nginx.conf.sample', 0604);

    $results = $p->patcher()->run(true);
    Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
    Assert::same("one\n", $file($p, 'one.txt')[0], 'the first file is untouched');
    Assert::same(["1\n2\n3\n", 0604], $file($p, 'nginx.conf.sample'), 'and so is the served copy');
    Assert::same(["customised\n", 0640], $file($p, 'vendor/magento/magento2-base/nginx.conf.sample'), 'and the customised mirror');
    Assert::false(
        strpos($reasons($results[0])['one.txt'], 'rolled back') !== false,
        'refused by the pre-flight, not written and undone'
    );
    Assert::same([], $recorded($p), 'and nothing is recorded as applied');

    Assert::case('a write that fails after others landed puts every one of them back');
    // The tree changing underneath the run, after a clean pre-flight: here a
    // directory git apply can read but not write. Everything the patch wrote
    // before it, primary and mirror, has to come back.
    $p = $mirrored('rollback-mirror', 'w.patch')
        ->write('vendor/acme/patches/w.patch', $nginxHunk . $lockedHunk)
        ->write('nginx.conf.sample', "1\n2\n3\n")
        ->write('vendor/magento/magento2-base/nginx.conf.sample', "1\n2\n3\n")
        ->write('locked/two.txt', "alpha\n");
    chmod($p->root . '/nginx.conf.sample', 0604);
    chmod($p->root . '/vendor/magento/magento2-base/nginx.conf.sample', 0640);
    chmod($p->root . '/locked', 0555);

    if (!is_writable($p->root . '/locked')) {
        $results = $p->patcher()->run(true);
        chmod($p->root . '/locked', 0755);
        Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
        Assert::same(["1\n2\n3\n", 0604], $file($p, 'nginx.conf.sample'), 'the served copy is put back, mode and all');
        Assert::same(["1\n2\n3\n", 0640], $file($p, 'vendor/magento/magento2-base/nginx.conf.sample'), 'and so is its mirror');
        Assert::same("alpha\n", $file($p, 'locked/two.txt')[0], 'and the file that refused is as it was');
        Assert::contains($reasons($results[0])['nginx.conf.sample'], 'rolled back', 'and the report says so');
        Assert::same([], $recorded($p), 'nothing is recorded as applied');
    }

    Assert::case('a mirror that cannot be written takes its primary back with it');
    // The failing fragment wrote its primary before the mirror refused. Its
    // verdict is the failure, which the rollback used to read as "never wrote".
    $p = $mirrored('rollback-own-primary', 'w.patch')
        ->write('vendor/acme/patches/w.patch', "--- a/one.txt\n+++ b/one.txt\n@@ -1 +1,2 @@\n one\n+two\n" . $nginxHunk)
        ->write('one.txt', "one\n")
        ->write('nginx.conf.sample', "1\n2\n3\n")
        ->write('vendor/magento/magento2-base/nginx.conf.sample', "1\n2\n3\n");
    chmod($p->root . '/vendor/magento/magento2-base/nginx.conf.sample', 0444);
    chmod($p->root . '/nginx.conf.sample', 0604);

    if (!is_writable($p->root . '/vendor/magento/magento2-base/nginx.conf.sample')) {
        $results = $p->patcher()->run(true);
        Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
        Assert::same("one\n", $file($p, 'one.txt')[0], 'the other file is put back');
        Assert::same(["1\n2\n3\n", 0604], $file($p, 'nginx.conf.sample'), 'and so is the failing file\'s own primary, mode and all');
        Assert::same(["1\n2\n3\n", 0444], $file($p, 'vendor/magento/magento2-base/nginx.conf.sample'), 'the mirror never changed');
        Assert::same([], $recorded($p), 'nothing is recorded as applied');
    }

    Assert::case('a skipped patch comes off whole or not at all');
    // Taking a skipped patch off writes too, and the second file failing used
    // to leave the first one reverted: half a security patch, on every run.
    $skipping = static function (string $name, string $source) use ($project, $meta): Project {
        return $project($name)
            ->root($meta([
                'trust' => ['acme/*'],
                'sources' => ['acme/patches' => ['skip' => ['U-1' => 'breaks our checkout']]],
            ]))
            ->package('acme/patches', $meta(['patches' => [['id' => 'U-1', 'source' => $source]]]));
    };
    $p = $skipping('skip-whole', 'u.patch')
        ->write('vendor/acme/patches/u.patch', "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1,2 @@\n one\n+two\n--- a/locked/b.txt\n+++ b/locked/b.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n")
        ->write('a.txt', "one\ntwo\n")
        ->write('locked/b.txt', "alpha\nbeta\n");
    chmod($p->root . '/locked', 0555);

    if (!is_writable($p->root . '/locked')) {
        $results = $p->patcher()->run(true);
        chmod($p->root . '/locked', 0755);
        Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
        Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/a.txt'), 'and the file that came off is put back');
        Assert::same("alpha\nbeta\n", (string) file_get_contents($p->root . '/locked/b.txt'), 'beside the one that would not');
    }

    $p = $skipping('skip-whole-control', 'u.patch')
        ->write('vendor/acme/patches/u.patch', "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1,2 @@\n one\n+two\n--- a/b.txt\n+++ b/b.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n")
        ->write('a.txt', "one\ntwo\n")
        ->write('b.txt', "alpha\nbeta\n");
    $results = $p->patcher()->run(true);
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'control: a skipped patch that can come off does');
    Assert::same(["one\n", "alpha\n"], [file_get_contents($p->root . '/a.txt'), file_get_contents($p->root . '/b.txt')], 'control: from every file');

    Assert::case('a skipped patch on a file two packages ship is not taken off one copy');
    $p = $skipping('skip-contested', 'u.patch')
        ->package('magento/magento2-base', ['map' => [['pub/errors', 'pub/errors']]], [], 'magento2-component')
        ->package('magento/magento2-ee-base', ['map' => [['pub/errors', 'pub/errors']]], [], 'magento2-component')
        ->write('vendor/acme/patches/u.patch', "--- a/pub/errors/processor.php\n+++ b/pub/errors/processor.php\n@@ -1,2 +1,2 @@\n <?php\n-\$a = 1;\n+\$a = 2;\n")
        ->write('pub/errors/processor.php', "<?php\n\$a = 2;\n")
        ->write('vendor/magento/magento2-base/pub/errors/processor.php', "<?php\n\$a = 2;\n")
        ->write('vendor/magento/magento2-ee-base/pub/errors/processor.php', "<?php\n\$a = 2;\n");
    $results = $p->patcher()->run(true);
    Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
    Assert::same("<?php\n\$a = 2;\n", (string) file_get_contents($p->root . '/pub/errors/processor.php'), 'and no copy is changed');
    Assert::contains((string) $results[0]['fragments'][0]->reason(), 'deployed to the project root by both', 'it says why');

    Assert::case('a rollback removes what the patch created and restores what it deleted');
    $p = $mirrored('rollback-create-delete', 'w.patch')
        ->write(
            'vendor/acme/patches/w.patch',
            "diff --git a/new/dir/created.txt b/new/dir/created.txt\nnew file mode 100644\n"
            . "--- /dev/null\n+++ b/new/dir/created.txt\n@@ -0,0 +1 @@\n+hello\n"
            . "diff --git a/gone.txt b/gone.txt\ndeleted file mode 100644\n"
            . "--- a/gone.txt\n+++ /dev/null\n@@ -1 +0,0 @@\n-bye\n"
            . $lockedHunk
        )
        ->write('gone.txt', "bye\n")
        ->write('locked/two.txt', "alpha\n");
    chmod($p->root . '/gone.txt', 0640);
    chmod($p->root . '/locked', 0555);

    if (!is_writable($p->root . '/locked')) {
        $results = $p->patcher()->run(true);
        chmod($p->root . '/locked', 0755);
        Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
        Assert::false(file_exists($p->root . '/new'), 'the created file is gone, and the directories made for it');
        Assert::same(["bye\n", 0640], $file($p, 'gone.txt'), 'the deleted file is back, mode and all');
        $written = [];
        foreach (json_decode(Renderer::json($results, []), true)['patches'] as $reported) {
            foreach ($reported['targets'] as $target) {
                $written = array_merge($written, $target['written'] ?? []);
            }
        }
        Assert::same([], $written, 'and --json lists nothing as written');
        Assert::same([], $recorded($p), 'nothing is recorded as applied');
    }

    Assert::case('a rollback puts back a file the patch superseded');
    // A replacement moves the previous version aside and deletes it once the
    // new one is in. The rollback reversed only APPLIED fragments, so a later
    // failure left the new version in place under a patch that did not apply.
    $p = $mirrored('rollback-supersede', 's1.patch')
        ->write(
            'vendor/acme/patches/s1.patch',
            "diff --git a/bin/status b/bin/status\nnew file mode 100755\n--- /dev/null\n+++ b/bin/status\n@@ -0,0 +1 @@\n+v1\n"
        )
        ->write('locked/two.txt', "alpha\n");
    $p->patcher()->run(true);
    Assert::same(["v1\n", 0755], $file($p, 'bin/status'), 'the first version is installed');

    $p->package('acme/patches', $meta(['patches' => [
        ['id' => 'S-1', 'source' => 's1.patch'],
        ['id' => 'S-2', 'source' => 's2.patch'],
    ]]))->write(
        'vendor/acme/patches/s2.patch',
        "diff --git a/bin/status b/bin/status\nnew file mode 100755\n--- /dev/null\n+++ b/bin/status\n@@ -0,0 +1 @@\n+v2\n"
        . $lockedHunk
    );
    chmod($p->root . '/locked', 0555);

    if (!is_writable($p->root . '/locked')) {
        $results = $p->patcher()->run(true);
        chmod($p->root . '/locked', 0755);
        Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
        Assert::same(["v1\n", 0755], $file($p, 'bin/status'), 'the previous version is back, executable bit and all');
        Assert::same([], glob($p->root . '/bin/status.mpi-superseded-*'), 'with nothing left aside');
        Assert::same(1, count($recorded($p)), 'and only the first patch is on record');
    }

    Assert::case('a patch that lands everywhere is recorded once it has');
    $p = $mirrored('atomic-control', 'w.patch')
        ->write('vendor/acme/patches/w.patch', "--- a/one.txt\n+++ b/one.txt\n@@ -1 +1,2 @@\n one\n+two\n" . $nginxHunk)
        ->write('one.txt', "one\n")
        ->write('nginx.conf.sample', "1\n2\n3\n")
        ->write('vendor/magento/magento2-base/nginx.conf.sample', "1\n2\n3\n");
    chmod($p->root . '/vendor/magento/magento2-base/nginx.conf.sample', 0640);
    chmod($p->root . '/nginx.conf.sample', 0604);

    $results = $p->patcher()->run(true);
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'the run is clean');
    Assert::same("one\ntwo\n", $file($p, 'one.txt')[0], 'the first file is patched');
    // git apply writes a fresh file, so the served copy's mode is git's to set.
    Assert::same("1\n2a\n3\n", $file($p, 'nginx.conf.sample')[0], 'and the served copy');
    Assert::same(["1\n2a\n3\n", 0640], $file($p, 'vendor/magento/magento2-base/nginx.conf.sample'), 'and its mirror, keeping its mode');
    Assert::same(1, count($recorded($p)), 'and the patch is on record');

    Assert::case('a skipped patch that is present is taken back off disk');
    // unwanted() is the one function in the codebase that deletes a security
    // patch, and until now it was exercised only by the Docker acceptance run,
    // which the lint and analyse jobs do not execute.
    $p = $project('unwanted')
        ->root($meta([
            'trust' => ['acme/*'],
            'sources' => ['acme/patches' => ['skip' => ['U-1' => 'breaks our checkout']]],
        ]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'U-1', 'source' => 'a.patch']]]));
    $p->write('vendor/acme/patches/a.patch', $patch);
    $p->write('a.txt', "one\ntwo\n");

    $results = $p->patcher()->run(true);
    Assert::same(1, count($results), 'the skipped patch still gets a row');
    Assert::same(false, $results[0]['applicable'], 'it is not applicable');
    Assert::contains((string) $results[0]['skipped'], 'breaks our checkout', 'and carries the reason');
    Assert::same(
        Fragment::REVERTED,
        $results[0]['fragments'][0]->verdict(),
        'the fragment says it was removed'
    );
    Assert::same("one\n", (string) file_get_contents($p->root . '/a.txt'), 'and the file really lost it');

    Assert::case('a skipped patch that was never there is not a failure');
    $p = $project('unwanted-absent')
        ->root($meta([
            'trust' => ['acme/*'],
            'sources' => ['acme/patches' => ['skip' => ['U-1' => 'breaks our checkout']]],
        ]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'U-1', 'source' => 'a.patch']]]));
    $p->write('vendor/acme/patches/a.patch', $patch);
    $p->write('a.txt', "one\n");

    $results = $p->patcher()->run(true);
    Assert::same(
        Fragment::SKIPPED,
        $results[0]['fragments'][0]->verdict(),
        'nothing to remove, and nothing to complain about'
    );
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'so the run stays clean');

    Assert::case('a skipped patch nobody can classify is a conflict, not a shrug');
    // Local edits mean the reverse-check fails for a reason other than absence.
    // Reporting that as "not present" leaves the store running a patch the
    // project believes it switched off.
    $p = $project('unwanted-drift')
        ->root($meta([
            'trust' => ['acme/*'],
            'sources' => ['acme/patches' => ['skip' => ['U-1' => 'breaks our checkout']]],
        ]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'U-1', 'source' => 'a.patch']]]));
    $p->write('vendor/acme/patches/a.patch', $patch);
    $p->write('a.txt', "something else entirely\n");

    $results = $p->patcher()->run(true);
    Assert::same(
        Fragment::CONFLICT,
        $results[0]['fragments'][0]->verdict(),
        'nobody can say whether it is applied, so nobody says it is gone'
    );
    Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'and the run fails');

    Assert::case('a file a skip emptied is not "the module does not ship this"');
    // The reversal pass deletes the file when the skipped patch created it.
    // Anything patched on top then finds nothing there, and an absent target
    // with a modification hunk otherwise reads as NOT_APPLICABLE — which does
    // not move the exit code and prints under a green banner. A security patch
    // reclassified as one that was never needed.
    $p = $project('vacated')
        ->root($meta([
            'trust' => ['acme/*'],
            'sources' => ['acme/patches' => ['skip' => ['MAKER' => 'we do not want the file']]],
        ]))
        ->package('acme/patches', $meta(['patches' => [
            ['id' => 'MAKER', 'source' => 'make.patch'],
            ['id' => 'ONTOP', 'source' => 'ontop.patch'],
        ]]));
    $p->write('vendor/acme/patches/make.patch', "--- /dev/null\n+++ b/made.txt\n@@ -0,0 +1 @@\n+hello\n");
    $p->write('vendor/acme/patches/ontop.patch', "--- a/made.txt\n+++ b/made.txt\n@@ -1 +1,2 @@\n hello\n+world\n");
    $p->write('made.txt', "hello\n");

    $results = [];
    foreach ($p->patcher()->run(true) as $result) {
        $results[(string) $result['id']] = $result;
    }

    Assert::same(
        Fragment::REVERTED,
        $results['MAKER']['fragments'][0]->verdict(),
        'the skipped patch is removed, which deletes the file'
    );
    Assert::same(false, file_exists($p->root . '/made.txt'), 'the file really is gone');
    Assert::same(
        Fragment::CONFLICT,
        $results['ONTOP']['fragments'][0]->verdict(),
        'and the patch that needed it says so, rather than shrugging'
    );
    Assert::contains(
        (string) $results['ONTOP']['fragments'][0]->reason(),
        'removed by reverting',
        'naming the skip that took it away'
    );
    Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run does not exit 0');

    Assert::case('the old isolated/emergency keys are refused, not ignored');
    // Silently skipping a block named "isolated" would drop every patch in it
    // and exit 0 — a package that had been patching correctly for a year would
    // go quiet on upgrade, which is the exact failure this plugin exists to
    // prevent.
    $p = $project('legacy-keys')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => [
            '2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'isolated' => [['id' => 'L-1', 'source' => 'a.patch']],
            ],
        ]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same(1, count($collector->errors()), 'the old key is reported');
    Assert::contains($collector->errors()[0], 'uses "isolated"', 'and named');
    Assert::contains($collector->errors()[0], 'one "patches" list', 'with what to do instead');
    Assert::same([], $declarations, 'and nothing is silently declared from it');

    Assert::case('a line without cumulative does not have to be a chain');
    // Independent patches for one base version are ordinary. Only a line that
    // says it is cumulative gets the every-patch-but-one-needs-depends rule.
    $p = $project('non-cumulative')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => [
            '2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'patches' => [
                    ['id' => 'I-1', 'source' => 'a.patch'],
                    ['id' => 'I-2', 'source' => 'a.patch'],
                    ['id' => 'I-3', 'source' => 'a.patch'],
                ],
            ],
        ]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same([], $collector->errors(), 'three roots is fine when nothing claims a chain');
    Assert::same(3, count($declarations), 'and all three are declared');

    Assert::case('a cumulative line derives its chain from the order listed');
    // Nothing declares depends, and the edges still exist. That is the point:
    // a derived edge cannot be forgotten the way a written one could.
    $p = $project('cumulative-chain')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => [
            '2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'cumulative' => true,
                'patches' => [
                    ['id' => 'L-1', 'source' => 'a.patch'],
                    ['id' => 'L-2', 'source' => 'a.patch'],
                    ['id' => 'L-3', 'source' => 'a.patch'],
                ],
            ],
        ]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $declarations = $collector->collect();
    Assert::same([], $collector->errors(), 'no complaints');

    $entries = [];
    foreach ($p->patcher()->catalogue() as $entry) {
        $entries[(string) $entry['id']] = $entry;
    }
    Assert::same([], $entries['L-1']['depends'], 'the first depends on nothing');
    Assert::same(['L-1'], $entries['L-2']['depends'], 'the second on the first');
    Assert::same(['L-2'], $entries['L-3']['depends'], 'the third on the second');

    Assert::case('skipping a link in a derived chain still cascades');
    $p = $project('cumulative-skip')
        ->root($meta([
            'trust' => ['acme/*'],
            'sources' => ['acme/patches' => ['skip' => ['L-2@2.4.8-p5' => 'breaks checkout']]],
        ]))
        ->package('acme/patches', $meta(['lines' => [
            '2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'cumulative' => true,
                'patches' => [
                    ['id' => 'L-1', 'source' => 'a.patch'],
                    ['id' => 'L-2', 'source' => 'a.patch'],
                    ['id' => 'L-3', 'source' => 'a.patch'],
                ],
            ],
        ]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $states = [];
    foreach ($p->collector()->collect() as $declaration) {
        $states[(string) $declaration['id']] = $declaration['skipped'];
    }
    Assert::same(null, $states['L-1'], 'the one before it is untouched');
    Assert::contains((string) $states['L-2'], 'breaks checkout', 'the named one carries the reason');
    Assert::contains((string) $states['L-3'], 'depends on', 'and the one after it goes too');

    Assert::case('depends and cumulative are not both');
    // Two ways of saying the same thing, disagreeing silently, is worse than
    // either. The line already said the order; a per-patch edge can only
    // contradict it.
    $p = $project('cumulative-and-depends')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => [
            '2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'cumulative' => true,
                'patches' => [
                    ['id' => 'D-1', 'source' => 'a.patch'],
                    ['id' => 'D-2', 'source' => 'a.patch', 'depends' => ['D-1']],
                ],
            ],
        ]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $collector = $p->collector();
    $collector->collect();
    Assert::same(1, count($collector->errors()), 'reported');
    Assert::contains($collector->errors()[0], 'cumulative line', 'and says which rule it broke');

    Assert::case('the catalogue refuses to call an unreadable patch applicable');
    // magento-patches:list never runs runOne(), so it never saw an empty or truncated
    // diff: it printed "0 targets — applies here" and exited 0, while status
    // and verify called the same file a config error and exited 3.
    $p = $project('catalogue-unreadable')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'E-1', 'source' => 'empty.patch']]]));
    $p->write('vendor/acme/patches/empty.patch', '');

    $patcher = $p->patcher();
    $entries = $patcher->catalogue();
    Assert::same(1, count($entries), 'the patch is still listed');
    Assert::same(false, $entries[0]['applicable'], 'but not as something that applies here');
    Assert::contains((string) $entries[0]['reason'], 'could not be read', 'and says why');
    Assert::same(1, count($patcher->errors()), 'and it is a configuration error, so the command exits 3');

    Assert::case('the catalogue names what each patch depends on');
    // `depends` holds owner-scoped origin strings, not indices. Typed as int,
    // this was a TypeError out of magento-patches:list — and magento-patches:list is the one
    // command that answers before anything is installed, so a crash there is
    // the first thing anyone evaluating the package sees.
    $p = $project('catalogue-depends')
        ->root($meta(['trust' => ['acme/*']]))
        ->package('acme/patches', $meta(['lines' => [
            '2.4.8-p5' => [
                'base' => ['magento/magento2-base' => '2.4.8-p5'],
                'cumulative' => true, 'patches' => [
                    ['id' => 'C-1', 'source' => 'a.patch'],
                    ['id' => 'C-2', 'source' => 'a.patch', 'depends' => ['C-1']],
                ],
            ],
        ]]));
    $p->write('vendor/acme/patches/a.patch', $patch);

    $entries = [];
    foreach ($p->patcher()->catalogue() as $entry) {
        $entries[(string) $entry['id']] = $entry;
    }

    Assert::same(2, count($entries), 'both patches are catalogued');
    Assert::same([], $entries['C-1']['depends'], 'the first depends on nothing');
    Assert::same(['C-1'], $entries['C-2']['depends'], 'and the second names an id, not an index');

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

    // ------------------------------------------------------- root-file mirrors

    // A root file's other copy is whatever magento-composer-installer deploys
    // it from. aaa/ sorts ahead of magento/, which is what let a first match
    // pick the decoy on a real 2.4.9 store.
    $mirrored = static function (string $name, string $type, array $rootExtra = []) use ($project, $meta, $ten): Project {
        $p = $project($name)
            ->root(array_merge($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]), $rootExtra))
            ->package('aaa/analytics', ['map' => [['decoy', 'nginx.conf.sample']]], [], $type)
            ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component');

        return $p->write('nginx.conf.sample', $ten)
            ->write('vendor/magento/magento2-base/nginx.conf.sample', $ten)
            ->write('vendor/aaa/analytics/decoy', $ten)
            ->write('own.patch', "--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n");
    };
    $patched = str_replace("2\n", "2a\n", $ten);

    Assert::case('mirrors: an ordinary library\'s extra.map is not a deploy mapping');
    $p = $mirrored('mirror-library', 'library');
    $patcher = $p->patcher();
    $results = $outcome($patcher->run(true));
    Assert::same([], $patcher->errors(), 'nothing to complain about');
    Assert::same($patched, (string) file_get_contents($p->root . '/nginx.conf.sample'), 'the served copy is patched');
    Assert::same(
        $patched,
        (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'),
        'and so is magento2-base\'s, which deploys it'
    );
    Assert::same($ten, (string) file_get_contents($p->root . '/vendor/aaa/analytics/decoy'), 'the library\'s decoy is left alone');
    Assert::true(empty($results['project/root OWN-1']['blocked']), 'and the patch is not refused');

    Assert::case('mirrors: two packages deploying one root file is a configuration error, and nothing is written');
    $p = $mirrored('mirror-contested', 'magento2-component');
    $patcher = $p->patcher();
    $results = $outcome($patcher->run(true));
    Assert::same($ten, (string) file_get_contents($p->root . '/nginx.conf.sample'), 'the served copy is not written');
    Assert::same($ten, (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'), 'nor magento2-base\'s');
    Assert::same($ten, (string) file_get_contents($p->root . '/vendor/aaa/analytics/decoy'), 'nor the other package\'s');
    Assert::true(!empty($results['project/root OWN-1']['blocked']), 'the patch is refused');
    Assert::contains(
        (string) $results['project/root OWN-1']['reason'],
        'nginx.conf.sample is deployed to the project root by both',
        'and says why'
    );
    $errors = implode("\n", $patcher->errors());
    Assert::contains($errors, 'aaa/analytics', 'the error names one claimant');
    Assert::contains($errors, 'magento/magento2-base', 'and the other');
    Assert::contains($errors, 'extra.magento-deploy-ignore', 'and how to settle it');
    $results = $outcome($p->patcher()->run(false));
    Assert::true(!empty($results['project/root OWN-1']['blocked']), 'status refuses it too');

    Assert::case('mirrors: the root\'s magento-deploy-ignore settles a contest');
    $p = $mirrored('mirror-ignored', 'magento2-component', ['magento-deploy-ignore' => ['aaa/analytics' => ['/nginx.conf.sample']]]);
    $patcher = $p->patcher();
    $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no contest once one claimant is ignored');
    Assert::same(
        $patched,
        (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'),
        'magento2-base\'s copy is patched'
    );
    Assert::same($ten, (string) file_get_contents($p->root . '/vendor/aaa/analytics/decoy'), 'the ignored one is not');

    Assert::case('mirrors: a store ignoring magento2-base\'s copy of a file still gets both copies patched');
    $p = $mirrored('mirror-own-copy', 'library', ['magento-deploy-ignore' => ['magento/magento2-base' => ['/nginx.conf.sample']]]);
    $patcher = $p->patcher();
    $patcher->run(true);
    Assert::same([], $patcher->errors(), 'nothing to complain about');
    Assert::same($patched, (string) file_get_contents($p->root . '/nginx.conf.sample'), 'the store\'s own copy is patched');
    Assert::same(
        $patched,
        (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'),
        'and magento2-base\'s, as before'
    );

    Assert::case('mirrors: the root\'s magento-map-overwrite replaces a package\'s map');
    $p = $mirrored('mirror-overwrite', 'magento2-component', ['magento-map-overwrite' => ['AAA/Analytics' => []]]);
    $patcher = $p->patcher();
    $patcher->run(true);
    Assert::same([], $patcher->errors(), 'an emptied map claims nothing, whatever the key\'s case');
    Assert::same(
        $patched,
        (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'),
        'magento2-base\'s copy is patched'
    );

    // Adobe Commerce: magento2-base and magento2-ee-base both map all of
    // pub/errors, but only base ships processor.php, which StyleSmuggler
    // patches. Contesting the directory refused it on every Commerce store.
    $processor = "<?php\n\$a = 1;\n";
    $commerce = static function (string $name, bool $eeShipsIt) use ($project, $meta, $processor): Project {
        $p = $project($name)
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->package('magento/magento2-base', ['map' => [['pub/errors', 'pub/errors']]], [], 'magento2-component')
            ->package('magento/magento2-ee-base', ['map' => [['pub/errors', 'pub/errors']]], [], 'magento2-component')
            ->write('vendor/magento/magento2-base/pub/errors/processor.php', $processor)
            ->write('vendor/magento/magento2-ee-base/pub/errors/enterprise/page.phtml', "ee\n")
            ->write('pub/errors/processor.php', $processor)
            ->write('pub/errors/enterprise/page.phtml', "ee\n")
            ->write('own.patch', "--- a/pub/errors/processor.php\n+++ b/pub/errors/processor.php\n@@ -1,2 +1,2 @@\n <?php\n-\$a = 1;\n+\$a = 2;\n");

        return $eeShipsIt ? $p->write('vendor/magento/magento2-ee-base/pub/errors/processor.php', $processor) : $p;
    };

    Assert::case('mirrors: two packages deploying one directory contest only the files both ship');
    $p = $commerce('mirror-commerce', false);
    $patcher = $p->patcher();
    $results = $patcher->run(true);
    Assert::same([], $patcher->errors(), 'no configuration error');
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'the patch applies');
    Assert::same("<?php\n\$a = 2;\n", (string) file_get_contents($p->root . '/pub/errors/processor.php'), 'to the served copy');
    Assert::same(
        "<?php\n\$a = 2;\n",
        (string) file_get_contents($p->root . '/vendor/magento/magento2-base/pub/errors/processor.php'),
        'and to magento2-base\'s, the package that ships it'
    );
    Assert::false(file_exists($p->root . '/vendor/magento/magento2-ee-base/pub/errors/processor.php'), 'not to one that does not');

    Assert::case('mirrors: a served root file that has gone missing is not "not present"');
    // A failed update can leave magento2-base installed with its root files
    // never deployed. Calling the served copy "not present in this
    // installation" passed verify on a store with no pub/index.php. The patch
    // goes to the package copy, which the next deploy serves from, with a
    // warning: refusing it blocked every month built on it.
    $gone = static function (string $name, array $rootExtra, bool $shipped) use ($project, $meta, $ten): Project {
        $p = $project($name)
            ->root(array_merge($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]), $rootExtra))
            ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
            ->write('own.patch', "--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n");

        return $shipped ? $p->write('vendor/magento/magento2-base/nginx.conf.sample', $ten) : $p;
    };
    $patcher = $gone('mirror-gone', [], true)->patcher();
    Assert::same(Renderer::UNAPPLIED, Renderer::exitCode($patcher->run(false)), 'verify fails while the package copy is unpatched');
    Assert::contains(
        implode("\n", array_column($patcher->advisories(), 'message')),
        'composer reinstall magento/magento2-base',
        'and warns how to put the served copy back'
    );
    $p = $gone('mirror-gone-apply', [], true);
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'an apply patches the package copy');
    Assert::contains((string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'), '2a', 'which is where the next deploy comes from');
    $results = $gone('mirror-gone-ignored', ['magento-deploy-ignore' => ['magento/magento2-base' => ['/nginx.conf.sample']]], true)->patcher()->run(false);
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'control: a file the store has the installer ignore can be absent');
    $results = $gone('mirror-gone-unshipped', [], false)->patcher()->run(false);
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'control: so can one no package ships');

    Assert::case('mirrors: a patch deleting a root file deletes both copies');
    // The copies are in step, so the package copy follows the served one. A
    // deleted primary has nothing to copy, and the patch could never apply.
    $deleting = static function (string $name) use ($project, $meta): Project {
        return $project($name)
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->package('magento/magento2-base', ['map' => [['pub/old.php', 'pub/old.php']]], [], 'magento2-component')
            ->write('own.patch', "diff --git a/pub/old.php b/pub/old.php\ndeleted file mode 100644\n--- a/pub/old.php\n+++ /dev/null\n@@ -1 +0,0 @@\n-old\n")
            ->write('pub/old.php', "old\n")
            ->write('vendor/magento/magento2-base/pub/old.php', "old\n");
    };
    $p = $deleting('mirror-delete');
    Assert::same(Renderer::UNAPPLIED, Renderer::exitCode($p->patcher()->run(false)), 'a report says it is not applied yet');
    $results = $p->patcher()->run(true);
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'the patch applies');
    Assert::false(file_exists($p->root . '/pub/old.php'), 'the served copy is gone');
    Assert::false(file_exists($p->root . '/vendor/magento/magento2-base/pub/old.php'), 'and so is magento2-base\'s');
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'the next verify is clean');
    $written = 0;
    foreach ($p->patcher()->run(true) as $result) {
        foreach ($result['fragments'] as $fragment) {
            $written += $fragment->wasWritten() ? 1 : 0;
        }
    }
    Assert::same(0, $written, 'and a further apply writes nothing');

    $p = $deleting('mirror-delete-rollback');
    $p->write('own.patch', (string) file_get_contents($p->root . '/own.patch') . "--- a/locked/two.txt\n+++ b/locked/two.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n")
        ->write('locked/two.txt', "alpha\n");
    chmod($p->root . '/locked', 0555);

    if (!is_writable($p->root . '/locked')) {
        $results = $p->patcher()->run(true);
        chmod($p->root . '/locked', 0755);
        Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'a later file failing fails the run');
        Assert::same("old\n", (string) @file_get_contents($p->root . '/pub/old.php'), 'and the served copy comes back');
        Assert::same("old\n", (string) @file_get_contents($p->root . '/vendor/magento/magento2-base/pub/old.php'), 'and so does magento2-base\'s');
    }

    Assert::case('mirrors: list refuses a contested patch, as status does');
    $p = $commerce('mirror-commerce-contested', true);
    $patcher = $p->patcher();
    $entries = $patcher->catalogue();
    Assert::false($entries[0]['applicable'], 'list refuses it');
    Assert::contains((string) $entries[0]['reason'], 'pub/errors/processor.php is deployed to the project root by both', 'and says why');
    Assert::true($patcher->errors() !== [], 'and reports the configuration error');
    $results = $p->patcher()->run(false);
    Assert::true(!empty($results[0]['blocked']), 'status refuses it too');

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

'writes' => static function (Sandbox $sandbox): void {
    $meta = static function (array $extra): array {
        return ['magento-patches' => $extra];
    };
    $verdicts = static function (array $results): array {
        $out = [];
        foreach ($results as $result) {
            foreach ($result['fragments'] as $fragment) {
                $out[$fragment->target()] = $fragment->verdict();
            }
        }

        return $out;
    };

    Assert::case('symlink deploy: a skipped patch comes off the one file both paths name');
    // The served copy is a link to magento2-base's, so the copy and its
    // mirror are one file. Reverting it as both reverted it twice, the second
    // failed, and the rollback put the patch back on every run.
    $vendor = 'vendor/magento/magento2-base/nginx.conf.sample';
    $linked = static function (string $name, array $rootExtra, bool $link, string $contents) use ($sandbox, $meta, $vendor): Project {
        $p = (new Project($sandbox, $name))
            ->root($meta($rootExtra))
            ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
            ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]))
            ->write('vendor/acme/patches/p.patch', "--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n")
            ->write($vendor, $contents);

        if ($link) {
            symlink($vendor, $p->root . '/nginx.conf.sample');
        } else {
            $p->write('nginx.conf.sample', $contents);
        }

        return $p;
    };
    $skip = ['trust' => ['acme/*'], 'sources' => ['acme/patches' => ['skip' => ['P-1' => 'breaks us']]]];

    foreach (['separate copies' => false, 'a symlinked copy' => true] as $how => $link) {
        $p = $linked('symlink-skip-' . ($link ? 'link' : 'copy'), $skip, $link, "1\n2a\n3\n");
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $how . ': it comes off');
        Assert::same("1\n2\n3\n", (string) file_get_contents($p->root . '/' . $vendor), $how . ': and is gone');
        Assert::same("1\n2\n3\n", (string) file_get_contents($p->root . '/nginx.conf.sample'), $how . ': from the served copy too');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), $how . ': and the next status is clean');
    }

    $p = $linked('symlink-apply', ['trust' => ['acme/*']], true, "1\n2\n3\n");
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'control: applying through the link is clean');
    Assert::true(is_link($p->root . '/nginx.conf.sample'), 'control: and the link is kept');
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'control: and so is the next verify');

    Assert::case('a package deploying to a parent of vendor/ cannot take another package\'s copy');
    // A destination of `vendor` or `vendor/magento` sits above magento2-base's
    // install directory, so a patch naming the vendor copy took the intruder's
    // path, which does not exist, as its other copy. The served file was never
    // patched, and verify said it was.
    $base = 'vendor/magento/magento2-base/pub/errors/processor.php';
    $hijacked = static function (string $name, ?array $map) use ($sandbox, $meta, $base): Project {
        $p = (new Project($sandbox, $name))
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->package('magento/magento2-base', ['map' => [['pub/errors', 'pub/errors']]], [], 'magento2-component');

        if ($map !== null) {
            $p->package('zzz/widget', ['map' => $map], [], 'magento2-component')->write('vendor/zzz/widget/assets/readme.txt', "x\n");
        }

        return $p->write($base, "<?php\n\$a = 1;\n")
            ->write('pub/errors/processor.php', "<?php\n\$a = 1;\n")
            ->write('own.patch', "--- a/$base\n+++ b/$base\n@@ -1,2 +1,2 @@\n <?php\n-\$a = 1;\n+\$a = 2;\n");
    };

    foreach (['no other package' => null, 'a map onto vendor' => [['assets', 'vendor']], 'a map onto vendor/magento' => [['assets', 'vendor/magento']]] as $how => $map) {
        $p = $hijacked('hijack-' . md5($how), $map);
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $how . ': it applies');
        Assert::same("<?php\n\$a = 2;\n", (string) file_get_contents($p->root . '/' . $base), $how . ': to the package copy');
        Assert::same("<?php\n\$a = 2;\n", (string) file_get_contents($p->root . '/pub/errors/processor.php'), $how . ': and the served copy');
    }

    // A patch spans modules, and a store only has some of them. A file in a
    // module it does not install is not its to write, in either direction.
    $modify = "--- a/vendor/magento/module-present/a.php\n+++ b/vendor/magento/module-present/a.php\n@@ -1 +1,2 @@\n one\n+two\n";
    $absentStore = static function (string $name, string $patch, array $rootExtra = []) use ($sandbox, $meta): Project {
        return (new Project($sandbox, $name))
            ->root($meta(array_merge(['trust' => ['acme/*']], $rootExtra)))
            ->package('acme/patches', $meta(['patches' => [['id' => 'C-1', 'source' => 'c.patch']]]))
            ->package('magento/module-present', [])
            ->write('vendor/acme/patches/c.patch', $patch)
            ->write('vendor/magento/module-present/a.php', "one\n");
    };

    Assert::case('a skipped patch deleting a file from a module this store does not install recreates nothing');
    // Reversing a deletion checks out wherever the file is absent, so the skip
    // read as "still present" and taking it off wrote the vulnerable file back.
    $delete = "diff --git a/vendor/magento/module-absent/Old.php b/vendor/magento/module-absent/Old.php\ndeleted file mode 100644\n"
        . "--- a/vendor/magento/module-absent/Old.php\n+++ /dev/null\n@@ -1 +0,0 @@\n-<?php // vulnerable\n";
    $p = $absentStore('absent-skip-delete', $delete, ['sources' => ['acme/patches' => ['skip' => ['C-1' => 'not for us']]]]);
    $results = $p->patcher()->run(false);
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'status is clean');
    Assert::same(['vendor/magento/module-absent/Old.php' => Fragment::SKIPPED], $verdicts($results), 'and calls it skipped, not still present');
    $p->patcher()->run(true);
    Assert::false(file_exists($p->root . '/vendor/magento/module-absent'), 'apply writes nothing there');

    $p = $absentStore('present-skip-delete', str_replace('module-absent', 'module-present', $delete), ['sources' => ['acme/patches' => ['skip' => ['C-1' => 'not for us']]]]);
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'control: in a module it does install, the skip comes off');
    Assert::same("<?php // vulnerable\n", (string) @file_get_contents($p->root . '/vendor/magento/module-present/Old.php'), 'control: and the file is back, as the store asked');

    Assert::case('a patch creating a file in a module this store does not install leaves it alone');
    $create = "diff --git a/vendor/magento/module-absent/New.php b/vendor/magento/module-absent/New.php\nnew file mode 100644\n"
        . "--- /dev/null\n+++ b/vendor/magento/module-absent/New.php\n@@ -0,0 +1 @@\n+<?php // new\n";
    $p = $absentStore('absent-create', $modify . $create);
    $results = $p->patcher()->run(true);
    Assert::same(Renderer::OK, Renderer::exitCode($results), 'the patch applies');
    Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/vendor/magento/module-present/a.php'), 'to the module it does install');
    Assert::false(file_exists($p->root . '/vendor/magento/module-absent'), 'and writes nothing into the one it does not');
    Assert::same(Fragment::NOT_APPLICABLE, $verdicts($results)['vendor/magento/module-absent/New.php'], 'which is not applicable here');

    $p = $absentStore('present-create', $modify . str_replace('module-absent', 'module-present', $create));
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'control: in a module it does install');
    Assert::same("<?php // new\n", (string) @file_get_contents($p->root . '/vendor/magento/module-present/New.php'), 'control: the file is created');

    Assert::case('magento2-base installed from a path repository keeps both copies in step');
    // Its install path resolves through the symlink a path repository leaves,
    // so a patch naming the vendor path matched no mapping, patched only the
    // package copy, and verify passed with the served file unpatched.
    foreach (['the root path' => 'nginx.conf.sample', 'the vendor path' => 'vendor/magento/magento2-base/nginx.conf.sample'] as $how => $target) {
        $p = (new Project($sandbox, 'path-repo-' . md5($how)))
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->write('packages/base/nginx.conf.sample', "1\n2\n3\n")
            ->write('nginx.conf.sample', "1\n2\n3\n")
            ->write('own.patch', "--- a/$target\n+++ b/$target\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n");
        mkdir($p->root . '/vendor/magento', 0777, true);
        symlink('../../packages/base', $p->root . '/vendor/magento/magento2-base');
        $p->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component');

        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'patching ' . $how . ' applies');
        Assert::same("1\n2a\n3\n", (string) file_get_contents($p->root . '/nginx.conf.sample'), 'patching ' . $how . ': to the served copy');
        Assert::same("1\n2a\n3\n", (string) file_get_contents($p->root . '/packages/base/nginx.conf.sample'), 'patching ' . $how . ': and the package copy');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'patching ' . $how . ': and the next verify is clean');
    }

    Assert::case('a mode change is applied, not read as already there');
    // Reverse-checking a mode change only warns when the mode differs, so a
    // fragment that only made a file executable read as already applied.
    foreach (['a mode change alone' => '', 'a mode change with content' => "--- a/bin/run\n+++ b/bin/run\n@@ -1 +1 @@\n-a\n+b\n"] as $how => $content) {
        $p = (new Project($sandbox, 'mode-' . md5($how)))
            ->root($meta(['trust' => ['acme/*']]))
            ->package('acme/patches', $meta(['patches' => [['id' => 'M-1', 'source' => 'm.patch']]]))
            ->write('vendor/acme/patches/m.patch', "diff --git a/bin/run b/bin/run\nold mode 100644\nnew mode 100755\n" . $content)
            ->write('bin/run', "a\n");
        chmod($p->root . '/bin/run', 0644);
        Assert::same(Renderer::UNAPPLIED, Renderer::exitCode($p->patcher()->run(false)), $how . ': a report says it is not applied');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $how . ': it applies');
        clearstatcache();
        Assert::same(0755, fileperms($p->root . '/bin/run') & 0777, $how . ': and the file is executable');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), $how . ': and the next verify is clean');
    }

    Assert::case('a rolled-back rename puts the old name back');
    // The journal kept the new name only. Rolling back deleted it and never
    // restored the file git apply had renamed away, and the next run, finding
    // neither name, called the patch not applicable and exited 0.
    $rename = "diff --git a/old.php b/new.php\nsimilarity index 80%\nrename from old.php\nrename to new.php\n"
        . "--- a/old.php\n+++ b/new.php\n@@ -1,3 +1,3 @@\n a\n-b\n+B\n c\n";
    $renaming = static function (string $name, string $patch) use ($sandbox, $meta): Project {
        return (new Project($sandbox, $name))
            ->root($meta(['trust' => ['acme/*']]))
            ->package('acme/patches', $meta(['patches' => [['id' => 'R-1', 'source' => 'r.patch']]]))
            ->write('vendor/acme/patches/r.patch', $patch)
            ->write('old.php', "a\nb\nc\n")
            ->write('locked/two.txt', "alpha\n");
    };

    $p = $renaming('rename-control', $rename);
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'control: a rename applies');
    Assert::same(["a\nB\nc\n", false], [@file_get_contents($p->root . '/new.php'), file_exists($p->root . '/old.php')], 'control: under its new name');
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), 'control: and the next verify is clean');

    $p = $renaming('rename-rollback', $rename . "--- a/locked/two.txt\n+++ b/locked/two.txt\n@@ -1 +1,2 @@\n alpha\n+beta\n");
    chmod($p->root . '/locked', 0555);

    if (!is_writable($p->root . '/locked')) {
        $results = $p->patcher()->run(true);
        chmod($p->root . '/locked', 0755);
        Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'a later file failing fails the run');
        Assert::same("a\nb\nc\n", (string) @file_get_contents($p->root . '/old.php'), 'and the store\'s file is back under its old name');
        Assert::false(file_exists($p->root . '/new.php'), 'with nothing left under the new one');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'and the next run applies it');
        Assert::same("a\nB\nc\n", (string) @file_get_contents($p->root . '/new.php'), 'under its new name');
    }

    Assert::case('a rename of a file with another copy is refused, not half done');
    // git apply renames one path. Keeping a root file's other copy in step
    // would mean mapping the old name too, and no Adobe patch renames a file.
    $p = (new Project($sandbox, 'rename-mapped'))
        ->root($meta(['trust' => ['acme/*']]))
        ->package('magento/magento2-base', ['map' => [['pub/old.php', 'pub/old.php'], ['pub/new.php', 'pub/new.php']]], [], 'magento2-component')
        ->package('acme/patches', $meta(['patches' => [['id' => 'R-1', 'source' => 'r.patch']]]))
        ->write('vendor/acme/patches/r.patch', str_replace(['old.php', 'new.php'], ['pub/old.php', 'pub/new.php'], $rename))
        ->write('pub/old.php', "a\nb\nc\n")
        ->write('vendor/magento/magento2-base/pub/old.php', "a\nb\nc\n");
    $results = $p->patcher()->run(true);
    Assert::same(Renderer::CONFLICT, Renderer::exitCode($results), 'the run fails');
    Assert::contains((string) $results[0]['fragments'][0]->reason(), 'renames pub/old.php', 'and says why');
    Assert::same(
        ["a\nb\nc\n", "a\nb\nc\n", false],
        [@file_get_contents($p->root . '/pub/old.php'), @file_get_contents($p->root . '/vendor/magento/magento2-base/pub/old.php'), file_exists($p->root . '/pub/new.php')],
        'with nothing written'
    );

    Assert::case('a missing copy of a root file: the other copy is patched, and the chain carries on');
    // A store without its served nginx.conf.sample used to refuse the month
    // that patches it, and every month built on it was blocked behind it.
    $nginxHunk = "--- a/nginx.conf.sample\n+++ b/nginx.conf.sample\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n";
    $chainStore = static function (string $name, bool $served, string $packageCopy, array $rootExtra = []) use ($sandbox, $meta, $nginxHunk): Project {
        $p = (new Project($sandbox, $name))
            ->root(array_merge($meta(['trust' => ['acme/*']]), $rootExtra))
            ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
            ->package('magento/module-present', [])
            ->package('acme/patches', $meta(['lines' => ['L' => [
                'base' => ['acme/patches' => '*'],
                'cumulative' => true,
                'patches' => [['id' => 'M-1', 'source' => 'm1.patch'], ['id' => 'M-2', 'source' => 'm2.patch']],
            ]]]))
            ->write('vendor/acme/patches/m1.patch', $nginxHunk . "--- a/vendor/magento/module-present/a.php\n+++ b/vendor/magento/module-present/a.php\n@@ -1 +1,2 @@\n one\n+two\n")
            ->write('vendor/acme/patches/m2.patch', "--- a/vendor/magento/module-present/b.php\n+++ b/vendor/magento/module-present/b.php\n@@ -1 +1,2 @@\n one\n+two\n")
            ->write('vendor/magento/module-present/a.php', "one\n")
            ->write('vendor/magento/module-present/b.php', "one\n")
            ->write('vendor/magento/magento2-base/nginx.conf.sample', $packageCopy);

        return $served ? $p->write('nginx.conf.sample', $packageCopy) : $p;
    };
    $notices = static function (Patcher $patcher): string {
        return implode("\n", array_column($patcher->advisories(), 'message'));
    };

    $p = $chainStore('missing-served-control', true, "1\n2\n3\n");
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'control: with both copies, both months apply');

    foreach (['already patched' => "1\n2a\n3\n", 'unpatched' => "1\n2\n3\n"] as $how => $packageCopy) {
        $p = $chainStore('missing-served-' . md5($how), false, $packageCopy);
        $patcher = $p->patcher();
        $results = $patcher->run(true);
        Assert::same(Renderer::OK, Renderer::exitCode($results), $how . ': the apply succeeds');
        Assert::same("1\n2a\n3\n", (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'), $how . ': the package copy, the next deploy\'s source, carries the patch');
        Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/vendor/magento/module-present/b.php'), $how . ': and the month built on it is not blocked');
        Assert::false(file_exists($p->root . '/nginx.conf.sample'), $how . ': the served copy is not conjured up');
        Assert::contains($notices($patcher), 'composer reinstall magento/magento2-base', $how . ': a warning says how to put it back');
        $patcher = $p->patcher();
        Assert::same(Renderer::OK, Renderer::exitCode($patcher->run(false)), $how . ': verify passes, as the patch is where it can be');
        Assert::contains($notices($patcher), 'nginx.conf.sample', $how . ': and still warns, naming the file');
    }

    $p = $chainStore('missing-served-ignored', false, "1\n2\n3\n", ['magento-deploy-ignore' => ['magento/magento2-base' => ['/nginx.conf.sample']]]);
    $patcher = $p->patcher();
    Assert::same(Renderer::OK, Renderer::exitCode($patcher->run(true)), 'control: a file the store has the installer ignore can be absent');
    Assert::same("1\n2\n3\n", (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'), 'control: and its package copy is left alone');
    Assert::false(strpos($notices($patcher), 'composer reinstall') !== false, 'control: without a warning');

    foreach (['root path' => 'pub/errors/processor.php', 'vendor path' => 'vendor/magento/magento2-base/pub/errors/processor.php'] as $how => $target) {
        // The other way round: magento2-base's copy is gone and the served one is not.
        $p = (new Project($sandbox, 'missing-package-' . md5($how)))
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->package('magento/magento2-base', ['map' => [['pub/errors', 'pub/errors']]], [], 'magento2-component')
            ->write('pub/errors/processor.php', "<?php\n\$a = 1;\n")
            ->write('vendor/magento/magento2-base/pub/errors/other.php', "x\n")
            ->write('own.patch', "--- a/$target\n+++ b/$target\n@@ -1,2 +1,2 @@\n <?php\n-\$a = 1;\n+\$a = 2;\n");
        Assert::same(Renderer::UNAPPLIED, Renderer::exitCode($p->patcher()->run(false)), $how . ': verify does not pass an unpatched served file');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $how . ': an apply patches it');
        Assert::same("<?php\n\$a = 2;\n", (string) file_get_contents($p->root . '/pub/errors/processor.php'), $how . ': the served copy');
    }

    Assert::case('a file two months create, in a module this store does not install');
    // The later creator is not installed here, so it never lands, and the
    // earlier one was flipped to "the patch that replaces this one did not
    // apply": exit 1 on every apply, while verify said 0.
    $create = static function (string $module, string $body): string {
        return "diff --git a/vendor/magento/$module/New.php b/vendor/magento/$module/New.php\nnew file mode 100644\n"
            . "--- /dev/null\n+++ b/vendor/magento/$module/New.php\n@@ -0,0 +1 @@\n+$body\n";
    };
    foreach (['module-present' => true, 'module-absent' => false] as $module => $installed) {
        $p = (new Project($sandbox, 'supersede-' . $module))
            ->root($meta(['trust' => ['acme/*']]))
            ->package('magento/module-present', [])
            ->package('acme/patches', $meta(['lines' => ['L' => [
                'base' => ['acme/patches' => '*'],
                'cumulative' => true,
                'patches' => [['id' => 'S-1', 'source' => 's1.patch'], ['id' => 'S-2', 'source' => 's2.patch']],
            ]]]))
            ->write('vendor/acme/patches/s1.patch', "--- a/vendor/magento/module-present/a.php\n+++ b/vendor/magento/module-present/a.php\n@@ -1 +1,2 @@\n one\n+two\n" . $create($module, 'v1'))
            ->write('vendor/acme/patches/s2.patch', "--- a/vendor/magento/module-present/b.php\n+++ b/vendor/magento/module-present/b.php\n@@ -1 +1,2 @@\n one\n+two\n" . $create($module, 'v2'))
            ->write('vendor/magento/module-present/a.php', "one\n")
            ->write('vendor/magento/module-present/b.php', "one\n");
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $module . ': the apply succeeds');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $module . ': and the next one');
        Assert::same(
            $installed ? "v2\n" : '',
            (string) @file_get_contents($p->root . "/vendor/magento/$module/New.php"),
            $module . ($installed ? ': the later version is there' : ': nothing is written into it')
        );
    }

    Assert::case('an untrusted map cannot reach into another package under vendor/');
    $untrusted = static function (string $name, ?array $map, string $decoy = "one\n") use ($sandbox, $meta): Project {
        $p = (new Project($sandbox, $name))
            ->root($meta(['trust' => ['acme/*']]))
            ->package('magento/module-present', [])
            ->package('acme/patches', $meta(['lines' => ['L' => [
                'base' => ['acme/patches' => '*'],
                'cumulative' => true,
                'patches' => [['id' => 'M-1', 'source' => 'm1.patch'], ['id' => 'M-2', 'source' => 'm2.patch']],
            ]]]))
            ->write('vendor/acme/patches/m1.patch', "--- a/vendor/magento/module-absent/Missing.php\n+++ b/vendor/magento/module-absent/Missing.php\n@@ -1 +1,2 @@\n one\n+two\n--- a/vendor/magento/module-present/a.php\n+++ b/vendor/magento/module-present/a.php\n@@ -1 +1,2 @@\n one\n+two\n")
            ->write('vendor/acme/patches/m2.patch', "--- a/vendor/magento/module-present/b.php\n+++ b/vendor/magento/module-present/b.php\n@@ -1 +1,2 @@\n one\n+two\n")
            ->write('vendor/magento/module-present/a.php', "one\n")
            ->write('vendor/magento/module-present/b.php', "one\n");

        return $map === null ? $p : $p->package('zzz/widget', ['map' => $map], [], 'magento2-component')->write('vendor/zzz/widget/decoy.php', $decoy);
    };
    Assert::same(Renderer::OK, Renderer::exitCode($untrusted('untrusted-none', null)->patcher()->run(true)), 'control: no other package, both months apply');
    $p = $untrusted('untrusted-absent', [['decoy.php', 'vendor/magento/module-absent/Missing.php']]);
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'a map onto a module file the store lacks changes nothing');
    Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/vendor/magento/module-present/b.php'), 'and the chain applies');
    $p = $untrusted('untrusted-diverged', [['decoy.php', 'vendor/magento/module-present/a.php']], "zzz\n");
    Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), 'a diverged decoy mapped onto a module file blocks nothing');
    Assert::same("zzz\n", (string) file_get_contents($p->root . '/vendor/zzz/widget/decoy.php'), 'and is not written');

    foreach (['no other package' => false, 'an untrusted map onto the vendor path' => true] as $how => $evil) {
        // magento2-base from a path repository: the install path is the link's target.
        $target = 'vendor/magento/magento2-base/nginx.conf.sample';
        $p = (new Project($sandbox, 'path-repo-' . md5($how)))
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->write('packages/base/nginx.conf.sample', "1\n2\n3\n")
            ->write('nginx.conf.sample', "1\n2\n3\n")
            ->write('own.patch', "--- a/$target\n+++ b/$target\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n");
        mkdir($p->root . '/vendor/magento', 0777, true);
        symlink('../../packages/base', $p->root . '/vendor/magento/magento2-base');
        $p->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component');
        if ($evil) {
            $p->package('zzz/widget', ['map' => [['decoy', 'vendor/magento/magento2-base/nginx.conf.sample']]], [], 'magento2-component')
                ->write('vendor/zzz/widget/decoy', "1\n2\n3\n");
        }
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $how . ': applies');
        Assert::same("1\n2a\n3\n", (string) file_get_contents($p->root . '/nginx.conf.sample'), $how . ': the served copy is patched');
        Assert::same("1\n2a\n3\n", (string) file_get_contents($p->root . '/packages/base/nginx.conf.sample'), $how . ': and the package copy');
    }

    Assert::case('a map with a control character in it is not a mapping');
    // A newline in a path would be written into a diff header unquoted.
    $p = (new Project($sandbox, 'map-newline'))->write("vendor/x/y/odd\nname", "1\n")->write('vendor/x/y/plain', "1\n");
    $resolver = new TargetResolver($p->root, [['package' => 'x/y', 'install' => 'vendor/x/y', 'map' => [
        ["odd\nname", 'served-odd', false],
        ['plain', "served\nplain", false],
        ['plain', 'served-plain', false],
    ]]]);
    Assert::same(null, $resolver->mirrorFor('served-odd'), 'not from a source with a newline');
    Assert::same(null, $resolver->mirrorFor("served\nplain"), 'nor to a destination with one');
    Assert::same('vendor/x/y/plain', $resolver->mirrorFor('served-plain'), 'control: a plain mapping still is one');

    Assert::case('a mode change reaches the other copy of a root file');
    $modeStore = static function (string $name, string $patch, string $packageCopy) use ($sandbox, $meta): Project {
        $p = (new Project($sandbox, $name))
            ->root($meta(['trust' => ['acme/*']]))
            ->package('magento/magento2-base', ['map' => [['bin/magento', 'bin/magento']]], [], 'magento2-component')
            ->package('acme/patches', $meta(['patches' => [['id' => 'P-1', 'source' => 'p.patch']]]))
            ->write('vendor/acme/patches/p.patch', $patch)
            ->write('vendor/magento/magento2-base/bin/magento', $packageCopy)
            ->write('bin/magento', "1\n2\n3\n");
        chmod($p->root . '/vendor/magento/magento2-base/bin/magento', 0644);
        chmod($p->root . '/bin/magento', 0644);

        return $p;
    };
    $permissions = static function (Project $p, string $path): string {
        clearstatcache();

        return sprintf('%o', fileperms($p->root . '/' . $path) & 0777);
    };
    $modeOnly = "diff --git a/bin/magento b/bin/magento\nold mode 100644\nnew mode 100755\n";
    $modeAndContent = $modeOnly . "--- a/bin/magento\n+++ b/bin/magento\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n";
    foreach (['mode only, copies in step' => [$modeOnly, "1\n2\n3\n"], 'mode and content, copies in step' => [$modeAndContent, "1\n2\n3\n"], 'control: diverged copies' => [$modeAndContent, "1\n2\n3\n# own\n"]] as $how => [$patch, $packageCopy]) {
        $p = $modeStore('mode-' . md5($how), $patch, $packageCopy);
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $how . ': applies');
        Assert::same(['755', '755'], [$permissions($p, 'bin/magento'), $permissions($p, 'vendor/magento/magento2-base/bin/magento')], $how . ': both copies executable');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(false)), $how . ': and verify agrees');
    }

    Assert::case('a skipped mode change that is not there is skipped, not unwanted');
    $p = (new Project($sandbox, 'skip-mode-absent'))
        ->root($meta(['trust' => ['acme/*'], 'sources' => ['acme/patches' => ['skip' => ['M-1' => 'no']]]]))
        ->package('acme/patches', $meta(['patches' => [['id' => 'M-1', 'source' => 'm.patch']]]))
        ->write('vendor/acme/patches/m.patch', "diff --git a/bin/run b/bin/run\nold mode 100644\nnew mode 100755\n")
        ->write('bin/run', "a\n");
    chmod($p->root . '/bin/run', 0644);
    Assert::same(Fragment::SKIPPED, $p->patcher()->run(false)[0]['fragments'][0]->verdict(), 'status says it is not there');
    Assert::same(Fragment::SKIPPED, $p->patcher()->run(true)[0]['fragments'][0]->verdict(), 'and apply has nothing to take off');
    chmod($p->root . '/bin/run', 0755);
    Assert::same(Fragment::UNWANTED, $p->patcher()->run(false)[0]['fragments'][0]->verdict(), 'control: with the mode there, it is still present');

    Assert::case('a diverged other copy reached through a symlinked directory');
    $vendorProcessor = 'vendor/magento/magento2-base/pub/errors/processor.php';
    // A hunk at line 1 with no trailing context has to match the whole file,
    // so this one carries a line after the change.
    foreach (['in step' => "<?php\n\$a = 1;\n\$b = 1;\n", 'diverged' => "<?php\n\$a = 1;\n\$b = 1;\n// ours\n"] as $how => $served) {
        $p = (new Project($sandbox, 'through-link-' . md5($how)))
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->package('magento/magento2-base', ['map' => [['pub/errors', 'pub/errors']]], [], 'magento2-component')
            ->write($vendorProcessor, "<?php\n\$a = 1;\n\$b = 1;\n")
            ->write('docroot/errors/processor.php', $served)
            ->write('own.patch', "--- a/$vendorProcessor\n+++ b/$vendorProcessor\n@@ -1,3 +1,3 @@\n <?php\n-\$a = 1;\n+\$a = 2;\n \$b = 1;\n");
        mkdir($p->root . '/pub');
        symlink('../docroot/errors', $p->root . '/pub/errors');
        Assert::same(Renderer::OK, Renderer::exitCode($p->patcher()->run(true)), $how . ': applies');
        Assert::contains((string) file_get_contents($p->root . '/docroot/errors/processor.php'), '$a = 2;', $how . ': to the served copy');
    }
},

'other-copy-missing' => static function (Sandbox $sandbox): void {
    $meta = static function (array $extra): array {
        return ['magento-patches' => $extra];
    };
    foreach (['root path' => 'nginx.conf.sample', 'vendor path' => 'vendor/magento/magento2-base/nginx.conf.sample'] as $how => $target) {
        Assert::case('served root file missing, patch names the ' . $how);
        $p = (new Project($sandbox, 'miss-' . md5($how)))
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
            ->write('vendor/magento/magento2-base/nginx.conf.sample', "1\n2\n3\n")
            ->write('own.patch', "--- a/$target\n+++ b/$target\n@@ -1,3 +1,3 @@\n 1\n-2\n+2a\n 3\n");
        $patcher = $p->patcher();
        $r = $patcher->run(true);
        Assert::same(Renderer::OK, Renderer::exitCode($r), $how . ': applies');
        Assert::same("1\n2a\n3\n", (string) file_get_contents($p->root . '/vendor/magento/magento2-base/nginx.conf.sample'), $how . ': package copy patched');
        Assert::contains(implode("\n", array_column($patcher->advisories(), 'message')), 'composer reinstall magento/magento2-base', $how . ': warns that the served copy is missing');
    }
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

'vendor-namespace' => static function (Sandbox $sandbox): void {
    $meta = static function (array $extra): array {
        return ['magento-patches' => $extra];
    };
    Assert::case('an untrusted map onto a whole vendor namespace directory');
    foreach (['control: no map' => null, 'map dest vendor/magento (dir)' => [['stuff', 'vendor/magento']]] as $how => $map) {
      foreach ([false, true] as $withBase) {
        $how2 = $how . ($withBase ? ' +magento2-base' : '');
        $p = (new Project($sandbox, 'vdir-' . md5($how2)))
            ->root($meta(['trust' => ['acme/*']]))
            ->package('magento/module-catalog', [], [], 'magento2-module')
            ->package('acme/patches', $meta(['patches' => [['id' => 'SEC-1', 'source' => 's.patch', 'base' => ['acme/patches' => '*']]]]))
            ->write('vendor/acme/patches/s.patch', "--- a/vendor/magento/module-catalog/a.php\n+++ b/vendor/magento/module-catalog/a.php\n@@ -1 +1,2 @@\n one\n+two\n")
            ->write('vendor/magento/module-catalog/a.php', "one\n");
        if ($withBase) {
            $p->package('magento/magento2-base', ['map' => [['nginx.conf.sample', 'nginx.conf.sample']]], [], 'magento2-component')
                ->write('vendor/magento/magento2-base/nginx.conf.sample', "x\n");
        }
        if ($map !== null) {
            $p->package('zzz/widget', ['map' => $map], [], 'magento2-component')
                ->write('vendor/zzz/widget/stuff/module-catalog/a.php', "diverged\n");
        }
        $r = $p->patcher()->run(true);
        $o = [];
        foreach ($r as $x) { foreach ($x['fragments'] as $f) { $o[] = $f->verdict() . ' ' . $f->reason(); } }
        Assert::same(Renderer::OK, Renderer::exitCode($r), $how2 . ': the trusted patch applies ' . implode(' | ', $o));
      }
    }
},

'mode-only' => static function (Sandbox $sandbox): void {
    $meta = static function (array $extra): array {
        return ['magento-patches' => $extra];
    };
    Assert::case('content in place, mode not (a copy deploy drops the executable bit)');
    $diff = "diff --git a/bin/tool b/bin/tool\nold mode 100644\nnew mode 100755\n--- a/bin/tool\n+++ b/bin/tool\n@@ -1 +1,2 @@\n one\n+two\n";
    foreach (['control: neither in place' => "one\n", 'content in place, mode not' => "one\ntwo\n"] as $how => $contents) {
        $p = (new Project($sandbox, 'mode-' . md5($how)))
            ->root($meta(['patches' => [['id' => 'OWN-1', 'source' => 'own.patch']]]))
            ->write('own.patch', $diff)->write('bin/tool', $contents);
        chmod($p->root . '/bin/tool', 0644);
        $r = $p->patcher()->run(true);
        $o = [];
        foreach ($r as $x) { foreach ($x['fragments'] as $f) { $o[] = $f->verdict() . ' ' . $f->reason(); } }
        Assert::same(Renderer::OK, Renderer::exitCode($r), $how . ': apply ends OK ' . implode(' | ', $o));
        clearstatcache();
        Assert::same(0755, fileperms($p->root . '/bin/tool') & 0777, $how . ': executable');
    }
},

];
