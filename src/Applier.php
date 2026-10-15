<?php

// Copyright (c) 2026 Sam James. Licensed under the Open Software License version 3.0.
// SPDX-License-Identifier: OSL-3.0

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

use Composer\Util\ProcessExecutor;

/**
 * Thin wrapper over `git apply`.
 *
 * One applier, one strip level, one working directory. Deliberately no fallback
 * cascade over other tools and strip levels: with a path filter in play, `-p0`
 * matches nothing, exits 0, and reports success having changed not one byte.
 */
class Applier
{
    private const STRIP_LEVEL = 1;

    /** @var ProcessExecutor */
    private $process;

    /** @var string */
    private $projectRoot;

    /** @var string|null */
    private $tempDir;

    /** @var string per instance, so a scratch view cannot overwrite it */
    private $lastError = '';

    public function __construct(ProcessExecutor $process, string $projectRoot)
    {
        $this->process = $process;
        $this->projectRoot = $projectRoot;
    }

    /**
     * Can this diff be applied to the working tree as it stands?
     */
    public function canApply(string $diff): bool
    {
        $code = $this->run($diff, ['--check', '-v']);
        $verbose = $this->lastError;
        $this->lastError = self::quiet($verbose);

        if ($code !== 0) {
            return false;
        }

        $moved = self::offsets($verbose);

        if ($moved === []) {
            return true;
        }

        $why = $this->appliedAgain($diff, $moved);

        if ($why !== null) {
            $this->lastError = $why;

            return false;
        }

        return true;
    }

    /**
     * Is this exact change already present? A clean reverse-check is the only
     * honest way to tell "already applied" from "conflicts with local changes".
     */
    public function isApplied(string $diff): bool
    {
        return $this->run($diff, ['-R', '--check']) === 0;
    }

    /**
     * Why applying $diff would put lines in a second time, or null.
     *
     * A hunk whose leading context is boilerplate can match somewhere else
     * in the file, and git apply takes it there at an offset. That is right
     * for a file that has only moved. It is wrong when the hunk's own lines
     * are already in the file between where it was made for and where git
     * would put it: a patch left behind once something stacked on it went
     * away no longer reverse-checks, and git apply then wrote its block again
     * further down while verify passed on two copies.
     *
     * @param array<int, array{line: int, offset: int}> $moved hunks the forward check placed at an offset
     */
    private function appliedAgain(string $diff, array $moved): ?string
    {
        $fragments = (new Splitter())->split($diff);
        $contents = $fragments === [] ? false : @file_get_contents($this->projectRoot . '/' . $fragments[0]->target());

        if ($contents === false) {
            return null;
        }

        // Each hunk's start, and its lines as they read once applied: context
        // and additions, with what it removes left out.
        $starts = $after = [];
        $hunk = 0;

        foreach (explode("\n", $diff) as $line) {
            $line = rtrim($line, "\r");

            if (preg_match('/^@@ -(\d+)/', $line, $header) === 1) {
                $hunk++;
                $starts[$hunk] = (int) $header[1];
            } elseif ($hunk > 0 && ($line === '' || $line[0] === ' ' || $line[0] === '+')) {
                $after[$hunk][] = [$line !== '' && $line[0] === '+', (string) substr($line, 1)];
            }
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $contents));

        foreach ($moved as $number => $where) {
            $run = $this->firstAddition($after[$number] ?? []);

            if ($run === null) {
                continue;
            }

            // Its own lines, beside the context they belong after, already in
            // the file as whole lines, between where it was made for and where
            // git would put it: a second copy. The same lines anywhere else (a
            // `deny all;`, the loop beside it) are another place.
            $start = $starts[$number] ?? $where['line'];
            $from = min($start, $where['line']) - 3;
            $to = max($start, $where['line']) + 3;

            for ($i = max(0, $from - 1), $last = min(count($lines) - count($run), $to); $i <= $last; $i++) {
                if (array_slice($lines, $i, count($run)) === $run) {
                    return sprintf(
                        'hunk %d would go in a second time, %d lines from where it belongs: the lines it adds are already in %s',
                        $number,
                        abs($where['offset']),
                        $fragments[0]->target()
                    );
                }
            }
        }

