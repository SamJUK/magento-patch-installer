<?php

declare(strict_types=1);

/**
 * Unit tests for the pieces that are pure enough to test without a Magento
 * install: splitting, path resolution, lifecycle dates, exit codes.
 *
 * Deliberately no PHPUnit. This plugin has to run on every PHP from 7.4 to 8.5
 * and no single PHPUnit major spans that, so a test suite built on one would
 * only ever run on part of the range — which is the part least likely to break.
 * With no dependency at all these run on all seven, in the job that already
 * checks the syntax, in under a second.
 *
 *   php tests/unit.php            # everything
 *   php tests/unit.php splitter   # one suite
 */

namespace SamJUK\MagentoPatchInstaller\Tests;

use SamJUK\MagentoPatchInstaller\Fragment;

spl_autoload_register(static function (string $class): void {
    $prefix = 'SamJUK\\MagentoPatchInstaller\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

final class Assert
{
    /** @var int */
    public static $passed = 0;

    /** @var string[] */
    public static $failures = [];

    /** @var string */
    private static $suite = '';

    /** @var string */
    private static $case = '';

    public static function suite(string $name): void
    {
        self::$suite = $name;
    }

    public static function case(string $name): void
    {
        self::$case = $name;
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    public static function same($expected, $actual, string $what): void
    {
        if ($expected === $actual) {
            self::$passed++;

            return;
        }

        self::$failures[] = sprintf(
            "%s / %s: %s\n     expected: %s\n       actual: %s",
            self::$suite,
            self::$case,
            $what,
            self::render($expected),
            self::render($actual)
        );
    }

    public static function true(bool $actual, string $what): void
    {
        self::same(true, $actual, $what);
    }

    public static function false(bool $actual, string $what): void
    {
        self::same(false, $actual, $what);
    }

    public static function contains(string $haystack, string $needle, string $what): void
    {
        if (strpos($haystack, $needle) !== false) {
            self::$passed++;

            return;
        }

        self::$failures[] = sprintf(
            "%s / %s: %s\n     expected to contain: %s\n                     in: %s",
            self::$suite,
            self::$case,
            $what,
            $needle,
            $haystack
        );
    }

    /**
     * @param mixed $value
     */
    private static function render($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '<array>';
        }

        return var_export($value, true);
    }
}

/**
 * A throwaway directory tree, removed however the run ends.
 */
final class Sandbox
{
    /** @var string */
    private $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/mpi-unit-' . bin2hex(random_bytes(6));

        if (!mkdir($this->root, 0700, true)) {
            throw new \RuntimeException('cannot create ' . $this->root);
        }

        // The temp directory is itself behind a symlink on macOS. Patcher
        // canonicalises the project root before handing it to TargetResolver,
        // so the tests have to start from the same place or every realpath
        // comparison in there fails for a reason production never sees.
        $this->root = realpath($this->root) ?: $this->root;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dir);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    public function mkdir(string $relative): string
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('cannot create ' . $path);
        }

        return $path;
    }

    public function remove(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            // Anything that is not a directory gets unlinked, including the
            // FIFOs and sockets a test may have made. isFile() is false for
            // those, and rmdir() on one leaves the whole tree behind.
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->root);
    }
}

/**
 * A Fragment already resolved to a verdict, for the renderer's benefit.
 */
function fragment(string $target, string $verdict): Fragment
{
    $fragment = new Fragment($target, ['--- a/' . $target, '+++ b/' . $target], false);
    $fragment->resolve($verdict);

    return $fragment;
}

/**
 * A result shaped the way Collector actually builds one: the label always
 * starts with the id, and carries the base version in brackets when there is
 * one, which is what the renderer's column splitting relies on.
 *
 * @param Fragment[] $fragments
 *
 * @return array<string, mixed>
 */
function result(
    bool $applicable,
    array $fragments,
    string $id = '2026-09-001',
    string $name = '',
    ?string $line = '2.4.8-p5'
): array {
    $label = $id . ($name === '' ? '' : ' ' . $name) . ($line === null ? '' : ' [' . $line . ']');

    return [
        'id' => $id,
        'label' => $label,
        'owner' => 'samjuk/fixture',
        'sourceRelative' => 'patches/' . $id . '.patch',
        'line' => $line,
        'applicable' => $applicable,
        'blocked' => false,
        'reason' => null,
        'fragments' => $fragments,
    ];
}

// Composer itself is a dev dependency, and the syntax job deliberately does not
// install it — that is what lets these run on all seven PHP versions. The
// Collector suite needs a real Composer object, so it joins in only where one
// is available, and says so when it does not.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
$haveComposer = is_file($autoload);

if ($haveComposer) {
    require $autoload;

    // Composer 2.2 declares getInstallPath() returning string, 2.3+ ?string.
    // The double the suite needs can only match one of them, so it is loaded
    // against the newer shape only. The plugin supports both; this is a test
    // harness limitation, not a compatibility one.
    $returns = (new \ReflectionMethod(\Composer\Installer\InstallationManager::class, 'getInstallPath'))
        ->getReturnType();

    $haveComposer = $returns !== null && $returns->allowsNull();

    if ($haveComposer) {
        require __DIR__ . '/unit/collector.php';
    }
}

$suites = require __DIR__ . '/unit/suites.php';

if ($haveComposer) {
    $suites = array_merge($suites, require __DIR__ . '/unit/collector-suite.php');
}

$only = $argv[1] ?? null;
$sandbox = new Sandbox();

try {
    foreach ($suites as $name => $suite) {
        if ($only !== null && $only !== $name) {
            continue;
        }

        Assert::suite($name);
        $suite($sandbox);
    }
} finally {
    $sandbox->remove();
}

if ($only !== null && !isset($suites[$only])) {
    fwrite(STDERR, "no such suite: {$only} (have: " . implode(', ', array_keys($suites)) . ")\n");
    exit(2);
}

if (Assert::$failures === []) {
    printf(
        "unit: %d assertions, 0 failures (php %s)%s\n",
        Assert::$passed,
        PHP_VERSION,
        $haveComposer ? '' : ' — collector suite skipped, needs composer/composer ^2.3'
    );
    exit(0);
}

fwrite(STDERR, "\n" . implode("\n\n", Assert::$failures) . "\n\n");
fwrite(STDERR, sprintf("unit: %d assertions, %d failures (php %s)\n", Assert::$passed, count(Assert::$failures), PHP_VERSION));
exit(1);
