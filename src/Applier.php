<?php

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
        return $this->run($diff, ['--check']) === 0;
    }

    /**
     * Is this exact change already present? A clean reverse-check is the only
     * honest way to tell "already applied" from "conflicts with local changes".
     */
    public function isApplied(string $diff): bool
    {
        return $this->run($diff, ['-R', '--check']) === 0;
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
        return trim($this->process->getErrorOutput());
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
        $patterns = [
            'diff --git a/' . $from . ' b/' . $from => 'diff --git a/' . $to . ' b/' . $to,
            '--- a/' . $from => '--- a/' . $to,
            '+++ b/' . $from => '+++ b/' . $to,
        ];

        return str_replace(array_keys($patterns), array_values($patterns), $diff);
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

        try {
            return $this->process->execute($command, $output, $this->projectRoot);
        } finally {
            @unlink($file);
        }
    }

    private function write(string $diff): string
    {
        if ($this->tempDir === null) {
            // A predictable name a local user could pre-create — or point at a
            // directory they own — would let them swap the diff between it
            // being written and git reading it. Refuse anything we did not just
            // create ourselves.
            $dir = sys_get_temp_dir() . '/magento-patch-installer-' . bin2hex(random_bytes(8));

            if (!@mkdir($dir, 0700)) {
                throw new \RuntimeException('Unable to create a temporary directory at ' . $dir);
            }

            $this->tempDir = $dir;
        }

        $file = $this->tempDir . '/' . bin2hex(random_bytes(8)) . '.patch';

        if (file_put_contents($file, $diff) === false) {
            throw new \RuntimeException('Unable to write a temporary patch to ' . $file);
        }

        return $file;
    }
}
