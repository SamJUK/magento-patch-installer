<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\VersionParser;

/**
 * Finds patch declarations, and refuses to look at ones nobody asked for.
 *
 * Patching is opt-in: the root package's own declarations always count, and any
 * other package must be named in `extra.magento-patches.trust`. The alternative
 * — every package in the tree may silently patch the project — is a supply
 * chain hole, not a feature.
 *
 * A declaration is an array with keys: label, owner, source (absolute),
 * sourceRelative, base (package => constraint).
 */
class Collector
{
    public const CONFIG_KEY = 'magento-patches';

    /** @var Composer */
    private $composer;

    /** @var string[] */
    private $errors = [];

    /** @var array<int, array<string, mixed>>|null */
    private $collected;

    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collect(): array
    {
        // Nothing about a package's declarations changes within a run, and
        // magento-patches:status asks three times. Splitting and re-validating a large
        // manifest for each caller is pure repetition — and it is the caller
        // count that turned a slow collect() into a slow composer install.
        if ($this->collected !== null) {
            return $this->collected;
        }

        // Collecting twice in one run — run() and advisories() both do — must
        // not report every problem twice.
        $this->errors = [];

        $root = $this->composer->getPackage();
        $trust = $this->trustPatterns($root);

        $declarations = $this->fromPackage($root, $this->rootDirectory());

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            if (!$this->isTrusted($package->getName(), $trust)) {
                // Silence here is indistinguishable from a fully patched store:
                // forget the trust entry and everything reports clean.
                // Only when it actually declares patches. Reacting to any key
                // under extra.magento-patches meant a package nobody trusts
                // could fail every consumer's composer install with one junk
                // key — and the only ways out were trusting the hostile package
                // or setting allow-unpatched, which switches the guarantee off.
                if ($this->declaresPatches($this->config($package))) {
                    $this->errors[] = sprintf(
                        '%s declares patches but is not trusted — add it to extra.magento-patches.trust to apply them',
                        $package->getName()
                    );
                }

                continue;
            }

            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
            if ($installPath === null || $installPath === '') {
                continue;
            }

            $declarations = array_merge($declarations, $this->fromPackage($package, $installPath));
        }

        $this->collected = $this->select($declarations);

