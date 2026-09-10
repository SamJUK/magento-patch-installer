<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

/**
 * Says how close the installed Magento is to the end of its supported life.
 *
 * Dates come from a manifest shipped in this package, never from a network call
 * at patch time: a security tool that phones home on every `composer install`
 * is a liability, and a store behind a firewall would lose the warning without
 * noticing. `bin/refresh-eol` rebuilds the manifest from magento.watch.
 *
 * This never fails a run. An unsupported store still needs its patches applied
 * — arguably more than a supported one does.
 */
class Lifecycle
{
    public const OK = 'ok';
    public const APPROACHING = 'approaching';
    public const ENDED = 'ended';

    /** How long before end of life the warning starts. */
    private const NOTICE_DAYS = 182;

    /**
     * Adobe Commerce installs both product packages, so the Enterprise one is
     * checked first — otherwise a Commerce store reports as Open Source.
     */
    private const PRODUCT_PACKAGES = [
        'magento/product-enterprise-edition',
        'magento/product-community-edition',
        'mage-os/product-community-edition',
    ];

    /** @var array<string, array<string, string>> */
    private $dates;

    /** @var string|null */
    private $generated;

    public function __construct(?string $manifestPath = null)
    {
        $path = $manifestPath ?? dirname(__DIR__) . '/resources/magento-eol.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        $this->dates = is_array($decoded) && isset($decoded['distributions']) && is_array($decoded['distributions'])
            ? $decoded['distributions']
            : [];

        $this->generated = is_array($decoded) && isset($decoded['generated'])
            ? (string) $decoded['generated']
            : null;
    }

    /**
     * @param array<string, string> $installed package name => version
     *
     * @return array{status: string, package: string, version: string, date: string, days: int}|null
     */
    public function check(array $installed, ?\DateTimeImmutable $now = null): ?array
    {
        $now = $now ?? new \DateTimeImmutable('today');

        foreach (self::PRODUCT_PACKAGES as $package) {
            if (!isset($installed[$package], $this->dates[$package])) {
                continue;
            }

            $version = $installed[$package];

            $date = $this->dates[$package][$version] ?? null;

            // An unknown version means the manifest predates this release, not
            // that the release is fine. Stay quiet rather than guess.
            if ($date === null) {
                return null;
            }

            $eol = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

            if ($eol === false) {
                return null;
            }

            $days = (int) $now->diff($eol->setTime(0, 0))->format('%r%a');

            if ($days < 0) {
                $status = self::ENDED;
            } elseif ($days <= self::NOTICE_DAYS) {
                $status = self::APPROACHING;
            } else {
                $status = self::OK;
            }

            return [
                'status' => $status,
                'package' => $package,
                'version' => $version,
                'date' => $date,
                'days' => $days,
            ];
        }

        return null;
    }

    /**
     * @param array<string, string> $installed
     *
     * @return array{level: string, message: string}|null
     */
    public function advisory(array $installed, ?\DateTimeImmutable $now = null): ?array
    {
        $result = $this->check($installed, $now);

        if ($result === null || $result['status'] === self::OK) {
            return null;
        }

        if ($result['status'] === self::ENDED) {
            return [
                'level' => 'critical',
                'message' => sprintf(
                    '%s %s reached end of life on %s (%d days ago). It receives no further security '
                    . 'releases, so patches here can only cover what Adobe has already published.',
                    $result['package'],
                    $result['version'],
                    $result['date'],
                    abs($result['days'])
                ),
            ];
        }

        return [
            'level' => 'warning',
            'message' => sprintf(
                '%s %s reaches end of life on %s, in %d days. Plan the upgrade before it stops '
                . 'receiving security releases.',
                $result['package'],
                $result['version'],
                $result['date'],
                $result['days']
            ),
        ];
    }

    public function generated(): ?string
    {
        return $this->generated;
    }

    /**
     * Which Magento this is, whether or not the manifest has heard of the
     * version. `check()` answers a narrower question and returns null for a
     * release it cannot date, which is right for an advisory and wrong for a
     * report that has to name the store it is describing.
     *
     * @param array<string, string> $installed
     *
     * @return array{package: string, version: string}|null
     */
    public static function product(array $installed): ?array
    {
        foreach (self::PRODUCT_PACKAGES as $package) {
            if (isset($installed[$package])) {
                return ['package' => $package, 'version' => $installed[$package]];
            }
        }

        return null;
    }
}
