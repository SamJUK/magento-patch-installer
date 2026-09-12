<?php

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
        $projectRoot = dirname((string) $composer->getConfig()->get('vendor-dir'));
        $projectRoot = realpath($projectRoot) ?: $projectRoot;
        $this->applier = new Applier($process, $projectRoot);
        $this->resolver = new TargetResolver($projectRoot, $this->rootMappings($projectRoot));
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
     * and reads no vendor file, so it answers on a fresh clone before anything
     * is installed — which is exactly when someone evaluating a meta package
     * wants to ask. Base constraints are still checked, because those come from
     * the lock file rather than from disk.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $entries = [];
        $declarations = $this->collector->collect();

        $ids = [];
        foreach ($declarations as $declaration) {
            $ids[$declaration['origin']] = (string) $declaration['id'];
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

            $entries[] = [
                'id' => $declaration['id'],
                'label' => $declaration['label'],
                'owner' => $declaration['owner'],
                'skipped' => $declaration['skipped'] ?? null,
                'source' => $declaration['sourceRelative'],
                'line' => $declaration['line'],
                'description' => $declaration['description'],
                // Ids rather than the resolved indices: the catalogue is read
                // by a person deciding whether this package covers them.
                'depends' => array_values(array_map(
                    static function (string $origin) use ($ids): string {
                        return $ids[$origin] ?? '?';
                    },
                    $declaration['depends']
                )),
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
        $declarations = $this->collector->collect();

        $lastCreator = $this->lastCreators($declarations);

        // Reversals run first, and in reverse dependency order. A patch cannot
        // come off while another sits on top of it, so walking forward — the
        // order everything else uses — leaves the first link of a chain stuck
        // until a second run, and calls the store clean in between.
        $reversals = [];

        foreach (array_reverse($declarations) as $declaration) {
            if (($declaration['skipped'] ?? null) === null || $this->baseMismatch($declaration['base']) !== null) {
                continue;
            }

            $reversals[$declaration['origin']] = array_merge($declaration, [
                'applicable' => false,
                'blocked' => false,
                'reason' => $declaration['skipped'],
                'fragments' => $this->unwanted($declaration, $apply),
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

            // Adobe stipulates each monthly isolated patch depends on the ones
            // before it. Once a prerequisite is missing, everything built on it
            // is refused by name rather than attempted — a hunk failure three
            // patches later is a much worse way to learn the same thing.
            $missing = null;

            if ($apply) {
                foreach ($declaration['depends'] as $needs) {
                    if (isset($broken[$needs])) {
                        $missing = $broken[$needs];
                        break;
                    }
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
    private function unwanted(array $declaration, bool $apply): array
    {
        $fragments = $this->fragments($declaration);
        $removed = false;

        foreach ($fragments as $fragment) {
            $target = $fragment->target();
            $primary = $this->resolver->writePathFor($target);
            $diff = $primary === $target
                ? $fragment->diff()
                : $this->applier->retarget($fragment->diff(), $target, $primary);

            $mirror = $this->resolver->mirrorFor($target);
            $mirrorDiff = $mirror === null
                ? null
                : $this->applier->retarget($fragment->diff(), $target, $mirror);

            // The mirror is checked even when the vendor copy is clean. A
            // magento2-base deploy leaves the patch in the served copy and not
            // the package one, and looking only at the primary calls that
            // "already gone".
            $here = $this->applier->isApplied($diff);
            $there = $mirrorDiff !== null && $this->applier->isApplied($mirrorDiff);

            if (!$here && !$there) {
                // Absent is only one of the reasons a reverse-check fails. The
                // others — local edits, another patch stacked on top — mean
                // nobody can say whether it is there, and reporting that as
                // "not present" leaves the store running it.
                if (file_exists($this->resolver->absolute($primary)) && !$this->applier->canApply($diff)) {
                    $fragment->resolve(
                        Fragment::CONFLICT,
                        'cannot tell whether it is applied: ' . $this->applier->lastError()
                    );
                    continue;
                }

                $fragment->resolve(Fragment::SKIPPED);
                continue;
            }

            if (!$apply) {
                $fragment->resolve(Fragment::UNWANTED, 'still present; magento-patches:apply will remove it');
                continue;
            }

            if ($here && !$this->applier->revert($diff)) {
                $fragment->resolve(Fragment::CONFLICT, $this->applier->lastError());
                continue;
            }

            if ($here) {
                $fragment->recordWrite($primary);
            }

            // The other copy of a root-mapped file has to come off too, or the
            // next deploy copies the patch straight back over the first.
            if ($there) {
                if (!$this->applier->revert((string) $mirrorDiff)) {
                    $fragment->resolve(Fragment::MIRROR_CONFLICT, $this->applier->lastError());
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
                $this->vacated[$primary] = (string) $declaration['label'];

                if ($mirror !== null) {
                    $this->vacated[$mirror] = (string) $declaration['label'];
                }
            }
        }

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
                if ($fragment->createsFile()) {
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
        if ($apply && $this->refusals($declaration, $superseded) !== []) {
            foreach ($fragments as $position => $fragment) {
                $this->handle($fragment, false, $declaration['owner'], $superseded[$position]);
                $result['fragments'][] = $fragment;
            }

            return $result;
        }

        foreach ($fragments as $position => $fragment) {
            $this->handle($fragment, $apply, $declaration['owner'], $superseded[$position]);
            $result['fragments'][] = $fragment;

            // Pre-flight said every fragment would apply, so a failure here is
            // the working tree changing underneath us, or a permission problem.
            // Rare, but it is exactly the half-applied state the pre-flight
            // exists to prevent, so undo what already landed.
            if ($apply && $fragment->isFailure()) {
                $this->rollback($result['fragments'], $declaration);

                break;
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
    private function refusals(array $declaration, array $superseded): array
    {
        $refused = [];

        foreach ($this->fragments($declaration) as $position => $fragment) {
            $this->handle($fragment, false, $declaration['owner'], $superseded[$position] ?? false);

            if ($fragment->isFailure()) {
                $refused[] = $fragment->target();
            }
        }

        return $refused;
    }

    /**
     * Undo the fragments of this patch that did land, after a later one failed.
     *
     * @param Fragment[] $fragments
     * @param array<string, mixed> $declaration
     */
    private function rollback(array $fragments, array $declaration): void
    {
        foreach ($fragments as $fragment) {
            if ($fragment->verdict() !== Fragment::APPLIED) {
                continue;
            }

            $target = $fragment->target();
            $primary = $this->resolver->writePathFor($target);
            $diff = $primary === $target
                ? $fragment->diff()
                : $this->applier->retarget($fragment->diff(), $target, $primary);

            if ($this->applier->revert($diff)) {
                $fragment->resolve(Fragment::APPLICABLE, sprintf(
                    'rolled back: another file in "%s" would not take the patch',
                    $declaration['label']
                ));

                continue;
            }

            $fragment->resolve(Fragment::CONFLICT, sprintf(
                'applied, but "%s" failed on a later file and this could not be rolled back',
                $declaration['label']
            ));
        }
    }

    private function handle(Fragment $fragment, bool $apply, string $owner, bool $superseded = false): void
    {
        if ($superseded) {
            $fragment->resolve(Fragment::NOT_APPLICABLE, 'superseded by a later patch in this line');

            return;
        }

        $target = $fragment->target();

        // `git apply` will not write through a symlink, which is what the
        // symlink deploy strategy leaves at the project root.
        $primary = $this->resolver->writePathFor($target);
        $diff = $primary === $target ? $fragment->diff() : $this->applier->retarget($fragment->diff(), $target, $primary);

        $mirror = $this->resolver->mirrorFor($target);
        $linked = $mirror !== null && $this->resolver->isSameFile($primary, $mirror);
        $before = $this->resolver->hash($primary);

        // Reverse first, and it wins outright. A hunk whose context is ordinary
        // boilerplate can match at more than one offset — the July patch's
        // nginx `location` block is a live example — so on an already-patched
        // file the forward check also passes, and applying again duplicates the
        // change. "Is it already there" is the question that decides.
        if ($this->applier->isApplied($diff)) {
            $fragment->resolve(Fragment::ALREADY);
            $this->reconcile($fragment, $primary, $mirror, $before, $linked, $apply);

            return;
        }

        if ($this->applier->canApply($diff)) {
            if (!$apply) {
                $fragment->resolve(Fragment::APPLICABLE);

                return;
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
            if ($this->resolver->hash($primary) === $before) {
                $fragment->resolve(
                    Fragment::CONFLICT,
                    'git apply reported success but ' . $primary . ' did not change'
                );

                return;
            }

            $fragment->resolve(Fragment::APPLIED);
            $fragment->recordWrite($primary);
            $this->reconcile($fragment, $primary, $mirror, $before, $linked, true);

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

            if (@rename($absolute, $aside)) {
                if ($this->applier->apply($diff)) {
                    @unlink($aside);
                    $fragment->resolve(Fragment::SUPERSEDED);
                    $fragment->recordWrite($primary);
                    $this->reconcile($fragment, $primary, $mirror, $before, $linked, true);

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
     */
    private function reconcile(
        Fragment $fragment,
        string $primary,
        ?string $mirror,
        ?string $before,
        bool $linked,
        bool $apply
    ): void {
        if ($mirror === null) {
            return;
        }

        $mirrorAbsolute = $this->resolver->absolute($mirror);
        $primaryAbsolute = $this->resolver->absolute($primary);

        if (!file_exists($mirrorAbsolute) && !$fragment->createsFile()) {
            return;
        }

        $mirrorHash = $this->resolver->hash($mirror);
        $primaryHash = $this->resolver->hash($primary);

        if ($mirrorHash !== null && $mirrorHash === $primaryHash) {
            return;
        }

        // Hardlinked copies were one file until `git apply` wrote and renamed;
        // they were in lockstep by definition, so copy.
        if ($linked || ($before !== null && $mirrorHash === $before)) {
            if (!$apply) {
                $fragment->resolve(Fragment::APPLICABLE, $mirror . ' is out of step and would be rewritten');

                return;
            }

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

            $mirrorWrite = $this->resolver->absolute($this->resolver->writePathFor($mirror));

            if (!@copy($primaryAbsolute, $mirrorWrite)) {
                $fragment->resolve(Fragment::MIRROR_CONFLICT, 'could not write ' . $mirror);

                return;
            }

            $fragment->recordWrite($mirror);

            return;
        }

        $mirrorDiff = $this->applier->retarget($fragment->diff(), $fragment->target(), $mirror);

        if ($this->applier->isApplied($mirrorDiff)) {
            return;
        }

        if ($this->applier->canApply($mirrorDiff)) {
            if (!$apply) {
                $fragment->resolve(Fragment::APPLICABLE, $mirror . ' is not patched');

                return;
            }

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
     * Every installed package that deploys files to the project root.
     *
     * @return array<int, array{package: string, install: string, map: array<mixed>}>
     */
    private function rootMappings(string $projectRoot): array
    {
        $mappings = [];
        $prefix = rtrim($projectRoot, '/') . '/';

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $extra = $package->getExtra();

            if (!isset($extra['map']) || !is_array($extra['map'])) {
                continue;
            }

            $installPath = (string) $this->composer->getInstallationManager()->getInstallPath($package);
            $installPath = realpath($installPath) ?: $installPath;

            if (strpos($installPath, $prefix) !== 0) {
                continue;
            }

            $mappings[] = [
                'package' => $package->getName(),
                'install' => substr($installPath, strlen($prefix)),
                'map' => $extra['map'],
            ];
        }

        return $mappings;
    }
}
