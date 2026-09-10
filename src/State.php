<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

/**
 * An audit log of what this plugin has written, and the one thing it is needed
 * for functionally: recognising a file as ours.
 *
 * It is deliberately not the source of truth. Every verdict is reached by
 * asking git about the working tree, so a missing, stale or hand-edited state
 * file changes nothing about what gets applied — that is what makes a fresh
 * clone and a wiped vendor directory both behave correctly. Losing it costs
 * the audit trail and the ability to supersede, nothing else.
 */
class State
{
    private const VERSION = 1;

    /** @var string */
    private $file;

    /** @var array<string, mixed> */
    private $data;

    /** @var bool */
    private $dirty = false;

    public function __construct(string $projectRoot)
    {
        // Always the same path. Choosing it based on whether var/ happens to
        // exist yet meant a fresh CI clone wrote the record into the project
        // root, where a deploy step's `git add -A` would commit it — and the
        // next run, once var/ existed, read the other path and found nothing.
        $this->file = $projectRoot . '/var/composer-patches/state.json';

        $this->data = $this->read();
    }

    /**
     * Was this exact content written here by a patch from the same owner?
     *
     * The question behind SUPERSEDE: Adobe regenerates vendor/bin/patch-status
     * with every monthly release, so month two lands a new-file hunk on month
     * one's file. Recognising our own previous write makes that a replacement;
     * an unrecognised hash stays a conflict, so nothing hand-written or
     * third-party is ever clobbered.
     */
    public function wasWrittenBy(string $owner, string $target, string $hash): bool
    {
        foreach ($this->data['patches'] as $record) {
            if (($record['owner'] ?? null) !== $owner) {
                continue;
            }

            if (($record['targets'][$target]['sha256'] ?? null) === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $declaration
     * @param Fragment[] $fragments
     */
    public function record(array $declaration, array $fragments, TargetResolver $resolver): void
    {
        $targets = [];

        foreach ($fragments as $fragment) {
            foreach ($fragment->written() as $path) {
                $hash = $resolver->hash($path);
                $targets[$path] = array_filter([
                    'state' => $fragment->verdict(),
                    'sha256' => $hash,
                ], static function ($value) {
                    return $value !== null;
                });
            }
        }

        if ($targets === []) {
            return;
        }

        // Keyed by owner as well as label: two trusted packages can ship the
        // same patch id for the same base version, and one must not overwrite
        // or delete the other's record.
        $label = $declaration['owner'] . ' ' . $declaration['label'];
        $existing = $this->data['patches'][$label]['targets'] ?? [];

        $this->data['patches'][$label] = [
            'id' => $declaration['id'],
            'owner' => $declaration['owner'],
            'source' => $declaration['sourceRelative'],
            'sha256' => hash_file('sha256', $declaration['source']) ?: null,
            'applied_at' => date('c'),
            'targets' => array_merge($existing, $targets),
        ];

        $this->dirty = true;
    }

    /**
     * When this plugin last wrote something, for the status report. Not used
     * for any decision — every verdict still comes from asking git.
     */
    public function writtenAt(): ?string
    {
        $latest = null;

        foreach ($this->data['patches'] as $record) {
            $at = $record['applied_at'] ?? null;

            if (is_string($at) && ($latest === null || $at > $latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    public function forget(string $label): void
    {
        if (isset($this->data['patches'][$label])) {
            unset($this->data['patches'][$label]);
            $this->dirty = true;
        }
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        // Same reason as the report: one invalid byte in a target path
        // otherwise loses the whole audit trail, silently.
        $json = json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json !== false) {
            @file_put_contents($this->file, $json . "\n");
        }

        $this->dirty = false;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $empty = ['version' => self::VERSION, 'patches' => []];

        if (!is_file($this->file)) {
            return $empty;
        }

        $decoded = json_decode((string) @file_get_contents($this->file), true);

        if (!is_array($decoded) || !isset($decoded['patches']) || !is_array($decoded['patches'])) {
            return $empty;
        }

        $decoded['version'] = self::VERSION;

        return $decoded;
    }
}
