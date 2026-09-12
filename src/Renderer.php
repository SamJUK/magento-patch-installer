<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

/**
 * Turns results into something a human reads in a wall of composer output, and
 * into an exit code a pipeline can act on.
 *
 * Patches that cannot apply are printed, not hidden. A store that is quietly
 * missing a security fix is the failure this whole plugin exists to prevent, so
 * it is never allowed to look like silence.
 */
class Renderer
{
    public const OK = 0;
    public const UNAPPLIED = 1;
    public const CONFLICT = 2;
    public const CONFIG_ERROR = 3;

    /**
     * The report: one aligned row per patch that applies here, with anything
     * unusual indented underneath it.
     *
     * Patches built for another base version are collapsed into a single line
     * rather than listed one by one. On a store carrying four release lines
     * that is sixteen rows of "not for you", each dragging a version constraint
     * behind it, and they crowd out the five rows that matter.
     *
     * @param array<int, array<string, mixed>> $results
     *
     * @return string[]
     */
    public static function lines(array $results, bool $verbose, bool $failuresOnly = false): array
    {
        $lines = [];
        $elsewhere = [];
        $notCovered = 0;

        $widths = self::widths($results);

        foreach ($results as $result) {
            if ($result['applicable'] === false) {
                // A chain member refused because a prerequisite is missing is
                // always news; a patch built for another base version is not.
                if (!empty($result['blocked'])) {
                    $lines[] = sprintf(
                        '  %s  %s  <comment>%s</comment>',
                        self::badge('BLOCKED'),
                        self::name($result, $widths),
                        self::safe((string) $result['reason'])
                    );
                    continue;
                }

                // A patch this project chose not to take. Always shown, never
                // collapsed into the other-versions footnote: the store is
                // deliberately not covered here and should keep being told so.
                if (($result['skipped'] ?? null) !== null) {
                    $lines[] = sprintf(
                        '  %s  %s  <comment>%s</comment>',
                        self::badge('SKIPPED'),
                        self::name($result, $widths),
                        self::safe((string) $result['skipped'])
                    );

                    // Anything it removed, or still has to remove. A target that
                    // was never there is not news; one that is still carrying a
                    // patch the project switched off very much is.
                    foreach ($result['fragments'] as $fragment) {
                        /** @var Fragment $fragment */
                        if ($fragment->verdict() === Fragment::SKIPPED) {
                            continue;
                        }

                        $lines[] = sprintf(
                            '      %s  %s',
                            self::state($fragment->verdict()),
                            self::shorten($fragment->target())
                        );
                    }

                    continue;
                }

                $elsewhere[(string) ($result['line'] ?? 'other')][] = (string) $result['id'];
                continue;
            }

            $counts = self::counts($result);
            $notCovered += $counts['not-applicable'];

            // verify is read by a pipeline and by whoever is staring at a red
            // build. Rows that are fine are noise in both cases.
            if ($failuresOnly && $counts['unapplied'] === 0 && $counts['problems'] === 0) {
                continue;
            }

            // The tally is right-aligned so the numbers form a column: a "0 of
            // 8" is meant to catch the eye against a wall of "9 targets".
            $lines[] = rtrim(sprintf(
                '  %s  %s  %s',
                self::badge(self::verdictOf($counts)),
                self::name($result, $widths),
                str_pad(self::tally($counts), $widths['tally'], ' ', STR_PAD_LEFT)
            ));

            foreach ($result['fragments'] as $fragment) {
                /** @var Fragment $fragment */
                $ordinary = $fragment->verdict() === Fragment::ALREADY
                    || $fragment->verdict() === Fragment::APPLIED;

                if (!$verbose && $ordinary) {
                    continue;
                }

                $lines[] = sprintf(
                    '      %s  %s',
                    self::state($fragment->verdict()),
                    self::shorten($fragment->target())
                );

                if ($fragment->reason() !== null) {
                    foreach (explode("\n", wordwrap(self::safe($fragment->reason()), 76)) as $line) {
                        $lines[] = str_repeat(' ', 19) . '<comment>' . $line . '</comment>';
                    }
                }
            }
        }

        foreach (self::footnotes($elsewhere, $notCovered, $verbose) as $line) {
            $lines[] = $line;
        }

        // Only when there is genuinely nothing declared. Results that were all
        // filtered out here are patches built for another base version, which
        // the footnote has already counted.
        if ($lines === [] && $results === []) {
            $lines[] = '  No patches declared.';
        }

        return $lines;
    }

