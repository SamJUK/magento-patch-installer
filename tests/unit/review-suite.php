<?php

// Copyright (c) 2026 Sam James. Licensed under the Open Software License version 3.0.
// SPDX-License-Identifier: OSL-3.0

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller\Tests;

// Regression cases from the review rounds: offsets, cycles through require, modes.

use SamJUK\MagentoPatchInstaller\Renderer;

$meta = static function (array $extra): array {
    return ['magento-patches' => $extra];
};
$patchFor = static function (string $file): string {
    return "--- a/$file\n+++ b/$file\n@@ -1 +1,2 @@\n one\n+two\n";
};
$summary = static function (array $results): string {
    $o = [];
    foreach ($results as $r) {
        $v = [];
        foreach ($r['fragments'] as $f) { $v[] = $f->verdict(); }
        $o[] = $r['owner'] . ':' . $r['id'] . '=' . (!empty($r['blocked']) ? 'BLOCKED(' . $r['reason'] . ')' : implode(',', $v));
    }
    return implode(' | ', $o);
};

$why = static function (array $r): string {
    $o = [];
    foreach ($r as $x) {
        if (!empty($x['blocked'])) { $o[] = $x['id'] . ' BLOCKED ' . $x['reason']; continue; }
        foreach ($x['fragments'] as $f) { $o[] = $x['id'] . ' ' . $f->verdict() . ' ' . $f->reason(); }
    }
    return implode(' | ', $o);
};