        return null;
    }

    /**
     * A hunk's first run of added lines with up to three context lines before
     * it, or after it where nothing comes before: null when there is no such
     * run, or no context to place it, or nothing in it but blanks and braces.
     *
     * @param array<int, array{0: bool, 1: string}> $after a hunk's lines once applied, each flagged when added
     *
     * @return string[]|null
     */
    private function firstAddition(array $after): ?array
    {
        $start = null;

        foreach ($after as $position => [$added]) {
            if ($added) {
                $start = $position;
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $end = $start;
        while (isset($after[$end + 1]) && $after[$end + 1][0]) {
            $end++;
        }

        $added = array_column(array_slice($after, $start, $end - $start + 1), 1);
        $before = array_column(array_slice($after, max(0, $start - 3), $start - max(0, $start - 3)), 1);
        $context = $before !== [] ? $before : array_column(array_slice($after, $end + 1, 3), 1);

        $telling = array_filter($added, static function (string $line): bool {
            return strlen(trim($line, " \t{}();")) > 0;
        });

        if ($context === [] || $telling === []) {
            return null;
        }

        return $before !== [] ? array_merge($before, $added) : array_merge($added, $context);
    }

    /**
     * The hunks `git apply -v` placed somewhere other than where they say.
     *
     * @return array<int, array{line: int, offset: int}> hunk number => where it went, and how far that is
     */
    private static function offsets(string $verbose): array
    {
        preg_match_all('/^Hunk #(\d+) succeeded at (\d+) \(offset (-?\d+) lines?\)\./m', $verbose, $matches, PREG_SET_ORDER);
        $moved = [];

        foreach ($matches as [, $hunk, $line, $offset]) {
            $moved[(int) $hunk] = ['line' => (int) $line, 'offset' => (int) $offset];
        }

        return $moved;
    }

    /**
     * What `git apply -v` said, without the lines -v adds: those are for
     * offsets(), not for the reason a conflict gives.
     */
    private static function quiet(string $verbose): string
    {
        return trim((string) preg_replace('/^(Checking patch .*|Hunk #\d+ succeeded at .*|Applied patch .*)\R?/m', '', $verbose));
    }

    /**
     * Applied, once the later diffs stacked on the same file are peeled off a scratch copy?
     *
     * @param string[] $above later diffs for $path, in apply order
     */
    public function isAppliedBeneath(string $diff, string $path, array $above): bool
    {
        if ($this->isApplied($diff)) {
            return true;
        }

        if ($above === []) {
            return false;
        }

        return $this->inScratch($path, static function (self $view, callable $local) use ($diff, $above): bool {
            foreach (array_reverse($above) as $later) {
                if ($view->isApplied($later = $local($later))) {
                    $view->revert($later);
                }
            }

            return $view->isApplied($local($diff));
        });
    }

    /**
     * Applicable, once the earlier diffs it is built on are put back on a scratch copy?
     *
     * @param string[] $below earlier diffs for $path, in apply order
     */
    public function canApplyAbove(string $diff, string $path, array $below): bool
    {
        if ($this->canApply($diff)) {
            return true;
        }

        if ($below === []) {
            return false;
        }

        return $this->inScratch($path, static function (self $view, callable $local) use ($diff, $below): bool {
            foreach ($below as $earlier) {
                if (!$view->isApplied($earlier = $local($earlier)) && $view->canApply($earlier)) {
                    $view->apply($earlier);
                }
            }

            return $view->canApply($local($diff));
        });
    }

    public function apply(string $diff): bool
    {
        return $this->run($diff, []) === 0;
    }

    public function revert(string $diff): bool
    {
        return $this->run($diff, ['-R']) === 0;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * One empty directory per Composer run, forever, is not much — but it is on
     * every CI host that ever installs this.
     */
    public function __destruct()
    {
        if ($this->tempDir !== null) {
            foreach (glob($this->tempDir . '/*') ?: [] as $leftover) {
                @unlink($leftover);
            }

            @rmdir($this->tempDir);
            $this->tempDir = null;
        }
    }

    /**
     * Rewrite a diff to target a different path, for writing the mirrored copy
     * of a root-mapped file.
     */
    public function retarget(string $diff, string $from, string $to): string
    {
        return (new Splitter())->retarget($diff, $from, $to);
    }

    /**
     * @param string[] $flags
     */
    private function run(string $diff, array $flags): int
    {
        $file = $this->write($diff);

        // --whitespace=nowarn because the default is read from the user's git
        // configuration. With apply.whitespace=error a valid patch that adds a
        // trailing space fails --check and reports as a conflict; with =fix the
        // content written is not the content in the patch. Neither belongs in a
        // security tool's verdict.
        // core.autocrlf and core.eol are read from the user's git configuration,
        // and `git apply` runs content through git's conversion machinery even
        // for a path that is gitignored and has never been in the index. With
        // autocrlf=true — the Git for Windows default — a patch applies, exits
        // 0, and rewrites the whole file LF to CRLF. Both checks keep answering
        // "already applied" afterwards, because git normalises on read, so the
        // tool never notices it changed every line of the file. A Windows
        // developer's vendor tree then differs byte for byte from CI's, which
        // breaks Adobe's own patch-status detection and any file integrity
        // check downstream.
        //
        // An in-tree .gitattributes can still override this; that is a separate
        // problem and it fails loudly rather than silently.
        $command = sprintf(
            'git -c core.autocrlf=false -c core.eol=lf apply -p%d --whitespace=nowarn %s%s',
            self::STRIP_LEVEL,
            $flags === [] ? '' : implode(' ', $flags) . ' ',
            ProcessExecutor::escape($file)
        );

        // offsets() reads what -v prints, and git translates it: under a German
        // locale "Hunk #1 succeeded at" never appears and the duplicate guard
        // switched itself off. Composer only sets this for its own VCS calls.
        $language = getenv('LANGUAGE');
        self::setEnv('LANGUAGE', 'C');

        try {
            $code = $this->process->execute($command, $output, $this->projectRoot);
            $this->lastError = trim($this->process->getErrorOutput());

            return $code;
        } finally {
            self::setEnv('LANGUAGE', $language === false ? null : $language);
            @unlink($file);
        }
    }

    /**
     * Where the process Composer starts reads its environment from: putenv()
     * alone is not enough, as it keeps only what $_SERVER also has.
     */
    private static function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);

            return;
        }

        putenv($name . '=' . $value);
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

        private function write(string $diff): string
    {
        if ($this->tempDir === null) {
            $this->tempDir = $this->makeTempDir();
        }

        $file = $this->tempDir . '/' . bin2hex(random_bytes(8)) . '.patch';

        if (file_put_contents($file, $diff) === false) {
            throw new \RuntimeException('Unable to write a temporary patch to ' . $file);
        }

        return $file;
    }

    private function makeTempDir(): string
    {
        // A predictable name a local user could pre-create — or point at a
        // directory they own — would let them swap the diff between it
        // being written and git reading it. Refuse anything we did not just
        // create ourselves.
        $dir = sys_get_temp_dir() . '/magento-patch-installer-' . bin2hex(random_bytes(8));

        if (!@mkdir($dir, 0700)) {
            throw new \RuntimeException('Unable to create a temporary directory at ' . $dir);
        }

        return $dir;
    }

    /**
     * Ask a question of a throwaway copy of $path, named "view".
     *
     * @param callable(self, callable(string): string): bool $question gets the view and a diff retargeter
     */
    private function inScratch(string $path, callable $question): bool
    {
        $source = $this->projectRoot . '/' . $path;

        if (!is_file($source)) {
            return false;
        }

        $scratch = $this->makeTempDir();
        $local = function (string $diff) use ($path): string {
            return $this->retarget($diff, $path, 'view');
        };

        try {
            return @copy($source, $scratch . '/view') && $question(new self($this->process, $scratch), $local);
        } finally {
            @unlink($scratch . '/view');
            @rmdir($scratch);
        }
    }
}
