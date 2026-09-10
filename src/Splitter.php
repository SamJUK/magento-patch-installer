<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

/**
 * Splits a patch file into one Fragment per target file.
 *
 * Handles both shapes Adobe ships: `diff --git` headers (the monthly isolated
 * patches) and a bare `--- `/`+++ ` pair behind a freeform preamble (hand-built
 * emergency patches). Anything before the first file header is discarded.
 *
 * "Bare" means without a `diff --git` line — the paths themselves still carry
 * the usual `a/` and `b/` prefixes, because everything is applied at -p1.
 *
 * Line endings are preserved exactly, so a patch built against CRLF files
 * still matches them. Classic Mac CR-only endings are not supported; nothing
 * has emitted them this century.
 */
class Splitter
{
    /**
     * @return Fragment[]
     */
    public function split(string $patchContents): array
    {
        // Split on the newline only, so a CRLF patch keeps its carriage
        // returns. They are content: a diff of a file that is stored CRLF
        // carries \r on every context and added line, and stripping them makes
        // the hunk match nothing at all.
        $lines = explode("\n", $patchContents);

        /** @var Fragment[] $fragments */
        $fragments = [];

        $buffer = [];
        $target = null;
        $minusPath = null;
        $createsFile = false;
        $sawHunk = false;
        $oldLeft = 0;
        $newLeft = 0;

        foreach ($lines as $index => $line) {
            // Inside a hunk, every line is body however much it looks like a
            // header: removing a line reading `-- x` emits `--- x`, and adding
            // `++ x` emits `+++ x`. Counting the hunk down from its own header
            // is the only way to tell the two apart.
            if (($oldLeft > 0 || $newLeft > 0) && $this->isHunkBody($line)) {
                $this->consume($line, $oldLeft, $newLeft);
                $buffer[] = $line;
                continue;
            }

            // Counts that do not match the body mean a malformed hunk header.
            // Trusting it over what the line plainly is would swallow every
            // following fragment into this one.
            $oldLeft = 0;
            $newLeft = 0;

            // A blank line outside a hunk carries nothing: trailing newlines at
            // the end of the file, or spacing in a freeform preamble. Inside a
            // hunk it is content, and the branch above already took it.
            if (rtrim($line, "\r") === '') {
                continue;
            }

            $opensGitHeader = strpos($line, 'diff --git ') === 0;

            // A `--- `/`+++ ` pair opens a new fragment only when it is not the
            // header block of the `diff --git` fragment we are already inside.
            $opensPlainHeader = strpos($line, '--- ') === 0
                && isset($lines[$index + 1])
                && strpos($lines[$index + 1], '+++ ') === 0
                && ($buffer === [] || $sawHunk);

            if ($opensGitHeader || $opensPlainHeader) {
                if ($buffer !== [] && $target !== null && $target !== '') {
                    $fragments[] = new Fragment($target, $buffer, $createsFile);
                }

                $buffer = [];
                $target = null;
                $minusPath = null;
                $createsFile = false;
                $sawHunk = false;
            }

            // Only a fallback, for a fragment that carries no `---`/`+++` pair
            // at all — a mode change or a pure rename. The regex cannot know
            // where an unquoted path containing spaces ends, which is precisely
            // why git writes the header pair and why that wins below.
            if ($opensGitHeader
                && preg_match('#^diff --git (?:"(?:\\\\.|[^"])*"|\S+) ("(?:\\\\.|[^"])*"|\S+)#', $line, $matches) === 1
            ) {
                $target = $this->stripPrefix($this->unquote($matches[1]));
            }

            if (strpos($line, '--- ') === 0) {
                $path = $this->pathFromHeader($line);
                $createsFile = $path === '/dev/null';
                $minusPath = $createsFile ? null : $path;
            }

            if (strpos($line, '+++ ') === 0) {
                $path = $this->pathFromHeader($line);
                // `+++ /dev/null` is a deletion; the target is the `---` side.
                $resolved = $path === '/dev/null' ? $minusPath : $path;

                if ($resolved !== null) {
                    $target = $resolved;
                }
            }

            if (strpos($line, '@@') === 0) {
                $sawHunk = true;
                $this->openHunk($line, $oldLeft, $newLeft);
            }

            // Unquote the header in the diff text as well as in the target.
            // Leaving them different means retarget() silently matches nothing,
            // and the mirrored copy of a root-mapped file is then checked
            // against the wrong path and reported as in step.
            $line = $this->normaliseHeader($line);

            // Skip the preamble: nothing before the first file header belongs
            // to a fragment.
            if ($buffer === [] && !$opensGitHeader && !$opensPlainHeader) {
                continue;
            }

            $buffer[] = $line;
        }

        if ($buffer !== [] && $target !== null && $target !== '') {
            $fragments[] = new Fragment($target, $buffer, $createsFile);
        }

        return $fragments;
    }