return [

'cycle3' => static function (Sandbox $sandbox) use ($meta, $patchFor, $summary): void {
    // base requires lib. lib's patch depends on other's, other's depends on base's.
    $orders = [
        'base,lib,other' => ['base', 'lib', 'other'],
        'other,base,lib' => ['other', 'base', 'lib'],
        'lib,other,base' => ['lib', 'other', 'base'],
        'other,lib,base' => ['other', 'lib', 'base'],
    ];
    foreach ([true, false] as $contradiction) {
        foreach ($orders as $how => $order) {
            $name = ($contradiction ? 'c3-' : 'c3ctl-') . md5($how);
            Assert::case(($contradiction ? '' : 'control ') . '3-node depends against require, order ' . $how);
            $p = (new Project($sandbox, $name))->root($meta(['trust' => ['vendor/*']]));
            $defs = [
                'base' => [$meta(['patches' => [['id' => 'SEC-1', 'source' => 'a.patch', 'base' => ['vendor/base' => '*']]]]), ['vendor/lib']],
                'lib' => [$meta(['patches' => [['id' => 'LIB-1', 'source' => 'b.patch', 'base' => ['vendor/base' => '*'], 'depends' => $contradiction ? ['vendor/other:OTH-1'] : []]]]), []],
                'other' => [$meta(['patches' => [['id' => 'OTH-1', 'source' => 'c.patch', 'base' => ['vendor/base' => '*'], 'depends' => ['vendor/base:SEC-1']]]]), []],
            ];
            foreach ($order as $pkg) {
                $p->package('vendor/' . $pkg, $defs[$pkg][0], $defs[$pkg][1]);
            }
            $p->write('vendor/vendor/base/a.patch', $patchFor('a.txt'))
                ->write('vendor/vendor/lib/b.patch', $patchFor('b.txt'))
                ->write('vendor/vendor/other/c.patch', $patchFor('c.txt'))
                ->write('a.txt', "one\n")->write('b.txt', "one\n")->write('c.txt', "one\n");
            $patcher = $p->patcher();
            $r = $patcher->run(true);
            Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/a.txt'), 'SEC-1 applied: ' . $summary($r) . ' errors=' . implode(' / ', $patcher->errors()));
            Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/c.txt'), 'OTH-1 applied: ' . $summary($r));

            if ($contradiction) {
                $errors = implode(' / ', $patcher->errors());
                Assert::true(strpos($errors, '"LIB-1" depends on "OTH-1", which leads to "SEC-1"') !== false, 'the error names the depends LIB-1 declares: ' . $errors);
            }
        }
    }
},

'cycle3-root' => static function (Sandbox $sandbox) use ($meta, $patchFor, $summary): void {
    // The store's own patch builds on base's SEC-1; lib (required by base) depends on the store's patch.
    foreach ([true, false] as $contradiction) {
        foreach (['base,lib' => ['base', 'lib'], 'lib,base' => ['lib', 'base']] as $how => $order) {
            Assert::case(($contradiction ? '' : 'control ') . 'root in the middle, order ' . $how);
            $p = (new Project($sandbox, ($contradiction ? 'c3r-' : 'c3rctl-') . md5($how)))
                ->root($meta(['trust' => ['vendor/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'base' => ['vendor/base' => '*'], 'depends' => ['vendor/base:SEC-1']]]]));
            $defs = [
                'base' => [$meta(['patches' => [['id' => 'SEC-1', 'source' => 'a.patch', 'base' => ['vendor/base' => '*']]]]), ['vendor/lib']],
                'lib' => [$meta(['patches' => [['id' => 'LIB-1', 'source' => 'b.patch', 'base' => ['vendor/base' => '*'], 'depends' => $contradiction ? ['project/root:OWN-1'] : []]]]), []],
            ];
            foreach ($order as $pkg) {
                $p->package('vendor/' . $pkg, $defs[$pkg][0], $defs[$pkg][1]);
            }
            $p->write('vendor/vendor/base/a.patch', $patchFor('a.txt'))
                ->write('vendor/vendor/lib/b.patch', $patchFor('b.txt'))
                ->write('own.patch', $patchFor('c.txt'))
                ->write('a.txt', "one\n")->write('b.txt', "one\n")->write('c.txt', "one\n");
            $patcher = $p->patcher();
            $r = $patcher->run(true);
            Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/a.txt'), 'SEC-1 applied: ' . $summary($r) . ' errors=' . implode(' / ', $patcher->errors()));
            Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/c.txt'), 'OWN-1 applied: ' . $summary($r));
        }
    }
},

'transitive-against' => static function (Sandbox $sandbox) use ($meta, $patchFor, $summary): void {
    // base requires lib, lib requires z. z's patch depends on base's (against the require). lib's patch is innocent.
    $orders = ['base,lib,z' => ['base', 'lib', 'z'], 'lib,base,z' => ['lib', 'base', 'z'], 'z,lib,base' => ['z', 'lib', 'base'], 'lib,z,base' => ['lib', 'z', 'base'], 'base,z,lib' => ['base', 'z', 'lib'], 'z,base,lib' => ['z', 'base', 'lib']];
    foreach ([true, false] as $contradiction) {
        foreach ($orders as $how => $order) {
            Assert::case(($contradiction ? '' : 'control ') . 'transitive require, order ' . $how);
            $p = (new Project($sandbox, ($contradiction ? 'ta-' : 'tactl-') . md5($how)))->root($meta(['trust' => ['vendor/*']]));
            $defs = [
                'base' => [$meta(['patches' => [['id' => 'SEC-1', 'source' => 'a.patch', 'base' => ['vendor/base' => '*']]]]), ['vendor/lib']],
                'lib' => [$meta(['patches' => [['id' => 'LIB-1', 'source' => 'b.patch', 'base' => ['vendor/base' => '*']]]]), ['vendor/z']],
                'z' => [$meta(['patches' => [['id' => 'Z-1', 'source' => 'c.patch', 'base' => ['vendor/base' => '*'], 'depends' => $contradiction ? ['vendor/base:SEC-1'] : []]]]), []],
            ];
            foreach ($order as $pkg) {
                $p->package('vendor/' . $pkg, $defs[$pkg][0], $defs[$pkg][1]);
            }
            $p->write('vendor/vendor/base/a.patch', $patchFor('a.txt'))
                ->write('vendor/vendor/lib/b.patch', $patchFor('b.txt'))
                ->write('vendor/vendor/z/c.patch', $patchFor('c.txt'))
                ->write('a.txt', "one\n")->write('b.txt', "one\n")->write('c.txt', "one\n");
            $patcher = $p->patcher();
            $r = $patcher->run(true);
            $s = $summary($r) . ' errors=' . implode(' / ', $patcher->errors());
            Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/a.txt'), 'SEC-1 applied: ' . $s);
            Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/b.txt'), 'LIB-1 applied: ' . $s);
        }
    }
},

'r5-cycle-with-order-depends' => static function (Sandbox $sandbox) use ($meta, $why): void {
    // m requires i requires j requires k. I-1 depends on J-1 (with the require). K-1 depends on M-1 (against it).
    $perms = static function (array $xs) use (&$perms): array {
        if (count($xs) <= 1) { return [$xs]; }
        $out = [];
        foreach ($xs as $k => $x) { $rest = $xs; unset($rest[$k]); foreach ($perms(array_values($rest)) as $p) { $out[] = array_merge([$x], $p); } }
        return $out;
    };
    $patchFor = static function (string $file): string { return "--- a/$file\n+++ b/$file\n@@ -1 +1,2 @@\n one\n+two\n"; };
    $bad = [];
    foreach ([true, false] as $contradiction) {
        foreach ($perms(['m', 'i', 'j', 'k']) as $order) {
            $how = implode(',', $order);
            $p = (new Project($sandbox, ($contradiction ? 'wo-' : 'woctl-') . md5($how)))->root($meta(['trust' => ['vendor/*']]));
            $defs = [
                'm' => [$meta(['patches' => [['id' => 'M-1', 'source' => 'p.patch', 'base' => ['vendor/m' => '*']]]]), ['vendor/i']],
                'i' => [$meta(['patches' => [['id' => 'I-1', 'source' => 'p.patch', 'base' => ['vendor/m' => '*'], 'depends' => ['vendor/j:J-1']]]]), ['vendor/j']],
                'j' => [$meta(['patches' => [['id' => 'J-1', 'source' => 'p.patch', 'base' => ['vendor/m' => '*']]]]), ['vendor/k']],
                'k' => [$meta(['patches' => [['id' => 'K-1', 'source' => 'p.patch', 'base' => ['vendor/m' => '*'], 'depends' => $contradiction ? ['vendor/m:M-1'] : []]]]), []],
            ];
            foreach ($order as $pkg) {
                $p->package('vendor/' . $pkg, $defs[$pkg][0], $defs[$pkg][1]);
                $p->write('vendor/vendor/' . $pkg . '/p.patch', $patchFor($pkg . '.txt'))->write($pkg . '.txt', "one\n");
            }
            $patcher = $p->patcher();
            $r = $patcher->run(true);
            $got = [];
            foreach (['m', 'i', 'j', 'k'] as $pkg) { $got[$pkg] = file_get_contents($p->root . '/' . $pkg . '.txt') === "one\ntwo\n" ? 'Y' : 'n'; }
            $want = ['m' => 'Y', 'i' => 'Y', 'j' => 'Y', 'k' => $contradiction ? 'n' : 'Y'];
            if ($got !== $want) { $bad[] = ($contradiction ? '' : 'CONTROL ') . $how . ' got ' . json_encode($got) . ' errors=' . implode(' / ', $patcher->errors()); }
        }
    }
    Assert::case('I-1 depends with the require; K-1 against it');
    Assert::same([], $bad, 'only K-1 refused, every order');
},

'r5-cycle-root-requires' => static function (Sandbox $sandbox) use ($meta, $why): void {
    // Store's OWN-1 depends on base:SEC-1. base requires lib. lib's LIB-1 depends on x:X-1. X-1 depends on project/root:OWN-1.
    $patchFor = static function (string $file): string { return "--- a/$file\n+++ b/$file\n@@ -1 +1,2 @@\n one\n+two\n"; };
    foreach (['control: root requires nothing (as the fixture)' => [], 'root requires base and x, as a real root does' => ['vendor/base', 'vendor/x']] as $how => $rootReq) {
        foreach (['base,lib,x' => ['base', 'lib', 'x'], 'x,lib,base' => ['x', 'lib', 'base'], 'lib,x,base' => ['lib', 'x', 'base']] as $o => $order) {
            Assert::case($how . ', order ' . $o);
            $p = (new Project($sandbox, 'crr-' . md5($how . $o)))
                ->root($meta(['trust' => ['vendor/*'], 'patches' => [['id' => 'OWN-1', 'source' => 'own.patch', 'base' => ['vendor/base' => '*'], 'depends' => ['vendor/base:SEC-1']]]]));
            $p->rootRequires = $rootReq;
            $defs = [
                'base' => [$meta(['patches' => [['id' => 'SEC-1', 'source' => 'p.patch', 'base' => ['vendor/base' => '*']]]]), ['vendor/lib']],
                'lib' => [$meta(['patches' => [['id' => 'LIB-1', 'source' => 'p.patch', 'base' => ['vendor/base' => '*'], 'depends' => ['vendor/x:X-1']]]]), []],
                'x' => [$meta(['patches' => [['id' => 'X-1', 'source' => 'p.patch', 'base' => ['vendor/base' => '*'], 'depends' => ['project/root:OWN-1']]]]), []],
            ];
            foreach ($order as $pkg) {
                $p->package('vendor/' . $pkg, $defs[$pkg][0], $defs[$pkg][1]);
                $p->write('vendor/vendor/' . $pkg . '/p.patch', $patchFor($pkg . '.txt'))->write($pkg . '.txt', "one\n");
            }
            $p->write('own.patch', $patchFor('own.txt'))->write('own.txt', "one\n");
            $patcher = $p->patcher();
            $r = $patcher->run(true);
            $got = [];
            foreach (['base', 'own', 'x', 'lib'] as $f) { $got[$f] = file_get_contents($p->root . '/' . $f . '.txt') === "one\ntwo\n" ? 'Y' : 'n'; }
            Assert::same(['base' => 'Y', 'own' => 'Y', 'x' => 'Y', 'lib' => 'n'], $got, 'only LIB-1 refused: ' . $why($r) . ' errors=' . implode(' / ', $patcher->errors()));
        }
    }
},

'r5-cycle-shared-backedge' => static function (Sandbox $sandbox) use ($meta, $why): void {
    // py requires pz. V-1 depends on Y-1, X-1. Z-1 depends on U-1. U-1 depends on V-1. X-1 depends on U-1.
    // V -> X -> U -> V is a cycle of depends alone; V -> Y -req-> Z -> U -> V shares its back edge.
    $patchFor = static function (string $file): string { return "--- a/$file\n+++ b/$file\n@@ -1 +1,2 @@\n one\n+two\n"; };
    foreach (['py requires pz' => ['vendor/z'], 'control: no require' => []] as $how => $pyReq) {
        Assert::case($how);
        $p = (new Project($sandbox, 'sbe-' . md5($how)))->root($meta(['trust' => ['vendor/*']]));
        $defs = [
            'v' => [$meta(['patches' => [['id' => 'V-1', 'source' => 'p.patch', 'depends' => ['vendor/y:Y-1', 'vendor/x:X-1']]]]), []],
            'y' => [$meta(['patches' => [['id' => 'Y-1', 'source' => 'p.patch']]]), $pyReq],
            'z' => [$meta(['patches' => [['id' => 'Z-1', 'source' => 'p.patch', 'depends' => ['vendor/u:U-1']]]]), []],
            'u' => [$meta(['patches' => [['id' => 'U-1', 'source' => 'p.patch', 'depends' => ['vendor/v:V-1']]]]), []],
            'x' => [$meta(['patches' => [['id' => 'X-1', 'source' => 'p.patch', 'depends' => ['vendor/u:U-1']]]]), []],
        ];
        foreach ($defs as $pkg => [$extra, $req]) {
            $p->package('vendor/' . $pkg, $extra, $req);
            $p->write('vendor/vendor/' . $pkg . '/p.patch', $patchFor($pkg . '.txt'))->write($pkg . '.txt', "one\n");
        }
        $patcher = $p->patcher();
        $r = $patcher->run(true);
        $got = [];
        foreach (['v', 'x', 'u'] as $f) { $got[$f] = file_get_contents($p->root . '/' . $f . '.txt') === "one\ntwo\n" ? 'Y' : 'n'; }
        Assert::same(['v' => 'n', 'x' => 'n', 'u' => 'n'], $got, $how . ': the depends-only cycle V,X,U is refused: order=' . implode(',', array_map(function ($x) { return $x['id']; }, $r)) . ' ' . $why($r) . ' errors=' . implode(' / ', $patcher->errors()));
    }
},

'r6-refused-enters-its-package' => static function (Sandbox $sandbox) use ($meta, $patchFor, $why): void {
    // top requires lib, addon requires top, y requires z. T-1 depends on addon's
    // A-1, against the require, so T-1 is refused. Z-1 is built on T-1, and the
    // walk into T-1's package reached it through L-1 -> Y-1 -> z and placed it
    // first, where nothing yet said T-1 was refused.
    $cases = [
        'top first' => [['top', 'lib', 'y', 'z', 'addon'], true],
        'control: addon first' => [['addon', 'z', 'y', 'lib', 'top'], true],
        'control: L-1 not built on Y-1' => [['top', 'lib', 'y', 'z', 'addon'], false],
    ];
    foreach ($cases as $how => [$order, $path]) {
        Assert::case($how);
        $defs = [
            'top' => [['vendor/lib'], [['T-1', ['vendor/addon:A-1']]]],
            'lib' => [[], [['L-1', $path ? ['vendor/y:Y-1'] : []]]],
            'y' => [['vendor/z'], [['Y-1', []]]],
            'z' => [[], [['Z-1', ['vendor/top:T-1']]]],
            'addon' => [['vendor/top'], [['A-1', []]]],
        ];
        $p = (new Project($sandbox, 'r6-rep-' . md5($how)))->root($meta(['trust' => ['vendor/*']]));
        foreach ($order as $pkg) {
            [$requires, $patches] = $defs[$pkg];
            $list = [];
            foreach ($patches as [$id, $depends]) {
                $list[] = ['id' => $id, 'source' => $id . '.patch', 'depends' => $depends];
                $p->write('vendor/vendor/' . $pkg . '/' . $id . '.patch', $patchFor($id . '.txt'))->write($id . '.txt', "one\n");
            }
            $p->package('vendor/' . $pkg, $meta(['patches' => $list]), $requires);
        }
        $r = $p->patcher()->run(true);
        Assert::same("one\n", (string) file_get_contents($p->root . '/T-1.txt'), 'T-1 refused: ' . $why($r));
        Assert::same("one\n", (string) file_get_contents($p->root . '/Z-1.txt'), 'Z-1, built on T-1, goes with it: ' . $why($r));
        Assert::same("one\ntwo\n", (string) file_get_contents($p->root . '/A-1.txt'), 'A-1 applied: ' . $why($r));
    }
},

'r6-no-depends-refused' => static function (Sandbox $sandbox) use ($meta, $patchFor, $why): void {
    // Cycles through requires where no depends runs against one. Only the
    // patches declaring a depends in the loop are refused: the others are
    // there through their package, and did nothing.
    $shapes = [
        // top requires lib, y requires z. L-1 depends on Y-1, Z-1 on T-1.
        'two hops' => [[
            'top' => [['vendor/lib'], [['T-1', []]]],
            'lib' => [[], [['L-1', ['vendor/y:Y-1']]]],
            'y' => [['vendor/z'], [['Y-1', []]]],
            'z' => [[], [['Z-1', ['vendor/top:T-1']]]],
        ], ['L-1', 'Z-1']],
        'control: two hops, L-1 not built on Y-1' => [[
            'top' => [['vendor/lib'], [['T-1', []]]],
            'lib' => [[], [['L-1', []]]],
            'y' => [['vendor/z'], [['Y-1', []]]],
            'z' => [[], [['Z-1', ['vendor/top:T-1']]]],
        ], []],
        // b requires c, d requires a. A-1 depends on B-1, C-1 on D-1.
        'four packages' => [[
            'a' => [[], [['A-1', ['vendor/b:B-1']]]],
            'b' => [['vendor/c'], [['B-1', []]]],
            'c' => [[], [['C-1', ['vendor/d:D-1']]]],
            'd' => [['vendor/a'], [['D-1', []]]],
        ], ['A-1', 'C-1']],
        // The same two hops, with top's patches a chain: T-2 and T-3 are links
        // within top, and did nothing to close the loop.
        'two hops, top a chain' => [[
            'top' => [['vendor/lib'], [['T-1', []], ['T-2', ['T-1']], ['T-3', ['T-2']]]],
            'lib' => [[], [['L-1', ['vendor/y:Y-1']]]],
            'y' => [['vendor/z'], [['Y-1', []]]],
            'z' => [[], [['Z-1', ['vendor/top:T-3']]]],
        ], ['L-1', 'Z-1']],
        'two hops, top a chain, z first' => [array_reverse([
            'top' => [['vendor/lib'], [['T-1', []], ['T-2', ['T-1']], ['T-3', ['T-2']]]],
            'lib' => [[], [['L-1', ['vendor/y:Y-1']]]],
            'y' => [['vendor/z'], [['Y-1', []]]],
            'z' => [[], [['Z-1', ['vendor/top:T-3']]]],
        ], true), ['L-1', 'Z-1']],
        'control: two hops, Z-1 built on T-1' => [[
            'top' => [['vendor/lib'], [['T-1', []], ['T-2', ['T-1']], ['T-3', ['T-2']]]],
            'lib' => [[], [['L-1', ['vendor/y:Y-1']]]],
            'y' => [['vendor/z'], [['Y-1', []]]],
            'z' => [[], [['Z-1', ['vendor/top:T-1']]]],
        ], ['L-1', 'Z-1']],
    ];
    foreach ($shapes as $how => [$defs, $refused]) {
        Assert::case($how);
        $p = (new Project($sandbox, 'r6-ndr-' . md5($how)))->root($meta(['trust' => ['vendor/*']]));
        $ids = [];
        foreach ($defs as $pkg => [$requires, $patches]) {
            $list = [];
            foreach ($patches as [$id, $depends]) {
                $ids[] = $id;
                $list[] = ['id' => $id, 'source' => $id . '.patch', 'depends' => $depends];
                $p->write('vendor/vendor/' . $pkg . '/' . $id . '.patch', $patchFor($id . '.txt'))->write($id . '.txt', "one\n");
            }
            $p->package('vendor/' . $pkg, $meta(['patches' => $list]), $requires);
        }
        $patcher = $p->patcher();
        $r = $patcher->run(true);
        $applied = [];
        foreach ($ids as $id) {
            $applied[$id] = file_get_contents($p->root . '/' . $id . '.txt') === "one\ntwo\n";
        }
        foreach ($ids as $id) {
            Assert::same(!in_array($id, $refused, true), $applied[$id], $id . ' ' . (in_array($id, $refused, true) ? 'refused' : 'applied') . ': ' . $why($r) . ' errors=' . implode(' / ', $patcher->errors()));
        }
        if ($refused !== []) {
            Assert::same(1, preg_match('/"(' . implode('|', $refused) . ')" is part of a dependency cycle/', implode(' / ', $patcher->errors())), 'the error names a patch that declares a depends: ' . implode(' / ', $patcher->errors()));
        }
    }
},

'r6-cycle-scale' => static function (Sandbox $sandbox) use ($meta): void {
    // A chain of 12,000 closed into a loop by one depends from a required
    // package. One walk per patch over the chain took 12 seconds.
    foreach (['closed by L-1' => true, 'control: open chain' => false] as $how => $closed) {
        Assert::case($how);
        $chain = [];
        for ($i = 1; $i <= 12000; $i++) {
            $chain[] = ['id' => 'T-' . $i, 'source' => 'p.patch', 'depends' => $i > 1 ? ['T-' . ($i - 1)] : []];
        }
        $p = (new Project($sandbox, 'r6-scale-' . md5($how)))->root($meta(['trust' => ['vendor/*']]))
            ->package('vendor/top', $meta(['patches' => $chain]), ['vendor/lib'])
            ->package('vendor/lib', $meta(['patches' => [['id' => 'L-1', 'source' => 'p.patch', 'depends' => $closed ? ['vendor/top:T-12000'] : []]]]))
            ->write('vendor/vendor/top/p.patch', 'x')->write('vendor/vendor/lib/p.patch', 'x');
        $collector = $p->collector();
        $started = microtime(true);
        $cyclic = [];
        foreach ($collector->collect() as $declaration) {
            if (!empty($declaration['cyclic'])) {
                $cyclic[] = $declaration['id'];
            }
        }
        Assert::same($closed ? ['L-1'] : [], $cyclic, 'only L-1, the depends against the require, is refused');
        Assert::true(microtime(true) - $started < 5, 'and settled in well under 5 seconds');
    }
},

];
