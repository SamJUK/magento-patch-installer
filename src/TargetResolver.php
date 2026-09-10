<?php

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
    /** @var string */
    private $projectRoot;

    /** @var array<int, array{package: string, install: string, src: string, dest: string, dir: bool}> */
    private $entries = [];

    /**
     * @param array<int, array{package: string, install: string, map: array<mixed>}> $mappings
     */
    public function __construct(string $projectRoot, array $mappings)
    {
        // Canonical, because every writePathFor result is a realpath compared
        // against this as a textual prefix. A non-canonical root makes that
        // comparison fail for every path, and the whole class quietly degrades
        // to returning what it was given.
        $this->projectRoot = rtrim(realpath($projectRoot) ?: $projectRoot, '/');

        $installRoots = [];
        foreach ($mappings as $mapping) {
            $installRoots[] = trim($mapping['install'], '/');
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
                if ($this->claimsAnotherPackage($dest, $install, $installRoots)) {
                    continue;
                }

                $this->entries[] = [
                    'package' => $mapping['package'],
                    'install' => $install,
                    'src' => $src,
                    'dest' => $dest,
                    'dir' => is_dir($this->projectRoot . '/' . $install . '/' . $src),
                ];
            }
        }

        // Longest destination first, so a specific file entry beats the
        // directory entry it sits under.
        usort($this->entries, static function (array $a, array $b): int {
            return strlen($b['dest']) <=> strlen($a['dest']);
        });
    }

    /**
     * Does this destination reach into an install directory that is not the
     * declaring package's own?
     *
     * @param string[] $installRoots
     */
    private function claimsAnotherPackage(string $dest, string $install, array $installRoots): bool
    {
        foreach ($installRoots as $root) {
            if ($root === '' || $root === $install) {
                continue;
            }

            if ($dest === $root || strpos($dest, $root . '/') === 0) {
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
        $relPath = ltrim($relPath, '/');

        foreach ($this->entries as $entry) {
            $packagePath = $entry['install'] . '/' . $entry['src'];

            $suffix = $this->suffixUnder($relPath, $entry['dest'], $entry['dir']);
            if ($suffix !== null) {
                return $packagePath . $suffix;
            }

            $suffix = $this->suffixUnder($relPath, $packagePath, $entry['dir']);
            if ($suffix !== null) {
                return $entry['dest'] . $suffix;
            }
        }

        return null;
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