        return $this->collected;
    }

    /**
     * Apply the root package's `sources` block: which of the patches a trusted
     * package offers this project actually wants.
     *
     * Skipped patches are marked, never dropped. A patch that disappears from
     * the report is a hole in coverage that looks like coverage, which is the
     * one failure this plugin exists to prevent — so a skip gets its own row,
     * its own count, and the reason the project gave for it.
     *
     * Root package only. Which patches a store wants is the store's decision,
     * and a dependency must not be able to switch off a sibling's.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<int, array<string, mixed>>
     */
    private function select(array $declarations): array
    {
        foreach ($declarations as $index => $declaration) {
            $declarations[$index]['skipped'] = null;
        }

        $sources = $this->config($this->composer->getPackage())['sources'] ?? [];

        if (!is_array($sources)) {
            $this->errors[] = '"sources" must be an object keyed by package name';

            return $declarations;
        }

        foreach ($sources as $owner => $rules) {
            if (!is_array($rules)) {
                $this->errors[] = sprintf('sources: "%s" is not an object', (string) $owner);
                continue;
            }

            $mine = [];
            foreach ($declarations as $index => $declaration) {
                if ($declaration['owner'] === $owner) {
                    $mine[$index] = $declaration;
                }
            }

            if ($mine === []) {
                // Almost always a typo. Silently doing nothing would leave the
                // project believing it had switched something off.
                $this->errors[] = sprintf('sources: "%s" declares no patches here', (string) $owner);
                continue;
            }

            $declarations = $this->applyRules($declarations, $mine, (string) $owner, $rules);
        }

        return $this->cascadeSkips($declarations);
    }

    /**
     * `skip` is the whole feature.
     *
     * There was an `only` list and a `mode: none` to go with it, and they are
     * gone. Opt-in is the wrong default for a security package — you get
     * partial coverage by omission, which is the failure this plugin exists to
     * prevent — and it was where the chain trouble came from: requiring one
     * link left its prerequisites skipped, and the cascade then took away the
     * very patch that had been asked for. Adding a mode back later is
     * additive; removing one would not have been.
     *
     * @param array<int, array<string, mixed>> $declarations
     * @param array<int, array<string, mixed>> $mine
     * @param array<string, mixed> $rules
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyRules(array $declarations, array $mine, string $owner, array $rules): array
    {
        foreach (array_keys($rules) as $key) {
            if ($key !== 'skip') {
                $this->errors[] = sprintf(
                    'sources: "%s" has an unknown key "%s"; only "skip" is supported',
                    $owner,
                    (string) $key
                );

                return $declarations;
            }
        }

        $skip = $rules['skip'] ?? [];

        if (!is_array($skip)) {
            $this->errors[] = sprintf('sources: "%s" has a malformed skip', $owner);

            return $declarations;
        }

        foreach ($skip as $identifier => $reason) {
            if (strpos((string) $identifier, '@') === false && $this->isChained((string) $identifier, $mine)) {
                // A bare id matches every base line, and on a chain the cascade
                // multiplies that: a line published later that reuses the id
                // would go dark under a reason written about a different
                // Magento release. Say which line you mean.
                $this->errors[] = sprintf(
                    'sources: "%s" skips "%s", which other patches are built on — scope it as "%s@<base version>"',
                    $owner,
                    (string) $identifier,
                    (string) $identifier
                );
                continue;
            }

            if (!is_scalar($reason)) {
                $this->errors[] = sprintf(
                    'sources: "%s" gives a non-string reason for "%s"',
                    $owner,
                    (string) $identifier
                );
                continue;
            }

            $matched = $this->mark(
                $declarations,
                $mine,
                (string) $identifier,
                trim((string) $reason) === '' ? 'skipped by this project' : trim((string) $reason)
            );

            if (!$matched) {
                $this->errors[] = sprintf(
                    'sources: "%s" has no patch matching skip "%s"',
                    $owner,
                    (string) $identifier
                );
            }
        }

        return $declarations;
    }


    /**
     * @param array<int, array<string, mixed>> $declarations
     * @param array<int, array<string, mixed>> $mine
     */
    private function mark(array &$declarations, array $mine, string $identifier, string $reason): bool
    {
        $matched = false;

        foreach ($mine as $index => $declaration) {
            if ($this->identifies($identifier, $declaration)) {
                $declarations[$index]['skipped'] = $reason;
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * Does this bare id name a patch that other patches are built on top of?
     *
     * @param array<int, array<string, mixed>> $mine
     */
    private function isChained(string $identifier, array $mine): bool
    {
        // "Is anything built on this", not "is it filed under isolated". An
        // emergency patch other patches depend on carries exactly the same
        // hazard, and a lone isolated patch nothing depends on carries none.
        foreach ($mine as $declaration) {
            if (!$this->identifies($identifier, $declaration)) {
                continue;
            }

            foreach ($mine as $other) {
                if (in_array($declaration['origin'], $other['depends'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * An identifier is an id, or `id@line` when it needs to be narrower.
     *
     * A bare id matches that id in every base line, because ids are unique only
     * within a line — 2026-08-001 exists for each of them — and quietly picking
     * one of several would be worse than either alternative.
     *
     * @param array<string, mixed> $declaration
     */
    private function identifies(string $identifier, array $declaration): bool
    {
        $parts = explode('@', $identifier, 2);

        if ($parts[0] !== (string) $declaration['id']) {
            return false;
        }

        return !isset($parts[1]) || $parts[1] === (string) ($declaration['line'] ?? '');
    }

    /**
     * Adobe stipulates each monthly isolated patch depends on the ones before
     * it. Skipping one is therefore not a local decision: everything later in
     * that line is built on it, and applying those would be applying a patch to
     * a file that never received its prerequisite.
     *
     * Every patch taken down this way is named, rather than the whole line
     * quietly shrinking.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<int, array<string, mixed>>
     */
    private function cascadeSkips(array $declarations): array
    {
        // Edges are indices into the pre-ordering array; map them to labels
        // once so the pass below can stay linear.
        $lookup = [];

        foreach ($declarations as $declaration) {
            $lookup[$declaration['origin']] = $declaration['label'];
        }

        // Declarations arrive in dependency order, so one forward pass settles
        // it: everything a patch is built on has already been decided by the
        // time the patch itself is reached, however long the chain.
        $skipped = [];

        foreach ($declarations as $index => $declaration) {
            if ($declaration['skipped'] !== null) {
                $skipped[$declaration['origin']] = true;
                continue;
            }

            foreach ($declaration['depends'] as $needs) {
                $prerequisite = $lookup[$needs] ?? null;

                if ($prerequisite !== null && isset($skipped[$needs])) {
                    // Named directly, not transitively: "depends on the patch
                    // three before the one you skipped" is true and sends you
                    // to the wrong place.
                    $declarations[$index]['skipped'] = sprintf(
                        'depends on %s, which this project skips',
                        $prerequisite
                    );
                    $skipped[$declaration['origin']] = true;
                    break;
                }
            }
        }

        return $declarations;
    }

    /**
     * Problems worth telling the user about: a bad source path, a package that
     * declared patches without being trusted.
     *
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function allowsUnpatched(): bool
    {
        $config = $this->config($this->composer->getPackage());

        return !empty($config['allow-unpatched']);
    }

    /**
     * Report what would happen and write nothing.
     *
     * For trialling this on a store that is already patched by something else:
     * you get the full verdict for every target without a byte changing, and
     * the working tree is exactly as you left it.
     */
    public function isDryRun(): bool
    {
        $config = $this->config($this->composer->getPackage());

        return !empty($config['dry-run']);
    }

    /**
     * Declarations come in two shapes, because patches do.
     *
     * `patches` holds the ones that stand alone: an emergency fix gated on a
     * version range, applying to whatever base line matches. `lines` groups the
     * rest by base version, which is where the base constraint belongs — stated
     * once, not repeated on every monthly patch built against it. Within a
     * line, `isolated` is an ordered chain (Adobe stipulates each monthly patch
     * depends on the ones before it) and `emergency` is not.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromPackage(PackageInterface $package, string $baseDir): array
    {
        $baseDir = rtrim($baseDir, '/');
        $config = $this->config($package);

        $declarations = $this->fromConfig($config, $package, $baseDir, $baseDir, true);

        foreach ($this->includes($config, $package, $baseDir) as $file => $included) {
            $declarations = array_merge(
                $declarations,
                // Sources resolve against the file that declares them, which is
                // what makes splitting worth doing: a manifest can sit in the
                // same directory as the patches it names.
                $this->fromConfig($included, $package, $baseDir, dirname($file), false, $file)
            );
        }

        return $this->resolve(array_values(array_filter($declarations)), $package);
    }

    /**
     * Resolve `depends` into edges, and order declarations so a patch always
     * comes after everything it is built on.
     *
     * Ordering used to come from a patch's index in its `isolated` array, which
     * could not express a dependency crossing the emergency/isolated split —
     * StyleSmuggler comes after that month's isolated patch and there was no way
     * to say so — and made the order of an `include` list load-bearing, so
     * splitting a base line across files silently inverted the chain.
     *
     * Nothing in the patches reveals this ordering: today's monthly patches
     * touch entirely disjoint files, so any order applies cleanly. It is Adobe's
     * stipulation, not a textual fact, and writing it down is the only honest
     * way to hold it.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolve(array $declarations, PackageInterface $package): array
    {
        $byLine = [];

        foreach ($declarations as $index => $declaration) {
            $byLine[(string) ($declaration['line'] ?? '')][$index] = $declaration;
        }

        foreach ($byLine as $line => $group) {
            $declarations = $this->link($declarations, $group, $package, (string) $line);
        }

        return $this->order($declarations);
    }

    /**
     * Turn each `depends` id into an index, and complain about every way that
     * can be wrong.
     *
     * @param array<int, array<string, mixed>> $declarations
     * @param array<int, array<string, mixed>> $group
     *
     * @return array<int, array<string, mixed>>
     */
    private function link(array $declarations, array $group, PackageInterface $package, string $line): array
    {
        $seen = [];

        // A cumulative line says its patches form a chain in the order listed,
        // so the edges are derived rather than written. Adobe's monthly patches
        // are cumulative and nothing in their content reveals it — declaring it
        // once on the line is the honest way to hold that, and an edge that is
        // derived cannot be forgotten the way a per-patch "depends" could.
        $previous = null;
        foreach ($group as $index => $declaration) {
            if (!$declaration['cumulative']) {
                continue;
            }

            if ($declaration['depends'] !== []) {
                $this->errors[] = sprintf(
                    '%s: patch "%s" declares "depends" in a cumulative line%s — the order it is listed in is the chain',
                    $package->getName(),
                    $declaration['label'],
                    $line === '' ? '' : ' for ' . $line
                );
            }

            $group[$index]['depends'] = $previous === null ? [] : [$previous];
            $declarations[$index]['depends'] = $group[$index]['depends'];
            $previous = (string) $declaration['id'];
        }

        // Built once. Scanning the whole group per `depends` entry is
        // quadratic, and the shape that triggers it — a chain declared
        // back-to-front — is the package's to choose: 60,000 patches in a 3 MB
        // manifest cost 87 seconds per collect(), and composer install calls
        // collect() more than once.
        $byId = [];
        foreach ($group as $candidate => $other) {
            if (!isset($byId[(string) $other['id']])) {
                $byId[(string) $other['id']] = $candidate;
            }
        }

        foreach ($group as $index => $declaration) {
            $id = (string) $declaration['id'];

            if (isset($seen[$id])) {
                // Two patches with one id share a label, so they collide in the
                // skip map, the chain map and the state file, and a `depends`
                // naming that id silently binds to whichever came first.
                $this->errors[] = sprintf(
                    '%s: "%s" is declared twice%s',
                    $package->getName(),
                    $id,
                    $line === '' ? '' : ' for ' . $line
                );
            }

            $seen[$id] = true;
            $edges = [];

            foreach ($declaration['depends'] as $needs) {
                $target = $byId[(string) $needs] ?? null;

                if ($target === null) {
                    $this->errors[] = sprintf(
                        '%s: patch "%s" depends on "%s", which is not declared%s',
                        $package->getName(),
                        $declaration['label'],
                        (string) $needs,
                        $line === '' ? '' : ' for ' . $line
                    );
                    continue;
                }

                if ($target === $index) {
                    $this->errors[] = sprintf(
                        '%s: patch "%s" depends on itself',
                        $package->getName(),
                        $declaration['label']
                    );
                    continue;
                }

                $edges[] = $target;
            }

            $declarations[$index]['depends'] = array_map(
                static function (int $target) use ($declaration): string {
                    return $declaration['owner'] . '#' . $target;
                },
                $edges
            );

        }

        return $declarations;
    }


    /**
     * Depth-first topological order, with declaration order as the tiebreak so
     * two independent patches keep the order they were written in.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<int, array<string, mixed>>
     */
    private function order(array $declarations): array
    {
        $ordered = [];
        $state = [];
        $cycles = 0;

        // One shared stack rather than a copy per frame. Passing the path by
        // value cost O(n²) memory on a chain declared back to front, and a
        // package chooses that order — 12,000 patches was a fatal, which
        // nothing can catch, in every consumer's composer install.
        $path = [];

        $visit = function (int $index) use (&$visit, &$ordered, &$state, &$path, &$cycles, $declarations): void {
            if (($state[$index] ?? null) === 'done') {
                return;
            }

            if (($state[$index] ?? null) === 'open') {
                // One message per run, not one per back-edge with the whole
                // path in each: a complete graph made the error text alone
                // grow cubically and exhausted memory before it printed.
                if ($cycles++ === 0) {
                    $this->errors[] = sprintf(
                        '%s: "%s" is part of a dependency cycle',
                        $declarations[$index]['owner'],
                        $declarations[$index]['label']
                    );
                }

                return;
            }

            $state[$index] = 'open';
            $path[] = $index;

            foreach ($declarations[$index]['depends'] as $needs) {
                $visit((int) substr((string) $needs, strrpos((string) $needs, '#') + 1));
            }

            array_pop($path);
            $state[$index] = 'done';
            // Globally unique. Origins used to restart at 0 for each package,
            // and every consumer built its lookup over the merged list, so the
            // last package silently owned every earlier package's edges.
            $declarations[$index]['origin'] = $declarations[$index]['owner'] . '#' . $index;
            $ordered[] = $declarations[$index];
        };

        foreach (array_keys($declarations) as $index) {
            $visit($index);
        }

        if ($cycles > 1) {
            $this->errors[] = sprintf('%d further dependency cycles were not listed', $cycles - 1);
        }

        return $ordered;
    }

    /**
     * Read the files named in `include`.
     *
     * Deliberately a list rather than a directory scan. A scan decides what is
     * a patch by where it sits, so a directory it fails to walk is a patch that
     * silently does not apply — and it widens what a trusted package can pull
     * in from a list you can read in review to whatever happens to be on disk.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string, mixed>> absolute path => decoded contents
     */
    private function includes(array $config, PackageInterface $package, string $baseDir): array
    {
        $paths = $config['include'] ?? [];

        if ($paths === []) {
            return [];
        }

        if (!is_array($paths)) {
            $this->errors[] = sprintf('%s: "include" must be a list of files', $package->getName());

            return [];
        }

        $files = [];

        foreach ($paths as $path) {
            if (!is_string($path)) {
                // Casting an array to string is a PHP warning, and Composer
                // turns warnings into exceptions — so a typo in a trusted
                // package's composer.json would abort composer install for
                // every consumer instead of being reported here.
                $this->errors[] = sprintf('%s: "include" contains a non-string entry', $package->getName());
                continue;
            }

            $file = $this->confine($baseDir, $baseDir, $path, $package->getName(), 'include');

            if ($file === null) {
                continue;
            }

            if (!is_file($file)) {
                // A FIFO passes realpath() and confinement, and reading it
                // blocks for ever with no output at all.
                $this->errors[] = sprintf('%s: included "%s" is not a regular file', $package->getName(), $path);
                continue;
            }

            $decoded = json_decode((string) @file_get_contents($file), true);

            if (!is_array($decoded)) {
                // A file that cannot be read is not a shorter list. Nobody can
                // say what it was meant to contain, so nobody can say the store
                // is covered.
                $this->errors[] = sprintf(
                    '%s: included file "%s" is not readable JSON',
                    $package->getName(),
                    $path
                );
                continue;
            }

            // A file that parses but declares nothing is the dangerous case: a
            // one-character typo in "lines" drops a whole year of patches, and
            // without this the run exits 0 and prints the green banner.
            // `include` counts as content here only so that a file which does
            // nothing but include others gets the clearer nesting error below,
            // rather than being told it declares nothing.
            if (!isset($decoded['patches']) && !isset($decoded['lines']) && !isset($decoded['include'])) {
                $this->errors[] = sprintf(
                    '%s: included file "%s" declares neither "patches" nor "lines"',
                    $package->getName(),
                    $path
                );
                continue;
            }

            $files[$file] = $decoded;
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>|null>
     */
    private function fromConfig(
        array $config,
        PackageInterface $package,
        string $packageRoot,
        string $sourceDir,
        bool $topLevel,
        string $sourceFile = 'composer.json'
    ): array {
        $declarations = [];

        if (!$topLevel && ($config['include'] ?? []) !== []) {
            // One level only, so the whole set is readable in one place.
            $this->errors[] = sprintf(
                '%s: an included file cannot include others (%s)',
                $package->getName(),
                $sourceFile
            );
        }

        foreach ($this->entries($config['patches'] ?? [], $package, 'patches') as $entry) {
            $declarations[] = $this->declaration(
                $entry,
                $package,
                $packageRoot,
                $sourceDir,
                $entry['base'] ?? [],
                null,
                false
            );
        }

        $lines = $config['lines'] ?? [];
        if (!is_array($lines)) {
            $this->errors[] = sprintf('%s: "lines" must be an object keyed by base version', $package->getName());
            $lines = [];
        }

        foreach ($lines as $line => $definition) {
            if (!is_array($definition)) {
                $this->errors[] = sprintf('%s: line "%s" is not an object', $package->getName(), $line);
                continue;
            }

            $base = isset($definition['base']) && is_array($definition['base']) ? $definition['base'] : [];

            if ($base === []) {
                $this->errors[] = sprintf('%s: line "%s" has no base constraint', $package->getName(), $line);
            }

            // "isolated" and "emergency" are how Adobe groups its releases, and
            // how a package that ships them might organise its files. They are
            // not distinctions this plugin acts on: a patch is a patch, and what
            // matters is what it depends on. Being explicit about the rename
            // rather than ignoring the old keys, because silently skipping a
            // block named "isolated" would drop every patch in it and exit 0.
            foreach (['isolated', 'emergency'] as $legacy) {
                if (isset($definition[$legacy])) {
                    $this->errors[] = sprintf(
                        '%s: line "%s" uses "%s" — patches are declared in one "patches" list now, ordered by "depends"',
                        $package->getName(),
                        $line,
                        $legacy
                    );
                }
            }

            $cumulative = ($definition['cumulative'] ?? false) === true;

            foreach ($this->entries($definition['patches'] ?? [], $package, (string) $line) as $entry) {
                $declarations[] = $this->declaration(
                    $entry,
                    $package,
                    $packageRoot,
                    $sourceDir,
                    $base,
                    (string) $line,
                    $cumulative
                );
            }
        }

        return $declarations;
    }

    /**
     * @param mixed $raw
     *
     * @return array<int, array<string, mixed>>
     */
    private function entries($raw, PackageInterface $package, string $context): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            $this->errors[] = sprintf('%s: "%s" must be a list of patches', $package->getName(), $context);

            return [];
        }

        $entries = [];

        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['id'], $entry['source'])) {
                $this->errors[] = sprintf('%s: a patch in "%s" is missing id or source', $package->getName(), $context);
                continue;
            }

            // Casting an array to string is a warning, and Composer promotes
            // warnings to exceptions — so this would leave composer install
            // dead rather than reporting a malformed declaration.
            foreach (['id', 'source', 'label', 'description'] as $field) {
                if (isset($entry[$field]) && !is_scalar($entry[$field])) {
                    $this->errors[] = sprintf(
                        '%s: a patch in "%s" has a non-string %s',
                        $package->getName(),
                        $context,
                        $field
                    );
                    continue 2;
                }
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>|null
     */
    private function declaration(
        array $entry,
        PackageInterface $package,
        string $packageRoot,
        string $sourceDir,
        array $base,
        ?string $line,
        bool $cumulative
    ): ?array {
        $label = $this->label($entry, $line);
        $depends = [];

        foreach ((array) ($entry['depends'] ?? []) as $needs) {
            if (!is_scalar($needs)) {
                $this->errors[] = sprintf(
                    '%s: patch "%s" has a non-string entry in "depends"',
                    $package->getName(),
                    $label
                );

                return null;
            }

            $depends[] = (string) $needs;
        }

        if (!$this->constraintsParse($base, $package->getName(), $label)) {
            return null;
        }

        $source = $this->confine($packageRoot, $sourceDir, (string) $entry['source'], $package->getName(), $label);

        if ($source === null) {
            return null;
        }

        return [
            'id' => (string) $entry['id'],
            'label' => $label,
            'owner' => $package->getName(),
            'source' => $source,
            'sourceRelative' => (string) $entry['source'],
            'base' => $base,
            'line' => $line,
            'cumulative' => $cumulative,
            'depends' => $depends,
            'description' => isset($entry['description']) ? trim((string) $entry['description']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function label(array $entry, ?string $line): string
    {
        $label = (string) $entry['id'];

        if (isset($entry['label']) && $entry['label'] !== '') {
            $label .= ' ' . $entry['label'];
        }

        return $line === null ? $label : $label . ' [' . $line . ']';
    }

    /**
     * Constraints are checked here rather than where they are evaluated,
     * because a patch can be declared by a third-party package: one typo in
     * someone else's composer.json would otherwise surface as a raw
     * VersionParser exception that fails `composer install` for every consumer,
     * with nothing in the message naming this plugin or offering a way past it.
     *
     * @param array<string, mixed> $base
     */
    private function constraintsParse(array $base, string $owner, string $label): bool
    {
        foreach ($base as $constrained => $constraint) {
            if (!is_string($constraint) && !is_numeric($constraint)) {
                $this->errors[] = sprintf(
                    '%s: patch "%s" has a non-string base constraint for %s',
                    $owner,
                    $label,
                    (string) $constrained
                );

                return false;
            }

            try {
                (new VersionParser())->parseConstraints((string) $constraint);
            } catch (\UnexpectedValueException $e) {
                $this->errors[] = sprintf(
                    '%s: patch "%s" has an unparseable base constraint for %s (%s)',
                    $owner,
                    $label,
                    (string) $constrained,
                    (string) $constraint
                );

                return false;
            }
        }

        return true;
    }

    /**
     * A source must resolve inside the package that declared it. No traversal
     * out, and no remote URLs at all — there is nothing to fetch, so there is
     * nothing to intercept.
     */
    private function confine(
        string $packageRoot,
        string $sourceDir,
        string $source,
        string $owner,
        string $label
    ): ?string {
        if (strpos($source, "\0") !== false) {
            // realpath() throws ValueError on a null byte, and nothing above
            // catches it — so one JSON string in a compromised trusted package
            // would abort composer install for every consumer with a stack
            // trace.
            $this->errors[] = sprintf('%s: patch "%s" has a null byte in its path', $owner, $label);

            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $source) === 1) {
            $this->errors[] = sprintf('%s: patch "%s" uses a remote source, which is not supported', $owner, $label);

            return null;
        }

        // Resolved against the file that named it; confined to the package that
        // shipped that file. An included manifest can point at a sibling patch,
        // and still cannot point outside its own package.
        $candidate = rtrim($sourceDir, '/') . '/' . ltrim($source, '/');
        $real = realpath($candidate);
        $realBase = realpath($packageRoot);

        if ($real !== false && !is_file($real)) {
            // Same reason: a patch source that is a FIFO would block for ever.
            $this->errors[] = sprintf('%s: patch "%s" is not a regular file (%s)', $owner, $label, $source);

            return null;
        }

        if ($real === false || $realBase === false) {
            $this->errors[] = sprintf('%s: patch "%s" points at a missing file (%s)', $owner, $label, $source);

            return null;
        }

        if (strpos($real, rtrim($realBase, '/') . '/') !== 0) {
            $this->errors[] = sprintf('%s: patch "%s" resolves outside its own package', $owner, $label);

            return null;
        }

        return $real;
    }

    /**
     * Does this package's config actually offer patches, as opposed to merely
     * mentioning the key?
     *
     * @param array<string, mixed> $config
     */
    private function declaresPatches(array $config): bool
    {
        foreach (['patches', 'lines', 'include'] as $key) {
            if (isset($config[$key]) && $config[$key] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(PackageInterface $package): array
    {
        $extra = $package->getExtra();

        return isset($extra[self::CONFIG_KEY]) && is_array($extra[self::CONFIG_KEY])
            ? $extra[self::CONFIG_KEY]
            : [];
    }

    /**
     * @return string[]
     */
    private function trustPatterns(RootPackageInterface $root): array
    {
        $config = $this->config($root);
        $trust = $config['trust'] ?? [];

        return is_array($trust) ? array_map('strval', $trust) : [];
    }

    /**
     * @param string[] $patterns
     */
    private function isTrusted(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function rootDirectory(): string
    {
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');

        return dirname((string) $vendorDir);
    }
}
