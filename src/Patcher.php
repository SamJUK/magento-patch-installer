<?php

// Copyright (c) 2026 Sam James. Licensed under the Open Software License version 3.0.
// SPDX-License-Identifier: OSL-3.0

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Util\ProcessExecutor;

/**
 * Classifies every patch target against the working tree, and optionally
 * applies what is missing.
 *
 * The working tree is the source of truth. Nothing here consults a record of
 * what was applied last time, which is why a package reinstall that quietly
 * reverts a patched file is detected rather than believed.
 */
class Patcher
{
    /** @var Composer */
    private $composer;

    /** @var Collector */
    private $collector;

    /** @var string[] Problems found while reading patches, as opposed to declaring them. */
    private $errors = [];

    /** @var Splitter */
    private $splitter;

    /** @var TargetResolver */
    private $resolver;

    /** @var Applier */
    private $applier;

    /** @var array<string, string> package name => installed version */
    private $installed = [];

    /** @var array<string, string> replaced package name => replacing package */
    private $replacedBy = [];

    /** @var array<string, string> write path => label of the skip that emptied it */
    private $vacated = [];

    /** @var State */
    private $state;

    /** @var array<string, Fragment[]> patch source => fragments */
    private $splits = [];

    /** @var array<int, array<string, Fragment[]>> declaration => file key => later links built on it */
    private $above = [];

    /** @var array<int, array<string, Fragment[]>> declaration => file key => earlier links it is built on */
    private $below = [];

    /** @var bool */
    private $applying = false;

    /** @var array<string, array<string, true>> deploying package => root files it ships that are missing */
    private $missingRoots = [];

    /** @var array<int, array<string, mixed>>|null in apply order, with the order requires() implies */
    private $declarations;

    /** @var array<string, string>|null origin => why its order is undefined, once worked out */
    private $unordered;

    /** @var string[] the same, between two trusted packages, where it is only a warning */
    private $unorderedWarnings = [];

    /**
     * What the patch being written found at each path before it first wrote
     * there, keyed by absolute path, so a failure can put every one of them back.
     *
     * @var array<string, array{fragment: Fragment, existed: bool, contents: string|false, mode: int, dirs: string[]}>
     */
    private $journal = [];

    public function __construct(Composer $composer, ProcessExecutor $process)
    {
        $this->composer = $composer;
        $this->collector = new Collector($composer);
        $this->splitter = new Splitter();

        // Composer makes vendor-dir absolute but does not resolve symlinks,
        // while install paths below are canonicalised. On a deploy layout where
        // the docroot is a symlink the two never share a prefix, and every
        // root-file mapping is silently dropped — which is exactly the
        // half-patched state this plugin exists to prevent.
        $vendorDir = (string) $composer->getConfig()->get('vendor-dir');
        $projectRoot = dirname($vendorDir);
        $projectRoot = realpath($projectRoot) ?: $projectRoot;
        $this->applier = new Applier($process, $projectRoot);
        $this->resolver = new TargetResolver(
            $projectRoot,
            $this->rootMappings($projectRoot, dirname($vendorDir)),
            basename($vendorDir)
        );
        $this->state = new State($projectRoot);

        $this->indexPackages();
    }

    public static function fromComposer(Composer $composer, IOInterface $io): self
    {
        return new self($composer, new ProcessExecutor($io));
    }

    /**
     * What this project's trusted packages declare, without looking at the
     * working tree at all.
     *
     * The catalogue answers a different question from the report: not "where
     * does this store stand" but "what does this package cover". It runs no git
     * and changes nothing. Base constraints are still checked, against what
     * Composer records as installed rather than against the files.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $entries = [];
        $declarations = $this->declarations();

        $unordered = $this->unordered($declarations);
        $refused = [];
        $ids = $owners = [];
        foreach ($declarations as $declaration) {
            $ids[$declaration['origin']] = (string) $declaration['id'];
            $owners[$declaration['origin']] = (string) $declaration['owner'];
        }

        foreach ($declarations as $declaration) {
            $mismatch = $this->baseMismatch($declaration['base']);
            $targets = count($this->fragments($declaration));

            // The catalogue never runs runOne(), so it never saw an unreadable
            // patch file. It printed "0 targets — applies here" and exited 0,
            // while status and verify called the same file a config error and
            // exited 3. magento-patches:list is the command someone runs on a fresh
            // clone while deciding whether to trust a package, which is exactly
            // where a truncated patch most needs to be visible.
            if ($mismatch === null && $targets === 0) {
                $this->errors[] = sprintf(
                    '%s: patch "%s" could not be read as a diff (%s)',
                    $declaration['owner'],
                    $declaration['label'],
                    $declaration['sourceRelative']
                );

                $mismatch = 'could not be read as a diff';
            }

            // Refused here exactly as status refuses it, and so is everything
            // built on it: the catalogue saying "applies" where status says
            // "blocked" is two answers to one question.
            // A patch the store skips is reported as skipped, as status does,
            // whatever else is wrong with it.
            if ($mismatch === null && ($declaration['skipped'] ?? null) === null) {
                $mismatch = $this->unplaced($declaration, $unordered) ?? $this->contested($declaration);

                foreach ($declaration['depends'] as $needs) {
                    if ($mismatch === null && isset($refused[$needs])) {
                        $mismatch = sprintf('requires %s, which is not applied', $refused[$needs]);
                    }
                }

                if ($mismatch !== null) {
                    $refused[$declaration['origin']] = $declaration['label'];
                }
            }

            $entries[] = [
                'id' => $declaration['id'],
                'label' => $declaration['label'],
                'owner' => $declaration['owner'],
                'skipped' => $declaration['skipped'] ?? null,
                'source' => $declaration['sourceRelative'],
                'line' => $declaration['line'],
                'description' => $declaration['description'],
                // Ids rather than the resolved indices: the catalogue is read
                // by a person deciding whether this package covers them. One in
                // another package is named the way `depends` would name it.
                'depends' => array_values(array_merge(array_map(
                    static function (string $origin) use ($ids, $owners, $declaration): string {
                        $owner = $owners[$origin] ?? null;

                        return $owner === null || $owner === $declaration['owner']
                            ? ($ids[$origin] ?? '?')
                            : $owner . ':' . $ids[$origin];
                    },
                    $declaration['depends']
                ), $declaration['unresolved'] ?? [])),
                'targets' => $targets,
                'applicable' => $mismatch === null && ($declaration['skipped'] ?? null) === null,
                'reason' => $declaration['skipped'] ?? $mismatch,
            ];
        }

        return $entries;
    }

    /**
     * The store itself: what it is, whether it is still supported, where its
     * patches come from and when they were last written.
     *
     * @return array<string, mixed>
     */
    public function environment(): array
    {
        $sources = [];

        foreach ($this->collector->collect() as $declaration) {
            $owner = (string) $declaration['owner'];
            $sources[$owner] = ($sources[$owner] ?? 0) + 1;
        }

        $lifecycle = new Lifecycle();

        return [
            'product' => Lifecycle::product($this->installed),
            'lifecycle' => $lifecycle->check($this->installed),
            'sources' => $sources,
            'lastApplied' => $this->state->writtenAt(),
            'manifest' => $lifecycle->generated(),
        ];
    }

    /**
     * Things worth saying out loud that are not per-patch verdicts.
     *
     * Being behind on patch releases is the most common real reason a store is
     * unprotected: the isolated patches only apply to the newest release of
     * their line, so a store on 2.4.8-p3 quietly receives none of the 2.4.8-p5
     * ones. Reading a README should not be how anyone discovers that.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public function advisories(): array
    {
        $notices = [];

        $lifecycle = (new Lifecycle())->advisory($this->installed);
        if ($lifecycle !== null) {
            $notices[] = $lifecycle;
        }

        // Patched in the package copy instead, so nothing failed. But the store
        // is serving without them until they are deployed again.
        foreach ($this->missingRoots as $package => $paths) {
            $paths = array_keys($paths);
            sort($paths);
            $many = count($paths) > 1;
            $notices[] = ['level' => 'warning', 'message' => sprintf(
                '%d root file%s that %s deploys %s missing, %s. Patches to %s went to its copy in vendor/, '
                . 'which the next deploy serves from. Put %s back with: composer reinstall %s',
                count($paths),
                $many ? 's' : '',
                $package,
                $many ? 'are' : 'is',
                $many ? 'including ' . $paths[0] : $paths[0],
                $many ? 'them' : 'it',
                $many ? 'them' : 'it',
                $package
            )];
        }

        $stale = [];

        foreach ($this->collector->collect() as $declaration) {
            if ($declaration['line'] === null) {
                continue;
            }

            foreach ($declaration['base'] as $package => $_) {
                $installed = $this->installed[$package] ?? null;

                if ($installed === null || !$this->sameLine((string) $declaration['line'], $installed)) {
                    continue;
                }

                if (Comparator::greaterThan((string) $declaration['line'], $installed)) {
                    $stale[sprintf(
                        '%s is on %s, but patches here are built for %s. '
                        . 'Isolated patches only apply to the newest release of their line — upgrade to receive them.',
                        $package,
                        $installed,
                        $declaration['line']
                    )] = true;
                }
            }
        }

        foreach (array_keys($stale) as $message) {
            $notices[] = ['level' => 'warning', 'message' => $message];
        }

        $this->unordered($this->declarations());

        foreach ($this->unorderedWarnings as $message) {
            $notices[] = ['level' => 'warning', 'message' => $message];
        }

        return $notices;
    }

    /**
     * Same release line, differing only in patch level: 2.4.8-p3 and 2.4.8-p5
     * are, 2.4.8-p3 and 2.4.9 are not.
     */
    private function sameLine(string $a, string $b): bool
    {
        $trim = static function (string $version): string {
            return (string) preg_replace('/-p\d+$/', '', $version);
        };

        return $trim($a) === $trim($b);
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return array_merge($this->collector->errors(), $this->errors);
    }

