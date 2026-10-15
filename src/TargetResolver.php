<?php

// Copyright (c) 2026 Sam James. Licensed under the Open Software License version 3.0.
// SPDX-License-Identifier: OSL-3.0

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

/**
 * Works out where a patch target actually lives on disk.
 *
 * Magento deploys ~120 files from magento/magento2-base to the project root via
 * that package's `extra.map`. Both copies matter: the root one is served, the
 * package one is the seed any future deploy copies back over it. This class
 * inverts the map so either path can find its counterpart, and normalises the
 * link-based deploy strategies where the "two" copies are really one file.
 */
class TargetResolver
{
    /**
     * The package types magento/magento-composer-installer installs, and so
     * deploys, and where it roots each type's map destinations
     * (MagentoHackathon\Composer\Magento\PackageTypes). Any other type's
     * extra.map is read by nothing.
     */
    public const DEPLOYED_TYPES = [
        'magento2-module' => '/app/code/',
        'magento2-theme' => '/app/design/',
        'magento2-library' => '/lib/internal/',
        'magento2-language' => '/app/i18n/',
        'magento2-component' => './',
    ];

    /** @var string */
    private $projectRoot;

    /** @var array<int, array{package: string, install: string, src: string, dest: string, dir: bool, ignored: bool}> */
    private $entries = [];

    /** @var array<string, string|null> root path => why it has no single other copy, once worked out */
    private $contests = [];

    /** @var string the vendor directory, relative to the project root, or '' when it is outside it */
    private $vendorDir;

    /**
     * @param array<int, array{package: string, install: string, raw?: string, map: array<mixed>}> $mappings
     * @param string $vendorDir relative to the project root
     */
    public function __construct(string $projectRoot, array $mappings, string $vendorDir = 'vendor')
    {
        $this->vendorDir = trim($vendorDir, '/');

        // Canonical, because every writePathFor result is a realpath compared
        // against this as a textual prefix. A non-canonical root makes that
        // comparison fail for every path, and the whole class quietly degrades
        // to returning what it was given.
        $this->projectRoot = rtrim(realpath($projectRoot) ?: $projectRoot, '/');

        // As installed and as resolved: a path repository's package sits at a
        // link under vendor/ whose target is somewhere else in the project.
        $installRoots = [];
        foreach ($mappings as $mapping) {
            $installRoots[] = trim($mapping['install'], '/');
            $installRoots[] = trim($mapping['raw'] ?? '', '/');
        }

        foreach ($mappings as $mapping) {
            $install = trim($mapping['install'], '/');

            foreach ($mapping['map'] as $pair) {
                if (!is_array($pair) || !isset($pair[0], $pair[1])) {
                    continue;
                }

                $src = trim((string) $pair[0], '/');
                $dest = trim((string) $pair[1], '/');

                // extra.map is read from every installed package, and nothing
                // vouches for the ones that are not Magento's. A `..` segment
                // would aim a mirror write outside the project entirely.
                if ($src === '' || $dest === '' || $this->escapes($src) || $this->escapes($dest)) {
                    continue;
                }

                // A deploy destination is a project-root path — pub/, app/,
                // .htaccess, nginx.conf.sample. It is never inside another
                // package's install directory. Without this, any installed
                // package, trusted or not, could name a destination inside
                // magento2-base, win the longest-dest sort, and take over the
                // mirror lookup for a file it does not own: the served copy
                // then quietly stops being patched while the report still says
                // applied. The mirror is the whole defence against a
                // magento2-base deploy undoing a root patch, and one line of
                // JSON in an untrusted dependency switched it off.
                $isDir = is_dir($this->projectRoot . '/' . $install . '/' . $src);

                if ($this->claimsAnotherPackage($dest, $install, $installRoots, $isDir)) {
                    continue;
                }

                $this->entries[] = [
                    'package' => $mapping['package'],
                    'install' => $install,
                    'src' => $src,
                    'dest' => $dest,
                    'dir' => $isDir,
                    'ignored' => !empty($pair[2]),
                ];
            }
        }

        // What is deployed before what the root has the installer ignore, then
        // longest destination first, so a specific file entry beats the
        // directory entry it sits under.
        usort($this->entries, static function (array $a, array $b): int {
            return [$a['ignored'], strlen($b['dest'])] <=> [$b['ignored'], strlen($a['dest'])];
        });
    }