    /**
     * Read the line counts out of `@@ -1,4 +1,5 @@`. A missing count means one.
     */
    private function openHunk(string $line, int &$oldLeft, int &$newLeft): void
    {
        // PREG_UNMATCHED_AS_NULL so an omitted count is distinguishable from a
        // count of zero: `@@ -1 +1,2 @@` means one line, not none.
        if (preg_match('/^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $line, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return;
        }

        $oldLeft = $m[1] === null ? 1 : (int) $m[1];
        $newLeft = $m[2] === null ? 1 : (int) $m[2];
    }

    /**
     * Could this line be part of a hunk body? Every body line carries a marker;
     * `--- x` and `+++ x` inside a hunk are a removal and an addition, which is
     * exactly the ambiguity the counts exist to resolve.
     */
    private function isHunkBody(string $line): bool
    {
        $line = rtrim($line, "\r");

        return $line === '' || strpos(' +-\\', $line[0]) !== false;
    }

    /**
     * Account for one body line against the hunk's remaining counts.
     */
    private function consume(string $line, int &$oldLeft, int &$newLeft): void
    {
        // Some tools write a bare empty line for an empty context line, and on
        // a CRLF patch that line is just the carriage return.
        $line = rtrim($line, "\r");
        $marker = $line === '' ? ' ' : $line[0];

        if ($marker === '\\') {
            // "\ No newline at end of file" annotates the line before it.
            return;
        }

        if ($marker !== '+') {
            $oldLeft = max(0, $oldLeft - 1);
        }

        if ($marker !== '-') {
            $newLeft = max(0, $newLeft - 1);
        }
    }

    /**
     * Rewrite a quoted `--- `/`+++ ` header to the plain path. Any other line
     * is returned exactly as it came in.
     */
    private function normaliseHeader(string $line): string
    {
        if (strpos($line, '--- ') !== 0 && strpos($line, '+++ ') !== 0) {
            return $line;
        }

        $eol = substr($line, -1) === "\r" ? "\r" : '';
        $parts = explode("\t", rtrim(substr($line, 4), "\r"), 2);
        $path = trim($parts[0]);

        if (strlen($path) < 2 || $path[0] !== '"' || substr($path, -1) !== '"') {
            return $line;
        }

        return substr($line, 0, 4)
            . $this->unquote($path)
            . (isset($parts[1]) ? "\t" . $parts[1] : '')
            . $eol;
    }

    private function pathFromHeader(string $line): string
    {
        $path = substr($line, 4);
        // Unified diff headers may carry a tab-separated timestamp.
        $path = explode("\t", $path)[0];

        return $this->stripPrefix($this->unquote(trim($path)));
    }

    /**
     * git quotes a path containing control or non-ASCII characters, C-style.
     */
    private function unquote(string $path): string
    {
        if (strlen($path) > 1 && $path[0] === '"' && substr($path, -1) === '"') {
            $path = stripcslashes(substr($path, 1, -1));
        }

        // Collector::confine() refuses a null byte in a declared path. Paths
        // coming out of the diff itself had no such check, and git's quoted
        // form carries \0 through stripcslashes as a real NUL — which makes
        // realpath() and hash_file() throw ValueError rather than return false.
        // Nothing between the plugin's event handler and here catches
        // Throwable, so that was a stack trace out of every consumer's
        // composer install. No such file can exist, so there is nothing to lose
        // by refusing it.
        return strpos($path, "\0") === false ? $path : '';
    }

    private function stripPrefix(string $path): string
    {
        if ($path === '/dev/null') {
            return $path;
        }

        return (string) preg_replace('#^[ab]/#', '', $path);
    }
}