    public function allowsUnpatched(): bool
    {
        return $this->collector->allowsUnpatched();
    }

    public function isDryRun(): bool
    {
        return $this->collector->isDryRun();
    }

    /**
     * @return array<int, array<string, mixed>> one entry per declared patch
     */
    public function run(bool $apply): array
    {
        $results = [];
        $broken = [];
        $unplaced = [];
        $declarations = $this->declarations();

        $lastCreator = $this->lastCreators($declarations);
        $this->applying = $apply;
        $this->stacks($declarations);
        $unordered = $this->unordered($declarations);

        // Reversals run first, and in reverse dependency order. A patch cannot
        // come off while another sits on top of it, so walking forward — the
        // order everything else uses — leaves the first link of a chain stuck
        // until a second run, and calls the store clean in between.
        $reversals = [];

        foreach (array_reverse($declarations, true) as $index => $declaration) {
            if (($declaration['skipped'] ?? null) === null || $this->baseMismatch($declaration['base']) !== null) {
                continue;
            }

            $reversals[$declaration['origin']] = array_merge($declaration, [
                'applicable' => false,
                'blocked' => false,
                'reason' => $declaration['skipped'],
                'fragments' => $this->unwanted($declaration, $apply, $index),
            ]);
        }

        foreach ($declarations as $index => $declaration) {
            if (($declaration['skipped'] ?? null) !== null) {
                if (isset($reversals[$declaration['origin']])) {
                    $results[] = $reversals[$declaration['origin']];
                    continue;
                }

                // A bare id skips that id in every base line, so most of what it
                // matches is built for a version this store is not on. Those are
                // not holes in this store's coverage and must not be counted or
                // shouted about as if they were — the other-versions footnote
                // already covers them.
                $results[] = array_merge($declaration, [
                    'applicable' => false,
                    'blocked' => false,
                    'skipped' => null,
                    'reason' => $this->baseMismatch($declaration['base']),
                    'fragments' => [],
                ]);
                continue;
            }

            // A prerequisite nothing declares has no place in the order, so the
            // patch has none either. Applying it anyway is how a store's own
            // month lands beneath the months it rewrites. Refused in every
            // mode, not just apply: there is no order in which it is right.
            // Another version's patch is that, here, whatever is wrong with it:
            // list says so, and a store is not blocked by a patch it cannot run.
            // A bad `depends` is still a configuration error, from the Collector.
            $unplacedBecause = $this->baseMismatch($declaration['base']) !== null
                ? null
                : $this->unplaced($declaration, $unordered) ?? $this->contested($declaration);

            if ($unplacedBecause !== null) {
                $results[] = array_merge($declaration, [
                    'applicable' => false,
                    'blocked' => true,
                    'reason' => $unplacedBecause,
                    'fragments' => [],
                ]);
                $broken[$declaration['origin']] = $declaration['label'];
                $unplaced[$declaration['origin']] = true;
                continue;
            }

            // Adobe stipulates each monthly isolated patch depends on the ones
            // before it. Once a prerequisite is missing, everything built on it
            // is refused by name rather than attempted — a hunk failure three
            // patches later is a much worse way to learn the same thing. That is
            // an apply-time guard, except over a prerequisite with no place in
            // the order, which is refused in every mode.
            $missing = null;
            $unplacedBelow = false;

            foreach ($declaration['depends'] as $needs) {
                if (isset($broken[$needs]) && ($apply || isset($unplaced[$needs]))) {
                    $missing = $broken[$needs];
                    $unplacedBelow = isset($unplaced[$needs]);
                    break;
                }
            }

            if ($missing !== null) {
                $results[] = array_merge($declaration, [
                    'applicable' => false,
                    'blocked' => true,
                    'reason' => sprintf('requires %s, which is not applied', $missing),
                    'fragments' => [],
                ]);
                // Keyed by origin, not label: two packages can ship the same id
                // for the same base version, and one must not be able to block
                // the other's chain.
                $broken[$declaration['origin']] = $declaration['label'];

                if ($unplacedBelow) {
                    $unplaced[$declaration['origin']] = true;
                }

                continue;
            }

            $result = $this->runOne($declaration, $apply, $index, $lastCreator);
            $results[] = $result;

            // Blocking is an apply-time guard. When only reporting, every patch
            // is classified on its own merits: an earlier link being unapplied
            // is not a reason to say nothing is known about the later ones,
            // because an actual apply would have applied it first.
            if ($apply && $this->incomplete($result)) {
                $broken[$declaration['origin']] = $declaration['label'];
            }
        }

        // Apply-time only. When reporting, the earlier creator genuinely is
        // superseded — an actual apply would have run the later one — so
        // "did not land" is meaningless and the correction would say so anyway.
        if ($apply) {
            $results = $this->correctSupersedes($results, $lastCreator);
        }

        $this->state->save();

        return $results;
    }