    /**
     * A package's extra.map as magento-composer-installer deploys it.
     *
     * Each destination goes through the root's path-mapping-translations (the
     * first matching prefix, with or without a leading `./`), gets its type's
     * prefix, and is marked when magento-deploy-ignore covers it — by the
     * installer's own test, which ignores a destination that any ignore entry
     * starts with (PathTranslationParser::translatePathMappings(),
     * DeploystrategyAbstract::isDestinationIgnored()).
     *
     * Marked, not dropped. An ignored file is one the store keeps its own copy
     * of, and is still served: it never competes with a package that does
     * deploy there, but where nothing else does, its package copy stays its
     * other copy, as it always was.
     *
     * @param array<mixed> $map
     * @param array<mixed> $translations
     * @param array<mixed> $ignored
     *
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public static function deployed(array $map, string $typePrefix, array $translations, array $ignored): array
    {
        $deployed = [];

        foreach ($map as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1])) {
                continue;
            }

            $dest = (string) $pair[1];

            foreach ($translations as $from => $to) {
                // An empty key is a needle PHP 7.4 warns about, and Composer
                // turns that warning into an exception.
                if ((string) $from === '') {
                    continue;
                }

                foreach (['', './'] as $variant) {
                    $prefix = $variant . $from;

                    if (strpos($dest, $prefix) === 0) {
                        $dest = $to . substr($dest, strlen($prefix));
                        break 2;
                    }
                }
            }

            $dest = $typePrefix . $dest;
            $asIgnored = str_replace(['/./', '//'], '/', '/' . $dest);
            $isIgnored = false;

            foreach ($ignored as $entry) {
                $isIgnored = $isIgnored || (is_string($entry) && strpos($entry, $asIgnored) === 0);
            }

            $segments = array_filter(explode('/', $dest), static function (string $segment): bool {
                return $segment !== '' && $segment !== '.';
            });

            $deployed[] = [(string) $pair[0], implode('/', $segments), $isIgnored];
        }

        return $deployed;
    }

    /**
     * Why $relPath has no single other copy, or null: more than one package
     * deploys the root path it is, or is the package copy of.
     */
    public function contested(string $relPath): ?string
    {
        $pair = $this->pair($relPath);

        if ($pair === null) {
            return null;
        }

        $root = $pair['root'];

        if (!array_key_exists($root, $this->contests)) {
            $packages = array_keys($this->shippers($root));
            $this->contests[$root] = count($packages) > 1
                ? sprintf('%s is deployed to the project root by both %s', $root, implode(' and ', $packages))
                : null;
        }

        return $this->contests[$root];
    }

    /**
     * The deployed entries whose package actually ships root path $root, one
     * per package.
     *
     * Decided per file, not per mapping. Which copy is served then comes down
     * to deploy order and magento-force, so neither can be called the served
     * file's other copy. But a directory mapping only deploys what is in it:
     * on Adobe Commerce, magento2-base and magento2-ee-base both map all of
     * pub/errors, and only base ships processor.php.
     *
     * @return array<string, array{package: string, install: string, src: string, dest: string, dir: bool, ignored: bool}>
     */
    private function shippers(string $root): array
    {
        $found = [];

        foreach ($this->entries as $entry) {
            if ($entry['ignored'] || isset($found[$entry['package']])) {
                continue;
            }

            $suffix = $this->suffixUnder($root, $entry['dest'], $entry['dir']);

            if ($suffix !== null && file_exists($this->absolute($entry['install'] . '/' . $entry['src'] . $suffix))) {
                $found[$entry['package']] = $entry;
            }
        }

        return $found;
    }

