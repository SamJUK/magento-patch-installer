<?php

// Copyright (c) 2026 Sam James. Licensed under the Open Software License version 3.0.
// SPDX-License-Identifier: OSL-3.0

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

    /** @var array<string, PackageInterface> installed package name => package */
    private $installed = [];

    /** @var array<string, array<string, true>> source => every package it requires */
    private $required = [];

    /** @var array<string, string> replaced or provided name => installed package standing in for it */
    private $replacements = [];

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

        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();

        foreach ($packages as $package) {
            $this->installed[$package->getName()] = $package;

            foreach (array_merge($package->getReplaces(), $package->getProvides()) as $link) {
                $this->replacements[strtolower($link->getTarget())] = $package->getName();
            }
        }

        foreach ($packages as $package) {
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

        // Ordered once, across every source. A store's own patch built on a
        // trusted package's chain is collected first, and ordering each package
        // on its own put it beneath the very months it rewrites.
        $this->collected = $this->select($this->order($this->crossLink($declarations)));

        return $this->collected;
    }

    /**
     * Resolve the `depends` a package could not satisfy itself, against every
     * other source on the same base line: the root and each trusted package.
     *
     * A package's own declaration of an id always wins, so two packages
     * shipping the same ids keep separate chains. Otherwise exactly one other
     * source must declare it. Where several do, a bare id means the one the
     * declarer `require`s: that is declared, not guessed, and it is what stops
     * a third package that happens to ship the same id from making an add-on's
     * patch ambiguous. Anything still unsettled is a configuration error, and
     * the patch is marked so it is refused rather than applied out of order.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<int, array<string, mixed>>
     */
    private function crossLink(array $declarations): array
    {
        $byLineAndId = $linesOf = [];

        foreach ($declarations as $index => $declaration) {
            $line = (string) ($declaration['line'] ?? '');
            $byLineAndId[$line][(string) $declaration['id']][] = $index;
            $linesOf[(string) $declaration['id']][$line] = true;
        }

        foreach ($declarations as $index => $declaration) {
            $line = (string) ($declaration['line'] ?? '');

            foreach ($declaration['pending'] as $reference) {
                // First per owner: one owner declaring an id twice is that
                // owner's "declared twice" error, not two candidates.
                $candidates = [];

                foreach ($byLineAndId[$line][$reference['id']] ?? [] as $other) {
                    $owner = $declarations[$other]['owner'];

                    if ($owner !== $declaration['owner']
                        && ($reference['owner'] ?? $owner) === $owner
                        && !isset($candidates[$owner])) {
                        $candidates[$owner] = $other;
                    }
                }

                if (count($candidates) > 1) {
                    $required = array_intersect_key($candidates, $this->requiredBy((string) $declaration['owner']));

                    if (count($required) === 1) {
                        $candidates = $required;
                    }
                }

                if (count($candidates) === 1) {
                    $declarations[$index]['depends'][] = $declarations[(int) reset($candidates)]['origin'];
                    continue;
                }

                $named = $reference['owner'] === null
                    ? $reference['id']
                    : $reference['owner'] . ':' . $reference['id'];
                $declarations[$index]['unresolved'][] = $named;
                $where = $line === '' ? 'top-level "patches"' : $line;

                if ($candidates === []) {
                    $elsewhere = array_keys(array_diff_key($linesOf[$reference['id']] ?? [], [$line => true]));
                    $declarations[$index]['unresolvedWhy'] = $elsewhere === []
                        ? sprintf('depends on %s, which nothing declares for %s', $named, $where)
                        : sprintf('depends on %s, which is only declared for another base line', $named);

                    $this->errors[] = $elsewhere === []
                        ? sprintf(
                            '%s: patch "%s" depends on "%s", which is not declared for %s',
                            $declaration['owner'],
                            $declaration['label'],
                            $named,
                            $where
                        )
                        : sprintf(
                            '%s: patch "%s" depends on "%s", which is only declared for %s — a patch can only '
                            . 'build on one in its own base line, so declare it in that line instead of %s',
                            $declaration['owner'],
                            $declaration['label'],
                            $named,
                            implode(', ', array_map(static function ($other): string {
                                return (string) $other === '' ? 'top-level "patches"' : (string) $other;
                            }, $elsewhere)),
                            $where
                        );
                    continue;
                }

                $owners = array_keys($candidates);
                $declarations[$index]['unresolvedWhy'] = sprintf(
                    'depends on %s, which %s all declare',
                    $named,
                    implode(', ', $owners)
                );

                // Only the root can be told to fix its own config. A package's
                // declarations are its author's, so say whose they are.
                $this->errors[] = $declaration['owner'] === $this->composer->getPackage()->getName()
                    ? sprintf(
                        '%s: patch "%s" depends on "%s", which %s all declare for %s — name one, e.g. "%s:%s"',
                        $declaration['owner'],
                        $declaration['label'],
                        $named,
                        implode(', ', $owners),
                        $where,
                        $owners[0],
                        $reference['id']
                    )
                    : sprintf(
                        '%s: patch "%s" depends on "%s", which %s all declare for %s — %s should require the '
                        . 'package it builds on, or name it as "vendor/package:%s"',
                        $declaration['owner'],
                        $declaration['label'],
                        $named,
                        implode(', ', $owners),
                        $where,
                        $declaration['owner'],
                        $reference['id']
                    );
            }
        }

        return $declarations;
    }

    /**
     * Every package a source requires, directly or through other packages.
     *
     * @return array<string, true>
     */
    private function requiredBy(string $owner): array
    {
        if (isset($this->required[$owner])) {
            return $this->required[$owner];
        }

        $root = $this->composer->getPackage();
        $queue = $owner === $root->getName()
            ? array_merge(array_keys($root->getRequires()), array_keys($root->getDevRequires()))
            : (isset($this->installed[$owner]) ? array_keys($this->installed[$owner]->getRequires()) : []);
        $seen = [];

        while ($queue !== []) {
            $name = strtolower((string) array_pop($queue));

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            // A fork installed in its place replaces it by name.
            if (!isset($this->installed[$name]) && isset($this->replacements[$name])) {
                $queue[] = $this->replacements[$name];
                continue;
            }

            if (isset($this->installed[$name])) {
                $queue = array_merge($queue, array_keys($this->installed[$name]->getRequires()));
            }
        }

        return $this->required[$owner] = $seen;
    }

    /**
     * Does $owner require $other, directly or through other packages?
     */
    public function requires(string $owner, string $other): bool
    {
        return isset($this->requiredBy($owner)[strtolower($other)]);
    }

    /**
     * A `depends` entry is an id, or `vendor/package:id` when the id alone
     * could mean a patch in more than one package.
     *
     * @return array{owner: string|null, id: string}
     */
    private function reference(string $needs): array
    {
        if (preg_match('#^([a-z0-9_.-]+/[a-z0-9_.-]+):(.+)$#', $needs, $matches) === 1) {
            return ['owner' => $matches[1], 'id' => $matches[2]];
        }

        return ['owner' => null, 'id' => $needs];
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

            // This package's own dependents only. Counting another source's
            // would let any trusted package cancel the store's skip of this
            // one just by depending on it — and the skipped patch is applied.
            // The cascade still takes every dependent down, whoever owns it.
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

        // Globally unique. Origins used to restart at 0 for each package, and
        // every consumer built its lookup over the merged list, so the last
        // package silently owned every earlier package's edges.
        foreach ($declarations as $index => $declaration) {
            $declarations[$index]['origin'] = $declaration['owner'] . '#' . $index;
        }

        return $declarations;
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
                $reference = $this->reference((string) $needs);
                $target = $reference['owner'] === null || $reference['owner'] === $package->getName()
                    ? $byId[$reference['id']] ?? null
                    : null;

                if ($target === null) {
                    // Not this package's, so possibly another source's: settled
                    // once every trusted package has been read.
                    $declarations[$index]['pending'][] = $reference;
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
     * A package goes after the packages it requires. That is how an add-on
     * says it builds on one, and it is a constraint, not a starting point: a
     * store's private package sorting first, or anything that depends on its
     * patch reaching it first, otherwise put it beneath the chain it extends.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<int, array<string, mixed>>
     */
    private function order(array $declarations): array
    {
        $ordered = [];
        $state = [];
        $position = [];
        $cyclic = [];

        foreach ($declarations as $index => $declaration) {
            $position[$declaration['origin']] = $index;
        }

        // Every declaration of the packages each owner requires, and not the
        // other way round. The root requires everything and is ordered by its
        // `depends` alone.
        $root = $this->composer->getPackage()->getName();
        $byOwner = $beneath = $entered = [];

        foreach ($declarations as $index => $declaration) {
            $byOwner[(string) $declaration['owner']][] = $index;
        }

        foreach (array_keys($byOwner) as $owner) {
            foreach ($byOwner as $other => $indices) {
                if ((string) $owner !== $root
                    && $other !== $owner
                    && $this->requires((string) $owner, (string) $other)
                    && !$this->requires((string) $other, (string) $owner)) {
                    $beneath[$owner] = array_merge($beneath[$owner] ?? [], $indices);
                }
            }
        }

        // What each patch needs before it: what it depends on, and through one
        // node per package rather than an edge from every link to every link,
        // every patch of the packages its package requires.
        $needs = [];

        foreach ($declarations as $index => $declaration) {
            foreach ($declaration['depends'] as $origin) {
                if (isset($position[$origin])) {
                    $needs[$index][] = $position[$origin];
                }
            }

            if (isset($beneath[(string) $declaration['owner']])) {
                $needs[$index][] = 'package:' . $declaration['owner'];
            }
        }

        foreach ($beneath as $owner => $indices) {
            $needs['package:' . $owner] = $indices;
        }

        $refused = $this->unorderable($declarations, $needs, $position);

        foreach (array_keys($refused) as $index) {
            $cyclic[$declarations[$index]['origin']] = true;
        }

        $visit = function (int $index) use (&$visit, &$ordered, &$state, &$entered, $refused, $beneath, $declarations, $position): void {
            // Done, or open: every cycle was settled above by refusing a patch
            // in it, and a refused patch's prerequisites, its package's
            // included, are not followed. Followed, they could reach a patch
            // built on it and place that first, before it is known refused.
            if (isset($state[$index])) {
                return;
            }

            $state[$index] = 'open';
            $owner = (string) $declarations[$index]['owner'];

            if (!isset($refused[$index]) && !isset($entered[$owner])) {
                $entered[$owner] = true;

                foreach ($beneath[$owner] ?? [] as $required) {
                    $visit($required);
                }
            }

            // Edges are origins, so one crossing into another package is
            // followed exactly like one inside it.
            if (!isset($refused[$index])) {
                foreach ($declarations[$index]['depends'] as $needed) {
                    if (isset($position[$needed])) {
                        $visit($position[$needed]);
                    }
                }
            }

            $state[$index] = 'done';
            $ordered[] = $declarations[$index];
        };

        foreach (array_keys($declarations) as $index) {
            $visit($index);
        }

        foreach ($ordered as $index => $declaration) {
            $ordered[$index]['cyclic'] = isset($cyclic[$declaration['origin']]);
        }

        return $ordered;
    }

    /**
     * The patches no order can place, refused so the rest can be ordered.
     *
     * A cycle of `depends` alone has no order in which any of it is right, so
     * all of it is refused. A cycle through a require is a `depends` running
     * against that require: a patch whose prerequisites lead, by `depends`, to
     * a patch in a package that requires the patch's own. Only that patch is
     * refused, with what builds on it. The patch it depends on did nothing
     * wrong, and a library a security package requires must not be able to
     * switch that package's patch off. A cycle through requires where no
     * `depends` runs against one refuses the patches whose `depends` crosses
     * into another package in it, and not those only there through their
     * package or their own package's chain. Settled on the graph as a whole,
     * then again on what is left, until nothing loops.
     *
     * @param array<int, array<string, mixed>> $declarations
     * @param array<int|string, array<int, int|string>> $needs node => nodes needed before it; packages are "package:<name>"
     * @param array<string, int> $position
     *
     * @return array<int, true>
     */
    private function unorderable(array $declarations, array $needs, array $position): array
    {
        $root = $this->composer->getPackage()->getName();
        $refused = [];
        $cycles = 0;
        $owner = static function (int $index) use ($declarations): string {
            return (string) $declarations[$index]['owner'];
        };

        do {
            $found = [];
            $edges = [];

            foreach ($needs as $node => $targets) {
                if (!is_int($node) || !isset($refused[$node])) {
                    $edges[$node] = $targets;
                }
            }

            foreach (self::components(array_merge(array_keys($declarations), array_keys($needs)), $edges) as $component) {
                if (count($component) === 1 && !in_array($component[0], $edges[$component[0]] ?? [], true)) {
                    continue;
                }

                $members = array_flip($component);
                $patches = array_values(array_filter($component, 'is_int'));
                $against = $dependents = $declarers = $byOwner = [];

                foreach ($patches as $patch) {
                    $byOwner[$owner($patch)][] = $patch;

                    foreach ($this->dependsOn($declarations[$patch], $position, $members) as $needed) {
                        $dependents[$needed][] = $patch;

                        if (count($patches) === count($component) || $owner($needed) !== $owner($patch)) {
                            $declarers[$patch] = $patch;
                        }
                    }
                }

                if (count($patches) < count($component)) {
                    // Walked back from each package's patches, once per package:
                    // a patch that leads to one by depends, from a package that
                    // package requires, runs against the require.
                    foreach ($byOwner as $theirs => $targets) {
                        $theirs = (string) $theirs;

                        if ($theirs === $root) {
                            continue;
                        }

                        $queue = [];
                        $seen = [];

                        foreach ($targets as $target) {
                            $queue[] = [$target, $target];
                        }

                        while ($queue !== []) {
                            [$node, $target] = array_pop($queue);

                            foreach ($dependents[$node] ?? [] as $before) {
                                if (isset($seen[$before])) {
                                    continue;
                                }

                                $seen[$before] = true;
                                $queue[] = [$before, $target];
                                $mine = $owner($before);

                                if (!isset($against[$before]) && $mine !== $root && $mine !== $theirs
                                    && $this->requires($theirs, $mine) && !$this->requires($mine, $theirs)) {
                                    $against[$before] = ['depends' => $node, 'leads' => $owner($node) === $theirs ? $node : $target];
                                }
                            }
                        }
                    }

                    ksort($against);
                }

                if ($cycles++ === 0) {
                    $first = $against === [] ? null : (int) array_key_first($against);
                    $named = $first === null ? (int) reset($declarers) : $first;
                    $this->errors[] = $first === null
                        ? sprintf('%s: "%s" is part of a dependency cycle', $owner($named), $declarations[$named]['label'])
                        : sprintf(
                            '%s: "%s" depends on "%s"%s, but %s requires %s, so its patches go first — '
                            . 'the depends runs against the require, and "%s" is refused',
                            $owner($first),
                            $declarations[$first]['label'],
                            $declarations[$against[$first]['depends']]['label'],
                            $against[$first]['depends'] === $against[$first]['leads']
                                ? ''
                                : sprintf(', which leads to "%s"', $declarations[$against[$first]['leads']]['label']),
                            $owner($against[$first]['leads']),
                            $owner($first),
                            $declarations[$first]['label']
                        );
                }

                // Nothing against a require: refusing what declares a depends into
                // another package in the cycle breaks it. Requires alone never
                // loop, and a depends within a package, a chain's link to the one
                // before, keeps to the same package. A patch that only sits in
                // it, through its package or its own chain, did nothing wrong.
                foreach ($against === [] ? $declarers : array_keys($against) as $patch) {
                    $found[$patch] = true;
                }
            }

            $refused += $found;
        } while ($found !== []);

        if ($cycles > 1) {
            $this->errors[] = sprintf('%d further dependency cycles were not listed', $cycles - 1);
        }

        return $refused;
    }

    /**
     * The patches among $members that $declaration names in `depends`.
     *
     * @param array<string, mixed> $declaration
     * @param array<string, int> $position
     * @param array<int|string, int> $members
     *
     * @return int[]
     */
    private function dependsOn(array $declaration, array $position, array $members): array
    {
        $out = [];

        foreach ($declaration['depends'] as $origin) {
            if (isset($position[$origin], $members[$position[$origin]])) {
                $out[] = $position[$origin];
            }
        }

        return $out;
    }

    /**
     * Strongly connected components (Tarjan), without recursion: a chain of
     * thousands is a stack that deep.
     *
     * @param array<int, int|string> $nodes
     * @param array<int|string, array<int, int|string>> $edges
     *
     * @return array<int, array<int, int|string>>
     */
    private static function components(array $nodes, array $edges): array
    {
        $inGraph = array_flip($nodes);
        $order = $low = $onStack = [];
        $stack = $components = [];
        $counter = 0;

        foreach ($nodes as $start) {
            if (isset($order[$start])) {
                continue;
            }

            $order[$start] = $low[$start] = $counter++;
            $stack[] = $start;
            $onStack[$start] = true;
            $work = [[$start, 0]];

            while ($work !== []) {
                $top = count($work) - 1;
                [$node, $next] = $work[$top];
                $targets = $edges[$node] ?? [];

                if ($next < count($targets)) {
                    $work[$top][1]++;
                    $to = $targets[$next];

                    if (!isset($inGraph[$to])) {
                        continue;
                    }

                    if (!isset($order[$to])) {
                        $order[$to] = $low[$to] = $counter++;
                        $stack[] = $to;
                        $onStack[$to] = true;
                        $work[] = [$to, 0];
                    } elseif (isset($onStack[$to])) {
                        $low[$node] = min($low[$node], $order[$to]);
                    }

                    continue;
                }

                array_pop($work);

                if ($work !== []) {
                    $parent = $work[count($work) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                }

                if ($low[$node] === $order[$node]) {
                    $component = [];

                    while ($stack !== []) {
                        $member = array_pop($stack);
                        unset($onStack[$member]);
                        $component[] = $member;

                        if ($member === $node) {
                            break;
                        }
                    }

                    $components[] = $component;
                }
            }
        }

        return $components;
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
            // `depends` this package could not satisfy itself, until crossLink()
            // settles them; and the ones nothing satisfies, which block the patch.
            'pending' => [],
            'unresolved' => [],
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
