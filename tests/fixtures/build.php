<?php

declare(strict_types=1);

/**
 * Builds a self-contained fixture package for the acceptance suite.
 *
 * The suite used to install the real meta package to get patches to work with,
 * which coupled this repository's tests to another repository's contents — a
 * patch added or reworded over there could turn these tests red for reasons
 * that have nothing to do with this code.
 *
 * Instead the fixtures are generated here, from the files actually present in
 * whichever Magento is installed. That makes them correct on every version in
 * the matrix without shipping one patch file per release, and it keeps the
 * suite honest: these are real diffs, applied to real vendor files.
 *
 *   php tests/fixtures/build.php <project-root> <output-dir>
 */

$root = rtrim($argv[1] ?? '/var/www/html', '/');
$out = rtrim($argv[2] ?? '/fixture-pkg', '/');

$installed = static function (string $package) use ($root): ?string {
    $file = $root . '/vendor/composer/installed.json';
    $data = json_decode((string) @file_get_contents($file), true);

    foreach (($data['packages'] ?? $data ?? []) as $entry) {
        if (($entry['name'] ?? null) === $package) {
            return ltrim((string) $entry['version'], 'v');
        }
    }

    return null;
};

$base = $installed('magento/magento2-base');

if ($base === null) {
    fwrite(STDERR, "no magento/magento2-base in $root\n");
    exit(1);
}

@mkdir($out . '/patches', 0755, true);

/**
 * A one-line diff against a file that really exists, so the patch is valid for
 * this exact installation.
 */
$diff = static function (string $relative, string $marker) use ($root, $out): ?string {
    $absolute = $root . '/' . $relative;

    if (!is_file($absolute)) {
        return null;
    }

    $original = (string) file_get_contents($absolute);

    // Exactly one added line, always the last. That makes the scenarios
    // deterministic: delete the last line for a clean revert, insert before it
    // to break the hunk's trailing context.
    $patched = rtrim($original, "\n") . "\n/* " . $marker . " */\n";

    $a = tempnam(sys_get_temp_dir(), 'fx');
    $b = tempnam(sys_get_temp_dir(), 'fx');
    file_put_contents($a, $original);
    file_put_contents($b, $patched);

    exec(sprintf('diff -u %s %s', escapeshellarg($a), escapeshellarg($b)), $lines);
    unlink($a);
    unlink($b);

    if ($lines === []) {
        return null;
    }

    // Rewrite diff's temp-file headers to the real target path.
    $lines[0] = '--- a/' . $relative;
    $lines[1] = '+++ b/' . $relative;

    $name = $marker . '.patch';
    file_put_contents($out . '/patches/' . $name, implode("\n", $lines) . "\n");

    return 'patches/' . $name;
};

/** The first file that exists both at the project root and inside magento2-base. */
$rootMapped = static function () use ($root): ?string {
    foreach (glob($root . '/lib/web/*.js') ?: [] as $path) {
        $relative = substr($path, strlen($root) + 1);

        if (is_file($root . '/vendor/magento/magento2-base/' . $relative)) {
            return $relative;
        }
    }

    return null;
};

/** A module that outeredge/magento-disable-graphql replaces, if this version has one. */
$graphql = static function () use ($root): ?string {
    foreach (['module-catalog-graph-ql', 'module-customer-graph-ql', 'module-graph-ql'] as $module) {
        $relative = 'vendor/magento/' . $module . '/etc/module.xml';

        if (is_file($root . '/' . $relative)) {
            return $relative;
        }
    }

    return null;
};

$candidates = [
    'chain-one' => 'vendor/magento/framework/Escaper.php',
    'chain-two' => 'vendor/magento/module-catalog/etc/module.xml',
    'chain-three' => 'vendor/magento/module-customer/etc/module.xml',
    'standalone' => 'vendor/magento/module-store/etc/module.xml',
];

$sources = [];
foreach ($candidates as $marker => $relative) {
    $source = $diff($relative, $marker);

    if ($source === null) {
        fwrite(STDERR, "fixture target missing: $relative\n");
        exit(1);
    }

    $sources[$marker] = $source;
}

// The third link also touches a root-deployed file, so the reinstall scenario
// has something that must be healed in two places at once.
$mapped = $rootMapped();
if ($mapped !== null) {
    // Record it so the scenarios that need their own root-mapped file do not
    // pick the same one and collide with this fixture's hunk.
    file_put_contents($out . '/root-target.txt', $mapped . "\n");

    $extra = $diff($mapped, 'chain-three-root');
    if ($extra !== null) {
        $combined = file_get_contents($out . '/' . $sources['chain-three'])
            . file_get_contents($out . '/' . $extra);
        file_put_contents($out . '/' . $sources['chain-three'], $combined);
        unlink($out . '/' . $extra);
    }
}

$emergency = [];
$graphqlTarget = $graphql();
if ($graphqlTarget !== null) {
    $source = $diff($graphqlTarget, 'graphql-only');
    if ($source !== null) {
        $emergency[] = ['id' => 'FX-GRAPHQL', 'label' => 'GraphQL only', 'source' => $source,
                        'base' => ['magento/magento2-base' => $base]];
    }
}

$manifest = [
    'name' => 'fixtures/patches',
    'description' => 'Generated fixtures for the acceptance suite. Not a real package.',
    'type' => 'library',
    'license' => 'MIT',
    'version' => '1.0.0',
    // The package under test comes in as a dependency, exactly as the real meta
    // package pulls it in.
    'require' => ['samjuk/magento-patch-installer' => '*'],
    'extra' => [
        'magento-patches' => [
            'patches' => array_merge([
                [
                    'id' => 'FX-STANDALONE',
                    'label' => 'Standalone',
                    'source' => $sources['standalone'],
                    'base' => ['magento/magento2-base' => $base],
                ],
            ], $emergency),
            'lines' => [
                $base => [
                    'base' => ['magento/magento2-base' => $base],
                    // Cumulative: the order below is the chain, and no entry
                    // declares an edge of its own.
                    'cumulative' => true,
                    'patches' => [
                        ['id' => 'FX-0001', 'label' => 'First', 'source' => $sources['chain-one'],
                         'description' => 'The patch the rest of the chain is built on'],
                        ['id' => 'FX-0002', 'label' => 'Second', 'source' => $sources['chain-two']],
                        ['id' => 'FX-0003', 'label' => 'Third', 'source' => $sources['chain-three']],
                    ],
                ],
                // A line for a release this store is not on, so the suite can
                // assert that patches built for another version are skipped.
                '0.0.1-p1' => [
                    'base' => ['magento/magento2-base' => '0.0.1-p1'],
                    'patches' => [
                        ['id' => 'FX-OTHER', 'source' => $sources['standalone']],
                    ],
                ],
            ],
        ],
    ],
];

file_put_contents(
    $out . '/composer.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

printf(
    "fixtures for magento2-base %s: %d patches, graphql=%s, root-mapped=%s\n",
    $base,
    count($sources) + count($emergency),
    $graphqlTarget === null ? 'none' : basename(dirname(dirname($graphqlTarget))),
    $mapped ?? 'none'
);