    /**
     * Take a skipped patch back off the working tree.
     *
     * "Skipped" has to mean the patch is not there, not merely that it was not
     * applied on top. On a store built fresh in CI the two are the same thing
     * and this does nothing. On a tree that has had `composer install` run
     * against it for six months, the patch is already in vendor/ and only this
     * removes it — which is what makes the two deployment styles agree.
     *
     * It is the one place this plugin removes a security patch on its own, so
     * it happens only for a patch the project named in its own config with a
     * written reason, it reports every file it touched, and it refuses rather
     * than forces when the reversal is not clean.
     *
     * @param array<string, mixed> $declaration
     *
     * @return Fragment[]
     */
    private function unwanted(array $declaration, bool $apply, int $index): array
    {
        $fragments = $this->fragments($declaration);
        $removed = $failed = false;
        $vacated = [];
        $this->journal = [];

        foreach ($fragments as $fragment) {
            // Nothing of the patch can be in a module the store does not
            // install, and reversing a deletion there would write the file.
            $absent = $this->notHere($fragment);

            if ($absent !== null) {
                $fragment->resolve(Fragment::SKIPPED, $absent);
                continue;
            }

            $target = $fragment->target();
            $primary = $this->resolver->writePathFor($target);
            $diff = $primary === $target
                ? $fragment->diff()
                : $this->applier->retarget($fragment->diff(), $target, $primary);

            $mirror = $this->mirrorOf($target, $primary);
            $mirrorPath = $mirror === null ? null : $this->resolver->writePathFor($mirror);
            $mirrorDiff = $mirrorPath === null
                ? null
                : $this->applier->retarget($fragment->diff(), $target, $mirrorPath);

            // A reverse check of a mode change alone passes whatever the mode,
            // so for one of those the mode is what says it is there.
            $modeOnly = $fragment->newMode() !== null && !$fragment->changesContent();

            // The mirror is checked even when the vendor copy is clean. A
            // magento2-base deploy leaves the patch in the served copy and not
            // the package one, and looking only at the primary calls that
            // "already gone".
            $here = $this->applier->isAppliedBeneath($diff, $primary, $this->above($index, $target, $primary))
                && (!$modeOnly || $this->hasMode($fragment, $primary));
            $there = $mirrorPath !== null && $mirrorDiff !== null
                && $this->applier->isAppliedBeneath($mirrorDiff, $mirrorPath, $this->above($index, $target, $mirrorPath))
                && (!$modeOnly || $this->hasMode($fragment, $mirrorPath));

            if (!$here && !$there) {
                // Absent is only one of the reasons a reverse-check fails. The
                // others — local edits, another patch stacked on top — mean
                // nobody can say whether it is there, and reporting that as
                // "not present" leaves the store running it.
                // Asked with the links it is built on put back: they are usually skipped and gone too.
                if (
                    file_exists($this->resolver->absolute($primary))
                    && !$this->applier->canApplyAbove($diff, $primary, $this->below($index, $target, $primary))
                ) {
                    $fragment->resolve(
                        Fragment::CONFLICT,
                        'cannot tell whether it is applied: ' . $this->applier->lastError()
                    );
                    $failed = true;
                    continue;
                }

                $fragment->resolve(Fragment::SKIPPED);
                continue;
            }

            // Two packages ship the file, so there is no one other copy to
            // take it off as well, and the next deploy could put it back.
            $contest = $this->resolver->contested($target);

            if ($contest !== null) {
                $fragment->resolve(Fragment::CONFLICT, 'not taken off: ' . $contest);
                $failed = true;
                continue;
            }

            $unrenamable = $this->unrenamable($fragment, $primary, $mirror);

            if ($unrenamable !== null) {
                $fragment->resolve(Fragment::CONFLICT, $unrenamable);
                $failed = true;
                continue;
            }

            if (!$apply) {
                $fragment->resolve(Fragment::UNWANTED, 'still present; magento-patches:apply will remove it');
                continue;
            }

            if ($here) {
                $this->remember($primary, $fragment);

                if ($fragment->renamedFrom() !== null) {
                    $this->remember((string) $fragment->renamedFrom(), $fragment);
                }

                if (!$this->applier->revert($diff)) {
                    $fragment->resolve(Fragment::CONFLICT, $this->applier->lastError());
                    $failed = true;
                    continue;
                }

                $fragment->recordWrite($primary);
            }

            // The other copy of a root-mapped file has to come off too, or the
            // next deploy copies the patch straight back over the first.
            if ($there) {
                $this->remember((string) $mirrorPath, $fragment);

                if (!$this->applier->revert((string) $mirrorDiff)) {
                    $fragment->resolve(Fragment::MIRROR_CONFLICT, $this->applier->lastError());
                    $failed = true;
                    continue;
                }

                $fragment->recordWrite((string) $mirror);
            }

            $fragment->resolve(Fragment::REVERTED);
            $removed = true;

            // Reverting a creating hunk deletes the file. Anything patched on
            // top of it then finds nothing there, and an absent target with a
            // modification hunk is otherwise read as "this module does not ship
            // this file" — a NOT_APPLICABLE that exits 0 under a green banner.
            if ($fragment->createsFile()) {
                $vacated[$primary] = (string) $declaration['label'];

                if ($mirror !== null) {
                    $vacated[$mirror] = (string) $declaration['label'];
                }
            }
        }

        // Off whole or not at all, as a patch goes on: half a security patch
        // is neither what the store asked for nor what it had.
        if ($failed) {
            $this->rollback($declaration, Fragment::UNWANTED, 'another file in "%s" would not come off');

            return $fragments;
        }

        $this->journal = [];
        $this->vacated = array_merge($this->vacated, $vacated);

        if ($removed) {
            $this->state->forget($declaration['owner'] . ' ' . $declaration['label']);
        }

        return $fragments;
    }