    /**
     * Does this destination reach into an install directory that is not the
     * declaring package's own, or sit above any install directory?
     *
     * Above is `vendor` or `vendor/magento`: every package path beneath it
     * then reads as a root copy of this package's, and a patch naming
     * magento2-base's copy took one that does not exist as its other copy.
     *
     * @param string[] $installRoots
     */
    private function claimsAnotherPackage(string $dest, string $install, array $installRoots, bool $isDir): bool
    {
        // Anything in the vendor directory but a top-level file, such as
        // vendor/.htaccess, is some package's: a path repository's package
        // resolves elsewhere, so its install path alone does not cover it,
        // and a directory there (vendor/amasty) is a whole vendor's packages.
        $underVendor = $this->vendorDir !== '' && strpos($dest, $this->vendorDir . '/') === 0;

        if ($this->vendorDir !== ''
            && ($dest === $this->vendorDir
                || ($underVendor && strpos(substr($dest, strlen($this->vendorDir) + 1), '/') !== false)
                || ($underVendor && ($isDir || is_dir($this->projectRoot . '/' . $dest))))
        ) {
            return true;
        }

        foreach ($installRoots as $root) {
            if ($root === '') {
                continue;
            }

            if (strpos($root, $dest . '/') === 0) {
                return true;
            }

            if ($root !== $install && ($dest === $root || strpos($dest, $root . '/') === 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The other copy of a root-mapped file, in whichever direction is needed:
     * a project path resolves to its package original and vice versa.
     *
     * Returns null for paths that are not root-mapped at all, which is the
     * overwhelming majority (anything under a module's own vendor directory).
     */
    public function mirrorFor(string $relPath): ?string
    {
        $pair = $this->pair($relPath);

        if ($pair === null || $this->contested($relPath) !== null) {
            return null;
        }

        return $pair['root'] === ltrim($relPath, '/') ? $pair['package'] : $pair['root'];
    }

    /**
     * The root copy and package copy $relPath is one of.
     *
     * @return array{root: string, package: string}|null
     */
    private function pair(string $relPath): ?array
    {
        $relPath = ltrim($relPath, '/');
        $first = null;

        // Where several packages map the directory a root path is in, its
        // other copy is in the one that ships it.
        foreach ($this->entries as $entry) {
            $suffix = $this->suffixUnder($relPath, $entry['dest'], $entry['dir']);

            if ($suffix === null) {
                continue;
            }

            $pair = ['root' => $relPath, 'package' => $entry['install'] . '/' . $entry['src'] . $suffix];

            if (file_exists($this->absolute($pair['package']))) {
                return $pair;
            }

            $first = $first ?? $pair;
        }

        // A package path is its own package's copy, before any destination
        // that would only name a package copy that is not there.
        foreach ($this->entries as $entry) {
            $suffix = $this->suffixUnder($relPath, $entry['install'] . '/' . $entry['src'], $entry['dir']);

            if ($suffix !== null) {
                return ['root' => $entry['dest'] . $suffix, 'package' => $relPath];
            }
        }

        if ($first !== null) {
            return $first;
        }

        // A package installed through a symlink, as a path repository leaves
        // it, is mapped by where it really is.
        $resolved = $this->writePathFor($relPath);

        return $resolved !== $relPath ? $this->pair($resolved) : null;
    }

    /**
     * The package that deploys root path $relPath and ships it, or null: none
     * does, the root has the installer ignore it, or it is not a root path.
     */
    public function deployedBy(string $relPath): ?string
    {
        $relPath = ltrim($relPath, '/');

        foreach ($this->shippers($relPath) as $package => $entry) {
            return $package;
        }

        return null;
    }

    /**
     * The project-root path $relPath is, or is the package copy of, or null
     * when it is not root-mapped: one name for both copies.
     */
    public function rootOf(string $relPath): ?string
    {
        $pair = $this->pair($relPath);

        return $pair === null ? null : $pair['root'];
    }

    /**
     * Where a patch aimed at $relPath should actually be written.
     *
     * `git apply` refuses to write through a symlink — "beyond a symbolic
     * link" — and it means any link in the path, not just the last segment.
     * That is the `symlink` deploy strategy at the project root, a `vendor/`
     * pointed at shared storage, and the vendor/<vendor>/<package> symlink a
     * Composer path repository leaves behind. Resolving it ourselves patches
     * the real file, and every path that names it still reflects the change.
     */
    public function writePathFor(string $relPath): string
    {
        $relPath = ltrim($relPath, '/');
        $resolved = realpath($this->absolute($relPath));

        // Not there yet — a hunk that creates the file. Resolve as much of the
        // path as does exist.
        $real = $resolved === false ? $this->resolveDeepest($relPath) : $resolved;

        if ($real === null) {
            return $relPath;
        }

        $prefix = $this->projectRoot . '/';
        if (strpos($real, $prefix) !== 0) {
            // Points outside the project; leave it alone rather than write
            // somewhere the project does not own.
            return $relPath;
        }

        return substr($real, strlen($prefix));
    }

    /**
     * Does this path leave the project once its links are followed?
     *
     * writePathFor() answers "where should this be written" and hands back the
     * unresolved path when the answer is "nowhere" — which reads as an ordinary
     * relative path, and absolute() then rebuilds exactly the escaping path it
     * was refusing. Callers that write without going through `git apply` — the
     * mirror copy is the only one — have to ask this instead.
     */
    public function escapesProject(string $relPath): bool
    {
        $relPath = ltrim($relPath, '/');
        $resolved = realpath($this->absolute($relPath));
        $real = $resolved === false ? $this->resolveDeepest($relPath) : $resolved;

        if ($real === null) {
            return false;
        }

        return strpos($real, $this->projectRoot . '/') !== 0;
    }

    /**
     * The absolute path $relPath would have, with the deepest existing part of
     * it resolved through any links.
     */
    private function resolveDeepest(string $relPath): ?string
    {
        $parts = explode('/', $relPath);
        $tail = [];

        while ($parts !== []) {
            array_unshift($tail, (string) array_pop($parts));

            $base = $parts === []
                ? realpath($this->projectRoot)
                : realpath($this->projectRoot . '/' . implode('/', $parts));

            if ($base !== false) {
                return rtrim($base, '/') . '/' . implode('/', $tail);
            }
        }

        return null;
    }

    /**
     * True when two project-relative paths are the same file on disk, which the
     * `link` (hardlink) deploy strategy makes possible.
     */
    public function isSameFile(string $a, string $b): bool
    {
        $pathA = $this->absolute($a);
        $pathB = $this->absolute($b);

        if (!is_file($pathA) || !is_file($pathB)) {
            return false;
        }

        $statA = @stat($pathA);
        $statB = @stat($pathB);

        if ($statA === false || $statB === false) {
            return false;
        }

        return $statA['dev'] === $statB['dev'] && $statA['ino'] === $statB['ino'];
    }

    public function absolute(string $relPath): string
    {
        return $this->projectRoot . '/' . ltrim($relPath, '/');
    }

    public function hash(string $relPath): ?string
    {
        $absolute = $this->absolute($relPath);

        if (!is_file($absolute)) {
            return null;
        }

        $hash = @hash_file('sha256', $absolute);

        return $hash === false ? null : $hash;
    }

    /**
     * Is this relative path anything other than a plain path downwards?
     *
     * Backslash counts as a separator: on Windows `..\..\x` is a traversal
     * that a single explode('/') would keep as one innocent-looking segment.
     * A `.` segment is rejected too — it can never match a lookup, so keeping
     * it would mean silently dropping a mapping rather than saying so.
     */
    private function escapes(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return true;
        }

        // A newline or other control character would reach a diff header as is.
        if (preg_match('#[\x00-\x1f\x7f]#', $path) === 1) {
            return true;
        }

        foreach (preg_split('#[/\\\\]#', $path) ?: [] as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * The part of $path that sits under $base, as a leading-slash suffix, or
     * null when $path is not $base and not under it.
     */
    private function suffixUnder(string $path, string $base, bool $baseIsDir): ?string
    {
        if ($path === $base) {
            return '';
        }

        if ($baseIsDir && strpos($path, $base . '/') === 0) {
            return substr($path, strlen($base));
        }

        return null;
    }
}
