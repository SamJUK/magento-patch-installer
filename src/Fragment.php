<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

/**
 * One file's worth of a patch: the smallest thing that can be applied, skipped
 * or refused on its own.
 */
class Fragment
{
    /** Target file exists, patch applies cleanly. */
    public const APPLICABLE = 'applicable';

    /** Applied during this run. */
    public const APPLIED = 'applied';

    /** Reverse-check passes: this exact change is already in the file. */
    public const ALREADY = 'already';

    /** Nothing to patch, and nothing vulnerable at this path. */
    public const NOT_APPLICABLE = 'not-applicable';

    /** Target present but matches neither side of the patch. Never overwritten. */
    public const CONFLICT = 'conflict';

    /** Applied at the primary path, but the mirrored copy refused. */
    public const MIRROR_CONFLICT = 'mirror-conflict';

    /** Replaced a file this plugin had written for an earlier patch. */
    public const SUPERSEDED = 'superseded';

    /** Reversed out of the working tree. */
    public const REVERTED = 'reverted';

    /** This project skips the patch, and the target does not carry it. */
    public const SKIPPED = 'skipped';

    /** This project skips the patch, but the target still carries it. */
    public const UNWANTED = 'unwanted';

    /** @var string Path as written in the patch, relative to the project root. */
    private $target;

    /** @var string[] */
    private $lines;

    /** @var bool True when the fragment creates the file (`--- /dev/null`). */
    private $createsFile;

    /** @var string */
    private $verdict = self::APPLICABLE;

    /** @var string|null Human-readable explanation, shown next to the verdict. */
    private $reason;

    /** @var string[] Paths actually written, primary first. */
    private $written = [];

    /**
     * @param string[] $lines
     */
    public function __construct(string $target, array $lines, bool $createsFile)
    {
        $this->target = $target;
        $this->lines = $lines;
        $this->createsFile = $createsFile;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function createsFile(): bool
    {
        return $this->createsFile;
    }

    public function diff(): string
    {
        return implode("\n", $this->lines) . "\n";
    }

    /**
     * The composer package that owns the target, or null for a project path.
     */
    public function owningPackage(): ?string
    {
        if (preg_match('#^vendor/([^/]+)/([^/]+)/#', $this->target, $m) === 1) {
            return $m[1] . '/' . $m[2];
        }

        return null;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return string[]
     */
    public function written(): array
    {
        return $this->written;
    }

    public function resolve(string $verdict, ?string $reason = null): void
    {
        $this->verdict = $verdict;
        $this->reason = $reason;
    }

    public function recordWrite(string $path): void
    {
        $this->written[] = $path;
    }

    public function wasWritten(): bool
    {
        return $this->verdict === self::APPLIED || $this->verdict === self::SUPERSEDED;
    }

    public function isFailure(): bool
    {
        return $this->verdict === self::CONFLICT || $this->verdict === self::MIRROR_CONFLICT;
    }

    /**
     * Applicable but still unapplied: the silent-revert case.
     */
    public function isUnapplied(): bool
    {
        return $this->verdict === self::APPLICABLE;
    }
}