    /**
     * A patch only supersedes an earlier one if it actually lands.
     *
     * lastCreators() decides which declaration owns a created path before any
     * of them run, so a creator that is then chain-blocked or conflicts leaves
     * the earlier patches reporting "superseded" for a file that does not
     * exist. The run is already failing at that point, but the counts would say
     * the earlier patch had nothing to do — and the counts are the thing this
     * plugin asks people to trust.
     *
     * @param array<int, array<string, mixed>> $results
     * @param array<string, int> $lastCreator
     *
     * @return array<int, array<string, mixed>>
     */
    private function correctSupersedes(array $results, array $lastCreator): array
    {
        foreach ($lastCreator as $key => $index) {
            $parts = explode("\0", (string) $key, 2);
            $owner = $parts[0];
            $target = $parts[1] ?? '';

            if ($this->landed($results[$index] ?? null, $target)) {
                continue;
            }

            foreach ($results as $position => $result) {
                if ($position === $index || ($result['applicable'] ?? false) === false) {
                    continue;
                }

                // Only the package that owns the supersede chain. A patch from
                // elsewhere that happens to touch the same path was never
                // superseded by this one, so it has nothing to be corrected to.
                if (($result['owner'] ?? null) !== $owner) {
                    continue;
                }

                foreach ($result['fragments'] as $fragment) {
                    /** @var Fragment $fragment */
                    if ($fragment->target() === $target && $fragment->verdict() === Fragment::NOT_APPLICABLE) {
                        $fragment->resolve(
                            Fragment::APPLICABLE,
                            'the patch that replaces this one did not apply, so nothing wrote ' . $target
                        );
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Did this result put the named target on disk, or find it already there?
     *
     * @param array<string, mixed>|null $result
     */
    private function landed(?array $result, string $target): bool
    {
        if ($result === null || ($result['applicable'] ?? false) === false) {
            return false;
        }

        foreach ($result['fragments'] as $fragment) {
            /** @var Fragment $fragment */
            if ($fragment->target() !== $target) {
                continue;
            }

            return $fragment->wasWritten() || $fragment->verdict() === Fragment::ALREADY;
        }

        return false;
    }

    /**
     * For every file created by a new-file hunk, the last declaration that
     * creates it.
     *
     * Adobe regenerates vendor/bin/patch-status with each monthly release, so
     * several patches in a line create the same path with different content.
     * Without this, each run would let every one of them rewrite the file in
     * turn — stable in its end state but never idempotent, and noisy forever.
     * Only the last creator acts; the earlier ones report that they have been
     * superseded.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<string, int> target path => declaration index
     */
    private function lastCreators(array $declarations): array
    {
        $creators = [];

        foreach ($declarations as $index => $declaration) {
            if (($declaration['skipped'] ?? null) !== null) {
                continue;
            }

            if ($this->baseMismatch($declaration['base']) !== null) {
                continue;
            }

            foreach ($this->fragments($declaration) as $fragment) {
                // A creation in a module this store does not install never
                // lands, so it supersedes nothing: counted, it flipped the
                // earlier creators to "the patch that replaces this one did not
                // apply", failing every apply while verify said 0.
                if ($fragment->createsFile() && $this->notHere($fragment) === null) {
                    // Keyed by owner as well as target. Adobe regenerates
                    // vendor/bin/patch-status every month, so month two's
                    // new-file hunk supersedes month one's — within the package
                    // that ships them both. Keyed by target alone, any trusted
                    // package that sorted later could claim a path another
                    // package creates, and the real security patch was marked
                    // "superseded by a later patch in this line" — not covered,
                    // exit 0, green banner. State::wasWrittenBy() is owner
                    // scoped for the same reason; this was not.
                    $creators[$declaration['owner'] . "\0" . $fragment->target()] = $index;
                }
            }
        }

        return $creators;
    }

    /**
     * Why a patch has no place in the order and is refused in every mode, or
     * null: a prerequisite nothing settles, a dependency cycle, or the store's
     * own patch with no order against a package's (unordered()).
     *
     * @param array<string, mixed> $declaration
     * @param array<string, string> $unordered
     */
    private function unplaced(array $declaration, array $unordered): ?string
    {
        if (($declaration['unresolved'] ?? []) !== []) {
            return (string) ($declaration['unresolvedWhy']
                ?? sprintf('depends on %s, which nothing declares', implode(', ', $declaration['unresolved'])));
        }

        if (!empty($declaration['cyclic'])) {
            return 'part of a dependency cycle';
        }

        return $unordered[$declaration['origin']] ?? null;
    }

    /**
     * Why this patch cannot be written to the store at all, or null: it
     * changes a root file two packages deploy, so which copy is served, and
     * so which is the other copy to keep in step, is down to their deploy
     * order. Patching one would report the file fixed while the copy that is
     * served, or the one the next deploy restores, is not.
     *
     * @param array<string, mixed> $declaration
     */
    private function contested(array $declaration): ?string
    {
        if ($this->baseMismatch($declaration['base']) !== null) {
            return null;
        }

        foreach ($this->fragments($declaration) as $fragment) {
            $why = $this->resolver->contested($fragment->target());

            if ($why === null) {
                continue;
            }

            $error = sprintf(
                '%s: patch "%s" changes %s, but %s, so which copy is served depends on their deploy order. '
                    . 'Remove one, or exclude its copy with extra.magento-deploy-ignore',
                $declaration['owner'],
                $declaration['label'],
                $fragment->target(),
                $why
            );

            if (!in_array($error, $this->errors, true)) {
                $this->errors[] = $error;
            }

            return $why;
        }

        return null;
    }

    /**
     * The store's own patches that change a file a trusted package's patch also
     * changes, where neither is built on the other.
     *
     * Ordering is declared, never guessed, so the order such a pair applies in
     * is undefined. The usual way to get here is a store adding its own month
     * on top of a package's chain without `depends`: the root is read first,
     * applied first, and the package's months it rewrites then conflict on
     * every run. The store's patch is refused, never the package's: the store
     * can fix its own config, and nothing a store or a sibling declares may
     * switch off a trusted package's security patch.
     *
     * Only where `depends` could fix it, which is within one base line. A pair
     * on different lines (a store's top-level patch against a package's line)
     * cannot be ordered at all, so it is a warning that says how to move it.
     * Between two packages it is only ever a warning, and only where neither
     * requires the other (declarations() orders those): neither vendor knows
     * about the other and the store cannot edit their declarations, so
     * refusing would make them impossible to install together, and a real
     * clash still fails the run as a conflict. Within one package two
     * independent fixes to a file are normal (Adobe's hotfix beside that
     * month's patch) and the author owns the order.
     *
     * @param array<int, array<string, mixed>> $declarations in apply order
     *
     * @return array<string, string> origin => why it is refused
     */
    private function unordered(array $declarations): array
    {
        if ($this->unordered !== null) {
            return $this->unordered;
        }

        $refused = $indexOf = [];

        foreach ($declarations as $index => $declaration) {
            $indexOf[$declaration['origin']] = $index;
        }

        $touches = $this->touches($declarations);
        $root = $this->composer->getPackage()->getName();
        $reach = [];
        /** @var array<string, array{message: string, files: int}> $betweenPackages one warning per pair of packages */
        $betweenPackages = [];
        /** @var array<int, array<string, array<string, array{others: array<int, true>, target: string}>>> $store */
        $store = [];
        $line = static function (array $declaration): string {
            return $declaration['line'] === null ? 'top-level "patches"' : '"' . $declaration['line'] . '" line';
        };

        foreach ($touches as $byOwner) {
            // A file one source changes, however many patches it has there,
            // is that source's to order. Most files stop here.
            if (count($byOwner) < 2) {
                continue;
            }

            $owners = array_keys($byOwner);

            foreach ($owners as $position => $ownerA) {
                foreach (array_slice($owners, $position + 1) as $ownerB) {
                    // A package on top of one it requires was ordered by
                    // declarations(): where all of the required one's patches here
                    // come first, every pair is, and checking each was quadratic.
                    foreach ([[$ownerA, $ownerB], [$ownerB, $ownerA]] as [$upper, $lower]) {
                        $below = array_keys($byOwner[$lower]);
                        $above = array_keys($byOwner[$upper]);

                        if ($below !== [] && $above !== []
                            && $this->buildsOn((string) $upper, (string) $lower)
                            && max($below) < min($above)) {
                            continue 2;
                        }
                    }

                    foreach ($byOwner[$ownerA] as $a => $targetA) {
                        foreach ($byOwner[$ownerB] as $b => $targetB) {
                            if ($this->reaches(max($a, $b), min($a, $b), $declarations, $indexOf, $reach)) {
                                continue;
                            }

                            // Between two packages, one warning for the pair, counting the
                            // files: one per pair of patches grew with the square of their
                            // chains, on every Composer run. The first unordered pair on a
                            // file is enough to count it.
                            if ((string) $ownerA !== $root && (string) $ownerB !== $root) {
                                $pair = [(string) $ownerA, (string) $ownerB];
                                sort($pair);
                                $pairKey = implode("\0", $pair);
                                $mutual = $this->collector->requires((string) $ownerA, (string) $ownerB)
                                    && $this->collector->requires((string) $ownerB, (string) $ownerA);
                                $betweenPackages[$pairKey] = $betweenPackages[$pairKey] ?? [
                                    'message' => sprintf(
                                        '%s "%s" and %s "%s" both change %s, and neither depends on the other, so the order '
                                        . 'they apply in is undefined. %s',
                                        $ownerA,
                                        $declarations[$a]['label'],
                                        $ownerB,
                                        $declarations[$b]['label'],
                                        $targetA,
                                        $mutual
                                            ? 'They require each other, so that says nothing about which comes first: '
                                                . 'if one is built on the other, its package should say so with "depends".'
                                            : 'If one is built on the other, its package should require the other, '
                                                . 'or say so with "depends".'
                                    ),
                                    'files' => 0,
                                ];
                                $betweenPackages[$pairKey]['files']++;

                                continue 3;
                            }

                            // The store's patch: collected, then reported once for each
                            // package and line it has no order against, naming the links
                            // that settle it rather than every link of a chain.
                            [$own, $other, $target] = (string) $ownerA === $root ? [$a, $b, $targetA] : [$b, $a, $targetB];
                            $otherOwner = (string) $declarations[$other]['owner'];
                            $otherLine = (string) ($declarations[$other]['line'] ?? '');
                            $found = $store[$own][$otherOwner][$otherLine] ?? ['others' => [], 'target' => $target];
                            $found['others'][$other] = true;
                            $store[$own][$otherOwner][$otherLine] = $found;
                        }
                    }
                }
            }
        }

        foreach ($store as $own => $byOther) {
            foreach ($byOther as $otherOwner => $byLine) {
                foreach ($byLine as $found) {
                    $others = array_keys($found['others']);
                    sort($others);
                    $tips = $this->tips($others, $declarations, $indexOf);
                    sort($tips);
                    $target = $found['target'];
                    $labels = implode(' and ', array_map(static function (int $index) use ($declarations): string {
                        return '"' . $declarations[$index]['label'] . '"';
                    }, $tips));
                    $names = implode(', ', array_map(static function (int $index) use ($declarations, $otherOwner): string {
                        return '"' . $otherOwner . ':' . $declarations[$index]['id'] . '"';
                    }, $tips));
                    $many = count($tips) > 1;

                    if ($declarations[$own]['line'] !== $declarations[$tips[0]]['line']) {
                        $this->unorderedWarnings[] = sprintf(
                            'Your patch "%s" changes %s, which %s\'s %s also change%s, but they are in the %s '
                            . 'and the %s, so nothing orders them. To build on %s, declare yours in the %s with "depends".',
                            $declarations[$own]['label'],
                            $target,
                            $otherOwner,
                            $labels,
                            $many ? '' : 's',
                            $line($declarations[$own]),
                            $line($declarations[$tips[0]]),
                            $many ? 'them' : 'it',
                            $line($declarations[$tips[0]])
                        );
                        continue;
                    }

                    // A cumulative line cannot declare `depends`, so the advice has to
                    // say where one can be.
                    $this->errors[] = sprintf(
                        '%s: patch "%s" changes %s, which %s\'s %s also change%s, and neither depends on the other — '
                        . 'if yours is built on %s, %s"depends": [%s]',
                        $root,
                        $declarations[$own]['label'],
                        $target,
                        $otherOwner,
                        $labels,
                        $many ? '' : 's',
                        $many ? 'them' : 'it',
                        empty($declarations[$own]['cumulative'])
                            ? 'add '
                            : 'declare it in a line without "cumulative", with ',
                        $names
                    );

                    $refused[$declarations[$own]['origin']] = $refused[$declarations[$own]['origin']]
                        ?? sprintf('no order against %s (%s); both change %s', $labels, $otherOwner, $target);
                }
            }
        }

        foreach ($betweenPackages as $warning) {
            $this->unorderedWarnings[] = $warning['files'] > 1
                ? sprintf('%s The same goes for %d more files they both change.', $warning['message'], $warning['files'] - 1)
                : $warning['message'];
        }

        return $this->unordered = $refused;
    }

    /**
     * Every file a patch that applies here changes, by the sources that change it.
     *
     * @param array<int, array<string, mixed>> $declarations
     *
     * @return array<string, array<string, array<int, string>>> file key => owner => declaration => target
     */
    private function touches(array $declarations): array
    {
        $touches = [];

        foreach ($declarations as $index => $declaration) {
            if (($declaration['skipped'] ?? null) !== null
                || $this->unplaced($declaration, []) !== null
                || $this->baseMismatch($declaration['base']) !== null) {
                continue;
            }

            foreach ($this->fragments($declaration) as $fragment) {
                $touches[$this->fileKey($fragment->target())][(string) $declaration['owner']][$index] = $fragment->target();
            }
        }

        return $touches;
    }

    /**
     * The declarations, with each package's patch built on the same-file
     * patches of the packages it requires.
     *
     * Requiring a package is how an add-on says it builds on it, and the
     * Collector already applies it after them. It needs the edge as well: a
     * later run checks a patch beneath the ones stacked on it, and without
     * that the package it requires reads as conflicting once the add-on has
     * rewritten its lines. Across base lines too: unlike `depends`, these
     * carry no id to look up, and touches() only holds patches for this
     * store's versions. The store's own patches still need `depends`: they
     * are refused without it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function declarations(): array
    {
        if ($this->declarations !== null) {
            return $this->declarations;
        }

        $declarations = $this->collector->collect();
        $indexOf = [];

        foreach ($declarations as $index => $declaration) {
            $indexOf[$declaration['origin']] = $index;
        }

        foreach ($this->touches($declarations) as $byOwner) {
            foreach ($byOwner as $owner => $later) {
                foreach ($byOwner as $other => $earlier) {
                    if (!$this->buildsOn((string) $owner, (string) $other)) {
                        continue;
                    }

                    // Only the tips: the rest of a chain is reached through them,
                    // and an edge to every link was quadratic in chain length.
                    $tips = $this->tips(array_keys($earlier), $declarations, $indexOf);

                    foreach (array_keys($later) as $b) {
                        $has = array_flip($declarations[$b]['depends']);

                        foreach ($tips as $a) {
                            $origin = $declarations[$a]['origin'];

                            if ($a < $b && !isset($has[$origin])) {
                                $declarations[$b]['depends'][] = $origin;
                                $has[$origin] = true;
                            }
                        }
                    }
                }
            }
        }

        return $this->declarations = $declarations;
    }

    /**
     * Does package $owner build on package $other: requires it, and is not
     * required by it? The root requires everything and is ordered by its own
     * `depends` alone.
     */
    private function buildsOn(string $owner, string $other): bool
    {
        $root = $this->composer->getPackage()->getName();

        return $owner !== $other
            && $owner !== $root
            && $other !== $root
            && $this->collector->requires($owner, $other)
            && !$this->collector->requires($other, $owner);
    }

    /**
     * Of these patches, the ones none of the others is built on.
     *
     * @param int[] $indices in apply order
     * @param array<int, array<string, mixed>> $declarations
     * @param array<string, int> $indexOf
     *
     * @return int[]
     */
    private function tips(array $indices, array $declarations, array $indexOf): array
    {
        if (count($indices) < 2) {
            return $indices;
        }

        $covered = $tips = [];

        foreach (array_reverse($indices) as $index) {
            if (isset($covered[$index])) {
                continue;
            }

            $tips[] = $index;
            $stack = [$index];

            while ($stack !== []) {
                foreach ($declarations[(int) array_pop($stack)]['depends'] as $origin) {
                    $next = $indexOf[$origin] ?? null;

                    if ($next !== null && !isset($covered[$next])) {
                        $covered[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
        }

        return $tips;
    }

    /**
     * Is $later built on $earlier, through any chain of `depends`?
     *
     * Asked only of the few pairs that share a file across sources, rather than
     * holding every patch's full ancestry — that is quadratic in chain length,
     * and magento-patches:list ran out of memory on a long chain. Declarations
     * are in dependency order, so nothing before $earlier can lead to it.
     *
     * @param array<int, array<string, mixed>> $declarations
     * @param array<string, int> $indexOf
     * @param array<string, bool> $reach
     */
    private function reaches(int $later, int $earlier, array $declarations, array $indexOf, array &$reach): bool
    {
        $key = $later . ':' . $earlier;

        if (!isset($reach[$key])) {
            $reach[$key] = false;
            $stack = [$later];
            $seen = [];

            while ($stack !== []) {
                $node = (int) array_pop($stack);

                foreach ($declarations[$node]['depends'] as $origin) {
                    $next = $indexOf[$origin] ?? null;

                    if ($next === $earlier) {
                        $reach[$key] = true;
                        break 2;
                    }

                    if ($next !== null && $next > $earlier && !isset($seen[$next])) {
                        $seen[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
        }

        return $reach[$key];
    }

    /**
     * For each patch, the same-file fragments of the patches built on it (above) and that it is built on
     * (below), so a chain is checked as a chain. Unrelated patches never stack; file creations are left
     * to the supersede logic.
     *
     * @param array<int, array<string, mixed>> $declarations in apply order
     */
    private function stacks(array $declarations): void
    {
        $indexOf = $builtOn = $modifies = [];

        foreach ($declarations as $index => $declaration) {
            $indexOf[$declaration['origin']] = $index;
            $builtOn[$index] = [];

            foreach ($declaration['depends'] as $origin) {
                if (isset($indexOf[$origin])) {
                    $builtOn[$index] += [$indexOf[$origin] => true] + $builtOn[$indexOf[$origin]];
                }
            }

            if ($this->baseMismatch($declaration['base']) !== null) {
                continue;
            }

            foreach ($this->fragments($declaration) as $fragment) {
                if (!$fragment->createsFile()) {
                    $modifies[$index][$this->fileKey($fragment->target())][] = $fragment;
                }
            }
        }

        $this->above = $this->below = [];

        foreach ($builtOn as $index => $earlier) {
            ksort($earlier);

            foreach (array_keys($earlier) as $prior) {
                foreach ($modifies[$index] ?? [] as $key => $fragments) {
                    $this->above[$prior][$key] = array_merge($this->above[$prior][$key] ?? [], $fragments);
                }

                foreach ($modifies[$prior] ?? [] as $key => $fragments) {
                    $this->below[$index][$key] = array_merge($this->below[$index][$key] ?? [], $fragments);
                }
            }
        }
    }

    /**
     * @return string[] diffs of later links that edit $target, aimed at $path
     */
    private function above(int $index, string $target, string $path): array
    {
        return $this->aimed($this->above[$index][$this->fileKey($target)] ?? [], $path);
    }

    /**
     * @return string[] diffs of earlier links that edit $target, aimed at $path
     */
    private function below(int $index, string $target, string $path): array
    {
        return $this->aimed($this->below[$index][$this->fileKey($target)] ?? [], $path);
    }

    /**
     * @param Fragment[] $fragments
     *
     * @return string[]
     */
    private function aimed(array $fragments, string $path): array
    {
        return array_map(function (Fragment $fragment) use ($path): string {
            return $fragment->target() === $path
                ? $fragment->diff()
                : $this->applier->retarget($fragment->diff(), $fragment->target(), $path);
        }, $fragments);
    }

    /** Root copy and package copy of a root-mapped file are one file, whichever a hunk names. */
    private function fileKey(string $target): string
    {
        return $this->resolver->rootOf($target) ?? $target;
    }

    /**
     * Split a patch once and reuse it: the pre-pass and the run itself look at
     * the same Fragment objects.
     *
     * @param array<string, mixed> $declaration
     *
     * @return Fragment[]
     */
    private function fragments(array $declaration): array
    {
        $source = $declaration['source'];

        if (!isset($this->splits[$source])) {
            $contents = @file_get_contents($source);
            $this->splits[$source] = $contents === false ? [] : $this->splitter->split($contents);
        }

        // Fragments carry their own verdict, so two declarations sharing a
        // source file must not share the objects — the second would overwrite
        // the first's results in a list that has already been returned.
        return array_map(static function (Fragment $fragment): Fragment {
            return clone $fragment;
        }, $this->splits[$source]);
    }

    /**
     * Applicable, but something in it did not land.
     *
     * @param array<string, mixed> $result
     */
    private function incomplete(array $result): bool
    {
        if ($result['applicable'] === false) {
            return false;
        }

        foreach ($result['fragments'] as $fragment) {
            /** @var Fragment $fragment */
            if ($fragment->isUnapplied() || $fragment->isFailure()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $declaration
     * @param array<string, int> $lastCreator target path => declaration index
     *
     * @return array<string, mixed>
     */
    private function runOne(array $declaration, bool $apply, int $index, array $lastCreator): array
    {
        $result = $declaration;
        $result['fragments'] = [];

        $mismatch = $this->baseMismatch($declaration['base']);
        if ($mismatch !== null) {
            $result['applicable'] = false;
            $result['reason'] = $mismatch;

            return $result;
        }

        $result['applicable'] = true;
        $result['reason'] = null;

        $fragments = $this->fragments($declaration);

        if ($fragments === []) {
            // Not "does not apply here" — nobody can say whether it applies.
            // An empty, truncated or unreadable file used to land in the same
            // bucket as a patch built for another base version, which exits 0
            // and reads as "nothing to do for this store".
            $this->errors[] = sprintf(
                '%s: patch "%s" could not be read as a diff (%s)',
                $declaration['owner'],
                $declaration['label'],
                $declaration['sourceRelative']
            );

            $result['applicable'] = false;
            $result['reason'] = 'could not be read as a diff';

            return $result;
        }

        $superseded = [];
        foreach ($fragments as $position => $fragment) {
            $key = $declaration['owner'] . "\0" . $fragment->target();
            $superseded[$position] = $fragment->createsFile()
                && ($lastCreator[$key] ?? $index) !== $index;
        }

        // A patch is a unit. `git apply` is all-or-nothing within one
        // invocation, and splitting a patch into per-file fragments throws that
        // away: a security fix that lands on three of its four files is not
        // three quarters protected, it is unpatched and possibly broken —
        // Adobe's patches move interdependent code, and a half-applied
        // namespace change fatals the site.
        //
        // So classify everything first, and only write if nothing is going to
        // refuse. A file this store cannot patch at all — the package is
        // replaced, not installed, or does not ship the file — is not a
        // refusal: those are expected on any real store and must not take the
        // rest of the patch down with them.
        if ($apply && $this->refusals($declaration, $superseded, $index) !== []) {
            foreach ($fragments as $position => $fragment) {
                $this->handle($fragment, false, $declaration['owner'], $superseded[$position], $index);
                $result['fragments'][] = $fragment;
            }

            return $result;
        }

        $this->journal = [];

        foreach ($fragments as $position => $fragment) {
            $this->handle($fragment, $apply, $declaration['owner'], $superseded[$position], $index);
            $result['fragments'][] = $fragment;

            // Pre-flight said every fragment would apply, so a failure here is
            // the working tree changing underneath us, or a permission problem.
            // Rare, but it is exactly the half-applied state the pre-flight
            // exists to prevent, so undo what already landed. Nothing is
            // recorded: a rolled-back write on record would later pass for a
            // file this plugin wrote, and be replaced as one.
            if ($apply && $fragment->isFailure()) {
                $this->rollback($declaration);

                return $result;
            }
        }

        if ($apply) {
            $this->state->record($declaration, $result['fragments'], $this->resolver);
        }

        return $result;
    }

    /**
     * Which fragments of this patch would refuse to apply, asked without
     * writing anything.
     *
     * Deliberately runs on a fresh split: these fragments carry the verdicts of
     * the dry pass and must not be the objects the real pass then reports.
     *
     * @param array<string, mixed> $declaration
     * @param array<int, bool> $superseded
     *
     * @return string[]
     */
    private function refusals(array $declaration, array $superseded, int $index): array
    {
        $refused = [];

        foreach ($this->fragments($declaration) as $position => $fragment) {
            $this->handle($fragment, false, $declaration['owner'], $superseded[$position] ?? false, $index);

            if ($fragment->isFailure()) {
                $refused[] = $fragment->target();
            }
        }

        return $refused;
    }

    /**
     * Keep what is at $path before this patch first writes it.
     */
    private function remember(string $path, Fragment $fragment): void
    {
        $absolute = $this->resolver->absolute($path);

        if (isset($this->journal[$absolute])) {
            return;
        }

        clearstatcache(true, $absolute);
        $existed = is_file($absolute);
        $dirs = [];

        // The directories a new file will need, deepest first, so they can go too.
        for ($dir = dirname($absolute); !$existed && !is_dir($dir) && $dir !== dirname($dir); $dir = dirname($dir)) {
            $dirs[] = $dir;
        }

        $this->journal[$absolute] = [
            'fragment' => $fragment,
            'existed' => $existed,
            'contents' => $existed ? @file_get_contents($absolute) : false,
            'mode' => $existed ? (int) fileperms($absolute) & 07777 : 0,
            'dirs' => $dirs,
        ];
    }

    /**
     * Put back everything this patch wrote, after a later file failed.
     *
     * Driven by what was written, not by verdicts. A fragment that wrote its
     * primary and then failed on the mirror reads as a failure, and its primary
     * still has to come back; so does a mirror, a superseded file and a deleted
     * one, none of which reversing the primary's diff ever reached. Restoring
     * the bytes rather than reversing the diff also restores the mode, which
     * git apply resets on every file it writes.
     *
     * @param array<string, mixed> $declaration
     */
    private function rollback(
        array $declaration,
        string $verdict = Fragment::APPLICABLE,
        string $why = 'another file in "%s" would not take the patch'
    ): void {
        $stuck = [];

        foreach (array_reverse($this->journal, true) as $absolute => $before) {
            $fragment = $before['fragment'];

            if (!$this->restore($absolute, $before)) {
                $stuck[spl_object_id($fragment)] = true;
                $fragment->resolve(Fragment::CONFLICT, sprintf(
                    '"%s" failed on a later file, and %s could not be put back as it was',
                    $declaration['label'],
                    substr($absolute, strlen($this->resolver->absolute('')))
                ));

                continue;
            }

            if (!$fragment->isFailure()) {
                $fragment->resolve($verdict, 'rolled back: ' . sprintf($why, $declaration['label']));
            }
        }

        // What was put back is no longer written, and --json must not list it.
        foreach ($this->journal as $before) {
            if (!isset($stuck[spl_object_id($before['fragment'])])) {
                $before['fragment']->forgetWrites();
            }
        }

        $this->journal = [];
    }

    /**
     * @param array{fragment: Fragment, existed: bool, contents: string|false, mode: int, dirs: string[]} $before
     */
    private function restore(string $absolute, array $before): bool
    {
        clearstatcache(true, $absolute);

        if (!$before['existed']) {
            if ((file_exists($absolute) || is_link($absolute)) && !@unlink($absolute)) {
                return false;
            }

            foreach ($before['dirs'] as $dir) {
                @rmdir($dir);
            }

            return true;
        }

        if ($before['contents'] === false) {
            return false;
        }

        if (is_file($absolute)
            && (fileperms($absolute) & 07777) === $before['mode']
            && @file_get_contents($absolute) === $before['contents']
        ) {
            return true;
        }

        $dir = dirname($absolute);

        return (is_dir($dir) || @mkdir($dir, 0777, true) || is_dir($dir))
            && @file_put_contents($absolute, $before['contents']) === strlen($before['contents'])
            && @chmod($absolute, $before['mode']);
    }

    private function handle(Fragment $fragment, bool $apply, string $owner, bool $superseded, int $index): void
    {
        if ($superseded) {
            $fragment->resolve(Fragment::NOT_APPLICABLE, 'superseded by a later patch in this line');

            return;
        }

        // A modification finds nothing to patch there anyway; a new file would
        // be written into a module the store does not have.
        $absent = $fragment->createsFile() ? $this->notHere($fragment) : null;

        if ($absent !== null) {
            $fragment->resolve(Fragment::NOT_APPLICABLE, $absent);

            return;
        }

        $target = $fragment->target();

        // `git apply` will not write through a symlink, which is what the
        // symlink deploy strategy leaves at the project root.
        $primary = $this->resolver->writePathFor($target);
        $diff = $primary === $target ? $fragment->diff() : $this->applier->retarget($fragment->diff(), $target, $primary);

        $mirror = $this->mirrorOf($target, $primary);

        // One copy gone and the other there: patch the one that is. A failed
        // update can leave a root file undeployed, and refusing the patch
        // blocked every month built on it, while the package copy is what the
        // next deploy serves from. The other way round, a package copy gone
        // with the served one in place, is vulnerable code being served.
        // A root file the store has the installer ignore is not missing.
        if ($mirror !== null
            && !$fragment->createsFile()
            && !isset($this->vacated[$primary])
            && !file_exists($this->resolver->absolute($primary))
            && file_exists($this->resolver->absolute($mirror))
        ) {
            $rootMissing = $this->resolver->rootOf($target) === ltrim($target, '/');
            $deployer = $rootMissing ? $this->resolver->deployedBy($target) : null;

            if (!$rootMissing || $deployer !== null) {
                if ($deployer !== null) {
                    $this->missingRoots[$deployer][ltrim($target, '/')] = true;
                }

                $primary = $this->resolver->writePathFor($mirror);
                $diff = $this->applier->retarget($fragment->diff(), $target, $primary);
                $mirror = null;
            }
        }

        $linked = $mirror !== null && $this->resolver->isSameFile($primary, $mirror);
        $before = $this->resolver->hash($primary);

        $unrenamable = $this->unrenamable($fragment, $primary, $mirror);

        if ($unrenamable !== null) {
            $fragment->resolve(Fragment::CONFLICT, $unrenamable);

            return;
        }

        // Reverse first, and it wins outright. A hunk whose context is ordinary
        // boilerplate can match at more than one offset — the July patch's
        // nginx `location` block is a live example — so on an already-patched
        // file the forward check also passes, and applying again duplicates the
        // change. "Is it already there" is the question that decides.
        // Asked beneath later links in the chain, which may have rewritten its lines.
        if ($this->applier->isAppliedBeneath($diff, $primary, $this->above($index, $target, $primary))) {
            if ($this->hasMode($fragment, $primary)) {
                $fragment->resolve(Fragment::ALREADY);
                $this->reconcile($fragment, $primary, $mirror, $before, $linked, $apply, $index);

                return;
            }

            // The content is there and only the mode is not: a copy deploy drops
            // the executable bit. Neither direction of git apply can fix that,
            // so set it, rather than calling the patch a conflict for good.
            // chmod() follows a link, as copy() does, wherever it points.
            if ($this->resolver->escapesProject($primary)) {
                $fragment->resolve(Fragment::CONFLICT, $primary . ' resolves outside the project and was not written');

                return;
            }

            if (!$apply) {
                $fragment->resolve(Fragment::APPLICABLE, sprintf('%s does not have mode %o', $primary, (int) $fragment->newMode()));

                // Whether the other copy can follow is part of whether this applies.
                $this->reconcile($fragment, $primary, $mirror, $before, $linked, false, $index, true);

                return;
            }

            $this->remember($primary, $fragment);

            if (!@chmod($this->resolver->absolute($primary), (int) $fragment->newMode())) {
                $fragment->resolve(Fragment::CONFLICT, sprintf('could not set mode %o on %s', (int) $fragment->newMode(), $primary));

                return;
            }

            $fragment->resolve(Fragment::APPLIED);
            $fragment->recordWrite($primary);
            $this->reconcile($fragment, $primary, $mirror, $before, $linked, true, $index);

            return;
        }

        // A report puts earlier links back first, as an apply would. An apply asks the real file.
        $applicable = $this->applying
            ? $this->applier->canApply($diff)
            : $this->applier->canApplyAbove($diff, $primary, $this->below($index, $target, $primary));

        if ($applicable) {
            if (!$apply) {
                $fragment->resolve(Fragment::APPLICABLE);

                // The mirror is written in the same pass, so whether it can
                // follow is part of whether this applies. Finding out after the
                // primary is written is how a patch ends up half on disk.
                $this->reconcile($fragment, $primary, $mirror, $before, $linked, false, $index, true);

                return;
            }

            $this->remember($primary, $fragment);

            if ($fragment->renamedFrom() !== null) {
                $this->remember((string) $fragment->renamedFrom(), $fragment);
            }

            if (!$this->applier->apply($diff)) {
                $fragment->resolve(Fragment::CONFLICT, $this->applier->lastError());

                return;
            }

            // An exit code is not evidence that anything was written. git apply
            // has more than one way to report success having changed nothing —
            // a path filtered out by the prefix it computed, a hunk it decided
            // was already there — and this plugin exists to answer whether a
            // security fix is on disk, not whether a command was happy. So
            // check the bytes moved, and treat a silent no-op as a conflict
            // rather than the success it claimed to be.
            if ($this->resolver->hash($primary) === $before
                && ($fragment->newMode() === null || !$this->hasMode($fragment, $primary))
            ) {
                $fragment->resolve(
                    Fragment::CONFLICT,
                    'git apply reported success but ' . $primary . ' did not change'
                );

                return;
            }

            $fragment->resolve(Fragment::APPLIED);
            $fragment->recordWrite($primary);
            $this->reconcile($fragment, $primary, $mirror, $before, $linked, true, $index);

            return;
        }

        // Neither direction applies. Absent target plus a modification hunk
        // means there is nothing here to patch; anything else is a real clash.
        if (!file_exists($this->resolver->absolute($primary)) && !$fragment->createsFile()) {
            // Missing because this run took it away is not the same as missing
            // because the module never shipped it, and only the second is
            // nothing to worry about. Saying "does not ship this file" here
            // reclassifies a hole left by the project's own skip as a patch
            // that was never needed, and exits 0.
            if (isset($this->vacated[$primary])) {
                $fragment->resolve(Fragment::CONFLICT, sprintf(
                    'the file was removed by reverting "%s", which this project skips',
                    $this->vacated[$primary]
                ));

                return;
            }

            $fragment->resolve(Fragment::NOT_APPLICABLE, $this->whyMissing($fragment));

            return;
        }

        // A new-file hunk landing on a file we wrote for an earlier patch in the
        // same chain is a replacement, not a clash: Adobe regenerates
        // vendor/bin/patch-status every month. An unrecognised hash stays a
        // conflict, so nothing hand-written is ever destroyed.
        $current = $this->resolver->hash($primary);
        if ($fragment->createsFile() && $current !== null && $this->state->wasWrittenBy($owner, $primary, $current)) {
            if (!$apply) {
                $fragment->resolve(Fragment::APPLICABLE, 'supersedes an earlier patch in this chain');
                $this->reconcile($fragment, $primary, $mirror, $before, $linked, false, $index, true);

                return;
            }

            // Keep the old file until the new content is actually in place,
            // and move it rather than read it into memory and write it back: a
            // rename preserves the mode and the ownership, and it cannot
            // half-succeed. Restoring by hand dropped the executable bit on
            // vendor/bin/patch-status, and a failed write left a file this
            // plugin had installed as a security fix simply gone, under a
            // message that said "conflict".
            $absolute = $this->resolver->absolute($primary);

            // A crash between the rename and the apply leaves one of these
            // behind, and the next run recreates the target without ever
            // mentioning it.
            foreach (glob($absolute . '.mpi-superseded-*') ?: [] as $stale) {
                @unlink($stale);
            }

            $aside = $absolute . '.mpi-superseded-' . bin2hex(random_bytes(4));
            $this->remember($primary, $fragment);

            if (@rename($absolute, $aside)) {
                if ($this->applier->apply($diff)) {
                    @unlink($aside);
                    $fragment->resolve(Fragment::SUPERSEDED);
                    $fragment->recordWrite($primary);
                    $this->reconcile($fragment, $primary, $mirror, $before, $linked, true, $index);

                    return;
                }

                if (!@rename($aside, $absolute)) {
                    $fragment->resolve(
                        Fragment::CONFLICT,
                        'could not be replaced, and the previous version is at ' . basename($aside)
                    );

                    return;
                }
            }
        }

        $fragment->resolve(Fragment::CONFLICT, $this->applier->lastError());
    }

    /**
     * Why a rename cannot be applied here, or null.
     *
     * git apply renames one path to another. A root file's other copy, or a
     * path that resolves through a symlink, would need the old name mapped as
     * well, and a rename half carried out loses the store's file. No Adobe
     * patch renames a file, so these are refused rather than attempted.
     */
    private function unrenamable(Fragment $fragment, string $primary, ?string $mirror): ?string
    {
        $from = $fragment->renamedFrom();

        if ($from === null
            || ($mirror === null
                && $primary === $fragment->target()
                && $this->resolver->mirrorFor($from) === null
                && $this->resolver->writePathFor($from) === $from)
        ) {
            return null;
        }

        return sprintf(
            'renames %s, which has another copy or resolves through a symlink, so the rename cannot be kept in step',
            $from
        );
    }

    /**
     * Does $path have the mode $fragment sets, if it sets one?
     *
     * git's reverse check of a mode change only warns when the mode differs,
     * so on its own it reads a fragment that makes a file executable as
     * already applied to one that is not.
     */
    private function hasMode(Fragment $fragment, string $path): bool
    {
        $mode = $fragment->newMode();
        $absolute = $this->resolver->absolute($path);
        clearstatcache(true, $absolute);

        return $mode === null || !is_file($absolute) || (fileperms($absolute) & 0777) === $mode;
    }

    /**
     * The other copy of $target, unless it is the file being written.
     *
     * The symlink deploy strategy leaves the served copy a link to the
     * package's, so both paths name one file. Handled as two, a skipped patch
     * was reverted twice, the second failed, and the rollback put it back on
     * every run. A hardlink is still two paths to write, so this asks where
     * each is written, not whether they are the same file.
     */
    private function mirrorOf(string $target, string $primary): ?string
    {
        $mirror = $this->resolver->mirrorFor($target);

        return $mirror !== null && $this->resolver->writePathFor($mirror) === $primary ? null : $mirror;
    }

    /**
     * Bring the other copy of a root-mapped file into line.
     *
     * In lockstep — the copies matched before we touched anything — the patched
     * content is copied across, which keeps them from drifting into different
     * patch states. Where they had already diverged, the fragment is applied to
     * the mirror on its own terms instead, so a project's own customisation is
     * never overwritten by a copy.
     *
     * Read-only callers get the same verdict and no writes. That distinction is
     * not cosmetic: a store whose vendor copy is patched and whose root copy was
     * wiped by a magento2-base reinstall is exactly the drift this plugin exists
     * to report, and quietly healing it inside `magento-patches:verify` would return 0
     * for a store that is serving an unpatched file.
     *
     * $pending asks the same of a primary that is about to be written: could
     * the mirror follow it?
     */
    private function reconcile(
        Fragment $fragment,
        string $primary,
        ?string $mirror,
        ?string $before,
        bool $linked,
        bool $apply,
        int $index,
        bool $pending = false
    ): void {
        if ($mirror === null) {
            return;
        }

        $mirrorAbsolute = $this->resolver->absolute($mirror);
        $primaryAbsolute = $this->resolver->absolute($primary);

        if (!file_exists($mirrorAbsolute) && !$fragment->createsFile()) {
            // The served copy is the one gone, for a patch that names the
            // package copy: warn as handle() does for one that names the root.
            $deployer = $this->resolver->rootOf($mirror) === ltrim($mirror, '/')
                ? $this->resolver->deployedBy($mirror)
                : null;

            if ($deployer !== null) {
                $this->missingRoots[$deployer][ltrim($mirror, '/')] = true;
            }

            return;
        }

        $mirrorHash = $this->resolver->hash($mirror);
        $primaryHash = $this->resolver->hash($primary);

        if ($mirrorHash !== null && $mirrorHash === $primaryHash && !$pending && $this->hasMode($fragment, $mirror)) {
            return;
        }

        // Hardlinked copies were one file until `git apply` wrote and renamed;
        // they were in lockstep by definition, so copy.
        if ($linked || ($before !== null && $mirrorHash === $before)) {
            // Every other write goes through git apply, which refuses to write
            // beyond a symbolic link. copy() follows one happily, so this is
            // the one path a hostile extra.map could aim out of the project —
            // and writePathFor() hands back the unresolved path when it
            // escapes, which absolute() then turns straight back into the
            // escaping path. Ask the question directly.
            if ($this->resolver->escapesProject($mirror)) {
                $fragment->resolve(
                    Fragment::MIRROR_CONFLICT,
                    $mirror . ' resolves outside the project and was not written'
                );

                return;
            }

            if (!$apply) {
                if (!$pending) {
                    $fragment->resolve(Fragment::APPLICABLE, $mirror . ' is out of step and would be rewritten');
                }

                return;
            }

            $mirrorWrite = $this->resolver->writePathFor($mirror);
            $this->remember($mirrorWrite, $fragment);

            // A deletion leaves nothing to copy: the package copy goes too. A
            // copy keeps the destination's mode, so a mode change is set too.
            $written = file_exists($primaryAbsolute)
                ? @copy($primaryAbsolute, $this->resolver->absolute($mirrorWrite))
                    && ($fragment->newMode() === null || @chmod($this->resolver->absolute($mirrorWrite), (int) $fragment->newMode()))
                : @unlink($this->resolver->absolute($mirrorWrite));

            if (!$written) {
                $fragment->resolve(Fragment::MIRROR_CONFLICT, 'could not write ' . $mirror);

                return;
            }

            $fragment->recordWrite($mirror);

            return;
        }

        // Through any link in its path: git apply will not write beyond one.
        $mirrorWrite = $this->resolver->writePathFor($mirror);
        $mirrorDiff = $this->applier->retarget($fragment->diff(), $fragment->target(), $mirrorWrite);

        $above = $this->above($index, $fragment->target(), $mirrorWrite);
        if ($this->applier->isAppliedBeneath($mirrorDiff, $mirrorWrite, $above)) {
            if ($this->hasMode($fragment, $mirrorWrite)) {
                return;
            }

            // The content is there and only the mode is not, as handle() finds
            // on the served copy: set it, rather than a conflict for good.
            if ($this->resolver->escapesProject($mirror)) {
                $fragment->resolve(Fragment::MIRROR_CONFLICT, $mirror . ' resolves outside the project and was not written');

                return;
            }

            if (!$apply) {
                if (!$pending) {
                    $fragment->resolve(Fragment::APPLICABLE, sprintf('%s does not have mode %o', $mirror, (int) $fragment->newMode()));
                }

                return;
            }

            $this->remember($mirrorWrite, $fragment);

            if (!@chmod($this->resolver->absolute($mirrorWrite), (int) $fragment->newMode())) {
                $fragment->resolve(Fragment::MIRROR_CONFLICT, sprintf('could not set mode %o on %s', (int) $fragment->newMode(), $mirror));

                return;
            }

            $fragment->recordWrite($mirror);

            return;
        }

        $applicable = $this->applying
            ? $this->applier->canApply($mirrorDiff)
            : $this->applier->canApplyAbove($mirrorDiff, $mirrorWrite, $this->below($index, $fragment->target(), $mirrorWrite));

        if ($applicable) {
            if (!$apply) {
                if (!$pending) {
                    $fragment->resolve(Fragment::APPLICABLE, $mirror . ' is not patched');
                }

                return;
            }

            $this->remember($mirrorWrite, $fragment);

            if ($this->applier->apply($mirrorDiff)) {
                $fragment->recordWrite($mirror);

                return;
            }
        }

        $fragment->resolve(Fragment::MIRROR_CONFLICT, $mirror . ' has diverged and will not take the patch');
    }

    /**
     * @param array<string, string> $constraints
     */
    private function baseMismatch(array $constraints): ?string
    {
        foreach ($constraints as $package => $constraint) {
            $version = $this->installed[$package] ?? null;

            if ($version === null) {
                return sprintf('%s is not installed', $package);
            }

            try {
                $satisfied = Semver::satisfies($version, (string) $constraint);
            } catch (\UnexpectedValueException $e) {
                // Collector rejects these before they get here. Belt and braces:
                // an exception escaping this far would fail composer install
                // with a VersionParser stack trace and no way past it.
                return sprintf('has an unreadable constraint for %s (%s)', $package, (string) $constraint);
            }

            if (!$satisfied) {
                return sprintf('built for %s %s, this install has %s', $package, $constraint, $version);
            }
        }

        return null;
    }

    /**
     * Why nothing should be written for $fragment at all, or null: its target
     * is in a package this store replaces, or does not install.
     */
    private function notHere(Fragment $fragment): ?string
    {
        $package = $fragment->owningPackage();

        return $package !== null && (isset($this->replacedBy[$package]) || !isset($this->installed[$package]))
            ? $this->whyMissing($fragment)
            : null;
    }

    private function whyMissing(Fragment $fragment): string
    {
        $package = $fragment->owningPackage();

        if ($package === null) {
            return 'not present in this installation';
        }

        if (isset($this->replacedBy[$package])) {
            return sprintf('%s is replaced by %s', $package, $this->replacedBy[$package]);
        }

        if (!isset($this->installed[$package])) {
            return sprintf('%s is not installed', $package);
        }

        return sprintf('%s is installed but does not ship this file', $package);
    }

    private function indexPackages(): void
    {
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $this->installed[$package->getName()] = $package->getPrettyVersion();

            foreach ($package->getReplaces() as $link) {
                $this->replacedBy[$link->getTarget()] = $package->getName();
            }
        }

        $root = $this->composer->getPackage();
        $this->installed[$root->getName()] = $root->getPrettyVersion();
    }

    /**
     * What magento/magento-composer-installer deploys to the project root, by
     * package.
     *
     * Only that may stand as a root file's other copy. An ordinary library's
     * extra.map is read by nothing, yet it used to be taken at its word: one
     * that sorted first could name its own decoy as the copy of a file
     * magento2-base deploys, and the base copy went unpatched under a clean
     * report. The installer deploys only the magento2-* types, and only a
     * package with an extra.map of its own, which the root's
     * magento-map-overwrite then replaces (Installer::supports(), install(),
     * getParser()). Destinations are rooted, translated and ignored as it
     * does (TargetResolver::deployed()).
     *
     * @return array<int, array{package: string, install: string, raw: string, map: array<mixed>}>
     */
    private function rootMappings(string $projectRoot, string $unresolvedRoot): array
    {
        $mappings = [];
        $prefix = rtrim($projectRoot, '/') . '/';
        $unresolvedPrefix = rtrim($unresolvedRoot, '/') . '/';
        $root = $this->composer->getPackage()->getExtra();
        $overwrite = is_array($root['magento-map-overwrite'] ?? null) ? array_change_key_case($root['magento-map-overwrite']) : [];
        $ignore = is_array($root['magento-deploy-ignore'] ?? null) ? array_change_key_case($root['magento-deploy-ignore']) : [];
        $translations = is_array($root['path-mapping-translations'] ?? null) ? $root['path-mapping-translations'] : [];

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $extra = $package->getExtra();
            $typePrefix = TargetResolver::DEPLOYED_TYPES[$package->getType()] ?? null;
            $map = $overwrite[$package->getName()] ?? $extra['map'] ?? null;

            if ($typePrefix === null || !isset($extra['map']) || !is_array($map)) {
                continue;
            }

            $rawPath = (string) $this->composer->getInstallationManager()->getInstallPath($package);
            $installPath = realpath($rawPath) ?: $rawPath;
            $raw = '';

            foreach ([$prefix, $unresolvedPrefix] as $base) {
                if ($raw === '' && strpos($rawPath, $base) === 0) {
                    $raw = substr($rawPath, strlen($base));
                }
            }

            if (strpos($installPath, $prefix) !== 0) {
                continue;
            }

            $ignored = array_merge((array) ($ignore['*'] ?? []), (array) ($ignore[$package->getName()] ?? []));

            $mappings[] = [
                'package' => $package->getName(),
                'install' => substr($installPath, strlen($prefix)),
                'raw' => $raw,
                'map' => TargetResolver::deployed($map, $typePrefix, $translations, $ignored),
            ];
        }

        return $mappings;
    }
}