    /**
     * The catalogue: what is on offer, grouped by the package that declares it
     * and then by the base version it was built for.
     *
     * The base version is printed once per group rather than once per row.
     * Sixteen rows that each repeat "2.4.6-p15" are sixteen rows in which the
     * one column that varies is the hardest to find.
     *
     * @param array<int, array<string, mixed>> $entries
     *
     * @return string[]
     */
    public static function catalogue(array $entries, bool $verbose): array
    {
        if ($entries === []) {
            return ['  No patches declared.'];
        }

        $byOwner = [];
        foreach ($entries as $entry) {
            $byOwner[(string) $entry['owner']][(string) ($entry['line'] ?? 'any')][] = $entry;
        }

        $width = ['id' => 0, 'name' => 0, 'line' => 0];
        foreach ($entries as $entry) {
            $width['id'] = max($width['id'], strlen((string) $entry['id']));
            $width['name'] = max($width['name'], strlen(self::humanName($entry)));
            $width['line'] = max($width['line'], strlen((string) ($entry['line'] ?? 'any')));
        }

        $lines = [];
        $applies = 0;

        foreach ($byOwner as $owner => $lineGroups) {
            $lines[] = '';
            $lines[] = sprintf('  <info>%s</info>', self::safe((string) $owner));
            $lines[] = '';

            foreach ($lineGroups as $line => $group) {
                $first = true;

                foreach ($group as $entry) {
                    if ($entry['applicable']) {
                        $applies++;
                    }

                    // A patch switched off looks nothing like one built for
                    // another release, and the catalogue was the last surface
                    // where the two were indistinguishable.
                    if (($entry['skipped'] ?? null) !== null) {
                        $note = '  <comment>SKIPPED</comment>';
                    } elseif ($entry['applicable']) {
                        $note = '  <info>applies here</info>';
                    } else {
                        $note = '';
                    }

                    $needs = ($entry['depends'] ?? []) === []
                        ? ''
                        : '  <comment>after ' . self::safe(implode(', ', $entry['depends'])) . '</comment>';

                    $lines[] = rtrim(sprintf(
                        '  %s  %s  %s  %s%s',
                        str_pad($first ? self::safe((string) $line) : '', $width['line']),
                        str_pad(self::safe((string) $entry['id']), $width['id']),
                        str_pad(self::safe(self::humanName($entry)), $width['name']),
                        str_pad((string) $entry['targets'], 3, ' ', STR_PAD_LEFT),
                        $needs . $note
                    ));

                    if ($verbose) {
                        if (($entry['description'] ?? null) !== null) {
                            foreach (explode("\n", wordwrap(self::safe((string) $entry['description']), 76)) as $wrapped) {
                                $lines[] = str_repeat(' ', 6) . $wrapped;
                            }
                        }

                        $lines[] = sprintf(
                            '%s<comment>%s</comment>',
                            str_repeat(' ', 6),
                            self::safe((string) $entry['source'])
                        );

                        if ($entry['reason'] !== null) {
                            foreach (explode("\n", wordwrap(self::safe((string) $entry['reason']), 76)) as $wrapped) {
                                $lines[] = str_repeat(' ', 6) . '<comment>' . $wrapped . '</comment>';
                            }
                        }
                    }

                    $first = false;
                }
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            '  %d patch%s · %d apply to this install%s',
            count($entries),
            count($entries) === 1 ? '' : 'es',
            $applies,
            $verbose ? '' : ' <comment>(-v for sources and reasons)</comment>'
        );

        return $lines;
    }

    /**
     * The header on `magento-patches:status`: the store, before its patches.
     *
     * @param array<string, mixed> $environment
     *
     * @return string[]
     */
    public static function environment(array $environment): array
    {
        $rows = [];

        $product = $environment['product'];
        $rows['Magento'] = $product === null
            ? 'no product package found'
            : self::safe($product['package'] . ' ' . $product['version']);

        $life = $environment['lifecycle'];
        if ($life === null) {
            $rows['End of life'] = 'unknown — this release is newer than the shipped manifest';
        } elseif ($life['days'] < 0) {
            $rows['End of life'] = sprintf('<error>%s — passed %d days ago</error>', $life['date'], abs($life['days']));
        } else {
            $rows['End of life'] = sprintf('%s — in %d days', $life['date'], $life['days']);
        }

        $sources = [];
        foreach ($environment['sources'] as $owner => $count) {
            $sources[] = sprintf('%s (%d)', self::safe((string) $owner), (int) $count);
        }
        $rows['Patch sources'] = $sources === [] ? 'none trusted' : implode(', ', $sources);

        $rows['Last applied'] = $environment['lastApplied'] ?? 'never, by this plugin';

        $width = 0;
        foreach (array_keys($rows) as $label) {
            $width = max($width, strlen($label));
        }

        $lines = [];
        foreach ($rows as $label => $value) {
            $lines[] = sprintf('  <comment>%s</comment>  %s', str_pad($label, $width), $value);
        }

        return $lines;
    }

    /**
     * The banner every command ends on.
     *
     * A wall of green rows with one amber one in the middle is exactly the
     * shape people skim past, and the whole premise here is that an unpatched
     * store is never allowed to look like silence. So the last thing printed
     * says, in one line and in one word, whether this store is fine — and it
     * says it with punctuation as well as colour, because CI logs have no
     * colour and that is where it matters most.
     *
     * @param array<int, array<string, mixed>> $results
     * @param string[] $errors
     * @param array<int, array{level: string, message: string}> $advisories
     */
    public static function banner(array $results, array $errors, array $advisories = []): string
    {
        $code = $errors === [] ? self::exitCode($results) : self::CONFIG_ERROR;
        $counts = self::totals($results);

        if ($code !== self::OK) {
            return self::band('white', 'red', '!!! ISSUE', self::wrongWith($code, $counts, $errors));
        }

        $applied = $counts['applied'] + $counts['already'];
        $chosen = self::chosen($results);

        if ($chosen > 0 || $advisories !== []) {
            // Never ISSUE: a skip is what the project asked for and the exit
            // code is unchanged. Never silent either, and never one at the
            // expense of the other — a store can be both deliberately
            // uncovered and past end of life, and the banner is the last line
            // anyone reads.
            $why = [];

            if ($chosen > 0) {
                $why[] = sprintf(
                    '%d patch%s switched off by this project',
                    $chosen,
                    $chosen === 1 ? '' : 'es'
                );
            }

            if ($advisories !== []) {
                $why[] = sprintf(
                    '%d advisor%s above',
                    count($advisories),
                    count($advisories) === 1 ? 'y' : 'ies'
                );
            }

            return self::band('black', 'yellow', '!   WARN ', sprintf(
                '%d of %d targets applied — %s',
                $applied,
                $applied + $counts['unapplied'],
                implode(', ', $why)
            ));
        }

        return self::band('black', 'green', '    OK   ', sprintf(
            '%d of %d targets applied%s',
            $applied,
            $applied + $counts['unapplied'],
            $counts['not-applicable'] === 0
                ? ''
                : sprintf(', %d not covered here', $counts['not-applicable'])
        ));
    }

    /**
     * How many patches this project switched off.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private static function chosen(array $results): int
    {
        $chosen = 0;

        foreach ($results as $result) {
            if (($result['skipped'] ?? null) !== null) {
                $chosen++;
            }
        }

        return $chosen;
    }

    /**
     * @param array<string, int> $counts
     * @param string[] $errors
     */
    private static function wrongWith(int $code, array $counts, array $errors): string
    {
        if ($code === self::CONFIG_ERROR) {
            return sprintf(
                '%d configuration problem%s — patches were dropped, not applied',
                count($errors),
                count($errors) === 1 ? '' : 's'
            );
        }

        if ($code === self::CONFLICT) {
            return sprintf(
                '%d target%s match neither side of its patch — nothing was overwritten',
                $counts['problems'],
                $counts['problems'] === 1 ? '' : 's'
            );
        }

        return sprintf(
            '%d target%s not applied — run composer magento-patches:apply',
            $counts['unapplied'],
            $counts['unapplied'] === 1 ? '' : 's'
        );
    }

    /**
     * A padded band, so the colour reads as a block rather than as tinted text.
     */
    private static function band(string $fg, string $bg, string $marker, string $message): string
    {
        return sprintf(
            '<fg=%s;bg=%s;options=bold> %s </><fg=%s;bg=%s> %s </>',
            $fg,
            $bg,
            $marker,
            $fg,
            $bg,
            str_pad($message, 66)
        );
    }

    /**
     * @param array<string, array<int, string>> $elsewhere
     *
     * @return string[]
     */
    private static function footnotes(array $elsewhere, int $notCovered, bool $verbose): array
    {
        $lines = [];

        if ($notCovered > 0 && !$verbose) {
            // Asked for explicitly: without this, the only sign of a target
            // nobody is patching is a number in the headline.
            $lines[] = '';
            $lines[] = sprintf(
                '  <comment>%d target%s not covered on this store</comment> — the module is replaced or not installed',
                $notCovered,
                $notCovered === 1 ? '' : 's'
            );
        }

        if ($elsewhere === []) {
            return $lines;
        }

        $total = 0;
        foreach ($elsewhere as $ids) {
            $total += count($ids);
        }

        $lines[] = '';
        $lines[] = sprintf(
            '  <comment>%d patch%s built for another base version</comment>%s',
            $total,
            $total === 1 ? '' : 'es',
            $verbose ? '' : ' <comment>(-v to list)</comment>'
        );

        if (!$verbose) {
            return $lines;
        }

        // Base-version keys and ids are written by the patch package. Every
        // other row goes through safe(); this one did not, so an id carrying a
        // newline and an <fg=...> tag could paint a forged "everything applied"
        // line into a CI log. Most rows on a real store are footnotes.
        $labels = [];
        foreach ($elsewhere as $line => $ids) {
            $labels[self::safe((string) $line)] = array_map([self::class, 'safe'], $ids);
        }

        $width = 0;
        foreach (array_keys($labels) as $line) {
            $width = max($width, strlen((string) $line));
        }

        foreach ($labels as $line => $ids) {
            $lines[] = sprintf('      %s  %s', str_pad((string) $line, $width), implode(', ', $ids));
        }

        return $lines;
    }

    /**
     * Column widths, measured from the patches that actually apply here.
     *
     * @param array<int, array<string, mixed>> $results
     *
     * @return array{id: int, name: int, line: int, tally: int}
     */
    private static function widths(array $results): array
    {
        $widths = ['id' => 0, 'name' => 0, 'line' => 0, 'tally' => 0];

        foreach ($results as $result) {
            if (
                $result['applicable'] === false
                && empty($result['blocked'])
                && ($result['skipped'] ?? null) === null
            ) {
                continue;
            }

            $widths['id'] = max($widths['id'], strlen((string) $result['id']));
            $widths['name'] = max($widths['name'], strlen(self::humanName($result)));
            $widths['line'] = max($widths['line'], strlen((string) ($result['line'] ?? '')));

            if ($result['applicable'] !== false) {
                $widths['tally'] = max($widths['tally'], strlen(self::tally(self::counts($result))));
            }
        }

        return $widths;
    }

    /**
     * The part of the label that is neither the id nor the base version.
     *
     * @param array<string, mixed> $result
     */
    private static function humanName(array $result): string
    {
        $name = substr((string) $result['label'], strlen((string) $result['id']));

        if (($result['line'] ?? null) !== null) {
            $name = str_replace('[' . $result['line'] . ']', '', $name);
        }

        return trim($name);
    }

    /**
     * @param array<string, mixed> $result
     * @param array{id: int, name: int, line: int, tally: int} $widths
     */
    private static function name(array $result, array $widths): string
    {
        // Not trimmed. A patch with no base version leaves that column empty,
        // and trimming it slides everything after it left by ten characters.
        return sprintf(
            '%s  %s  %s',
            str_pad(self::safe((string) $result['id']), $widths['id']),
            str_pad(self::safe(self::humanName($result)), $widths['name']),
            str_pad(self::safe((string) ($result['line'] ?? '')), $widths['line'])
        );
    }

    /**
     * Lower case for the states that are fine, upper case for the ones that are
     * not. Colour says the same thing, but only where there is colour.
     */
    private static function badge(string $word): string
    {
        $tag = 'info';

        if ($word === 'MISSING' || $word === 'BLOCKED' || $word === 'SKIPPED') {
            $tag = 'comment';
        } elseif ($word === 'CONFLICT') {
            $tag = 'error';
        }

        return sprintf('<%s>%s</%s>', $tag, str_pad($word, 8), $tag);
    }

    private static function state(string $verdict): string
    {
        $words = [
            Fragment::APPLIED => 'applied',
            Fragment::SUPERSEDED => 'replaced',
            Fragment::ALREADY => 'in place',
            Fragment::NOT_APPLICABLE => 'not covered',
            Fragment::APPLICABLE => 'MISSING',
            Fragment::CONFLICT => 'CONFLICT',
            Fragment::MIRROR_CONFLICT => 'CONFLICT',
            Fragment::REVERTED => 'removed',
            Fragment::SKIPPED => 'not wanted',
            Fragment::UNWANTED => 'PRESENT',
        ];

        $word = $words[$verdict] ?? $verdict;
        $tag = $word === 'CONFLICT' ? 'error' : ($word === 'MISSING' || $word === 'PRESENT' ? 'comment' : 'info');

        return sprintf('<%s>%s</%s>', $tag, str_pad($word, 11), $tag);
    }

    /**
     * @param array<string, int> $counts
     */
    private static function verdictOf(array $counts): string
    {
        if ($counts['problems'] > 0) {
            return 'CONFLICT';
        }

        if ($counts['unapplied'] > 0) {
            return 'MISSING';
        }

        if ($counts['reverted'] > 0) {
            return 'reverted';
        }

        return $counts['applied'] > 0 ? 'applied' : 'ok';
    }

    /**
     * Always "N of M", never "M targets", so the column is a column: a 0 of 8
     * has to catch the eye against a row of 9 of 9, and it cannot do that if
     * the two are different shapes.
     *
     * @param array<string, int> $counts
     */
    private static function tally(array $counts): string
    {
        $good = $counts['applied'] + $counts['already'] + $counts['reverted'];
        $total = $good + $counts['unapplied'] + $counts['not-applicable'] + $counts['problems'];

        return $total === 0 ? 'nothing to do' : sprintf('%d of %d', $good, $total);
    }

    /**
     * Every target starts with vendor/, so it carries no information and costs
     * seven columns on every line.
     */
    private static function shorten(string $path): string
    {
        return self::safe(strpos($path, 'vendor/') === 0 ? substr($path, 7) : $path);
    }

    /**
     * Ids, labels, base versions, source paths and reasons are all strings out
     * of a package's composer.json. They reach a console that interprets
     * `<info>` tags and ANSI escapes, so a compromised trusted package could
     * otherwise paint extra rows and a convincing green OK band into a CI log.
     *
     * It could never forge the real banner — that is printed last, with nothing
     * after it — but a log that looks patched is close enough to the thing this
     * plugin exists to prevent.
     */
    public static function safe(string $text): string
    {
        // Newline and tab included: they are how a package-controlled string
        // forges extra rows in a report, and nothing legitimate needs them —
        // wrapping is done by the renderer, after this.
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);

        return str_replace(['<', '>'], ['\\<', '\\>'], $text);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     *
     * @return array<string, int>
     */
    private static function totals(array $results): array
    {
        $totals = [
            'applied' => 0,
            'already' => 0,
            'not-applicable' => 0,
            'unapplied' => 0,
            'reverted' => 0,
            'skipped' => 0,
            'unwanted' => 0,
            'problems' => 0,
        ];

        foreach ($results as $result) {
            // Same predicate as exitCode(): a refused reversal fails the run, so
            // the banner explaining the failure has to count it too. Skipping it
            // here produced "!!! ISSUE  0 target match neither side of its
            // patch" — a run that correctly fails, under a sentence saying
            // nothing happened.
            if ($result['applicable'] === false && ($result['skipped'] ?? null) === null) {
                continue;
            }

            foreach (self::counts($result) as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return $totals;
    }

    /**
     * The headline, or an honest replacement for it when configuration errors
     * mean there is nothing to count. "0 patches: nothing to do" on a store
     * whose trust entry is missing reads as reassurance, and it is the opposite.
     *
     * @param array<int, array<string, mixed>> $results
     * @param string[] $errors
     */
    public static function summary(array $results, array $errors): string
    {
        if ($results === [] && $errors !== []) {
            return '<error>no patches could be read — see the configuration errors above</error>';
        }

        return self::headline($results);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    public static function exitCode(array $results): int
    {
        $code = self::OK;

        foreach ($results as $result) {
            // A skipped patch is not applicable, but a reversal that refused is
            // still a failure to do what the project's own config asked for —
            // and it leaves the store carrying a patch the project believes is
            // switched off, which is a lie in the other direction.
            if ($result['applicable'] === false && ($result['skipped'] ?? null) === null) {
                continue;
            }

            foreach ($result['fragments'] as $fragment) {
                /** @var Fragment $fragment */
                if ($fragment->isFailure()) {
                    return self::CONFLICT;
                }

                if ($result['applicable'] !== false && $fragment->isUnapplied()) {
                    $code = self::UNAPPLIED;
                }
            }
        }

        return $code;
    }

    /**
     * An advisory wrapped to a readable width, styled by severity.
     *
     * These sentences are long by nature — they name a package, a version and a
     * date, and then say what to do about it. Printed as one line they run off
     * the side of a terminal and get skimmed past, which defeats the point.
     *
     * @param array{level: string, message: string} $advisory
     *
     * @return string[]
     */
    public static function advisoryLines(array $advisory, string $indent = ''): array
    {
        $tag = $advisory['level'] === 'critical' ? 'error' : 'comment';
        $lines = [];

        foreach (explode("\n", wordwrap($advisory['message'], 88)) as $index => $line) {
            $lines[] = sprintf('%s<%s>%s</%s>', $indent . ($index === 0 ? '' : '  '), $tag, $line, $tag);
        }

        return $lines;
    }

    /**
     * Machine-readable equivalent of the same information, for pipelines that
     * want to act on it rather than read it.
     *
     * @param array<int, array<string, mixed>> $results
     * @param string[] $errors
     */
    public static function json(array $results, array $errors = []): string
    {
        $out = [
            // The same code the process exits with. A body saying 0 while the
            // process says 3 is worse than no field at all — the consumer of
            // this mode is automation, and automation reads the body.
            'exit_code' => $errors === [] ? self::exitCode($results) : self::CONFIG_ERROR,
            'errors' => $errors,
            'patches' => [],
        ];

        foreach ($results as $result) {
            $patch = [
                'id' => $result['id'],
                'label' => $result['label'],
                'owner' => $result['owner'],
                'source' => $result['sourceRelative'],
                'line' => $result['line'],
                'applicable' => $result['applicable'],
                'blocked' => !empty($result['blocked']),
                'skipped' => $result['skipped'] ?? null,
                'reason' => $result['reason'],
                'targets' => [],
            ];

            foreach ($result['fragments'] as $fragment) {
                /** @var Fragment $fragment */
                $patch['targets'][] = array_filter([
                    'path' => $fragment->target(),
                    'state' => $fragment->verdict(),
                    'reason' => $fragment->reason(),
                    'written' => $fragment->written(),
                ], static function ($value) {
                    return $value !== null && $value !== [];
                });
            }

            $out['patches'][] = $patch;
        }

        // A target path or a git error can carry bytes that are not valid
        // UTF-8 — a quoted diff header is enough. json_encode then returns
        // false, (string) false is '', and `magento-patches:verify --json` printed an
        // empty document while a security patch was genuinely missing.
        return (string) json_encode(
            $out,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    public static function headline(array $results): string
    {
        $patches = 0;
        $skipped = 0;
        $blocked = 0;
        $chosen = 0;
        $totals = ['applied' => 0, 'already' => 0, 'not-applicable' => 0, 'problems' => 0, 'unapplied' => 0, 'reverted' => 0];

        foreach ($results as $result) {
            if ($result['applicable'] === false) {
                // A refused chain member is a problem, not a patch built for
                // some other version. Counting them together reads as "nothing
                // to see here", which is the opposite of the truth. A patch the
                // project deliberately switched off is a third thing again.
                if (!empty($result['blocked'])) {
                    $blocked++;
                } elseif (($result['skipped'] ?? null) !== null) {
                    $chosen++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $patches++;
            $counts = self::counts($result);
            foreach ($totals as $key => $_) {
                $totals[$key] += $counts[$key];
            }
        }

        // Only the buckets that actually have something in them. A line reading
        // "0 applied, 0 missing, 0 not covered, 0 problems" is 105 characters
        // of mostly nothing, and it wraps in an 80-column terminal.
        $parts = [];

        foreach (
            [
                'applied' => 'applied',
                'already' => 'in place',
                'unapplied' => 'missing',
                'not-applicable' => 'not covered',
                'problems' => 'problems',
            ] as $key => $noun
        ) {
            if ($totals[$key] > 0) {
                $parts[] = $totals[$key] . ' ' . $noun;
            }
        }

        if ($blocked > 0) {
            $parts[] = $blocked . ' blocked';
        }

        if ($chosen > 0) {
            $parts[] = $chosen . ' skipped';
        }

        if ($skipped > 0) {
            $parts[] = $skipped . ' for other versions';
        }

        return sprintf(
            '%d patch%s · %s',
            $patches,
            $patches === 1 ? '' : 'es',
            $parts === [] ? 'nothing to do' : implode(' · ', $parts)
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, int>
     */
    private static function counts(array $result): array
    {
        $counts = [
            'applied' => 0,
            'already' => 0,
            'not-applicable' => 0,
            'unapplied' => 0,
            'reverted' => 0,
            'skipped' => 0,
            'unwanted' => 0,
            'problems' => 0,
        ];

        foreach ($result['fragments'] as $fragment) {
            /** @var Fragment $fragment */
            switch ($fragment->verdict()) {
                case Fragment::APPLIED:
                case Fragment::SUPERSEDED:
                    $counts['applied']++;
                    break;
                case Fragment::REVERTED:
                    $counts['reverted']++;
                    break;
                case Fragment::ALREADY:
                    $counts['already']++;
                    break;
                case Fragment::NOT_APPLICABLE:
                    $counts['not-applicable']++;
                    break;
                case Fragment::APPLICABLE:
                    $counts['unapplied']++;
                    break;
                case Fragment::SKIPPED:
                    $counts['skipped']++;
                    break;
                case Fragment::UNWANTED:
                    $counts['unwanted']++;
                    break;
                default:
                    $counts['problems']++;
            }
        }

        return $counts;
    }

}
