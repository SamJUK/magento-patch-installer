<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller\Tests;

use SamJUK\MagentoPatchInstaller\Fragment;
use SamJUK\MagentoPatchInstaller\Renderer;

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
    // patches:list never runs runOne(), so it never saw an empty or truncated
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
    // this was a TypeError out of patches:list — and patches:list is the one
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
},

];
