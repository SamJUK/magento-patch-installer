<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use SamJUK\MagentoPatchInstaller\Collector;
use SamJUK\MagentoPatchInstaller\Patcher;

/**
 * Collector is the only class that needs a real Composer object, which is why
 * it went untested for so long. It is also the one holding the trust gate and
 * the selection rules, so "hard to set up" was the wrong reason to skip it.
 *
 * Composer 2.2 declares getInstallPath() returning string and 2.3+ returning
 * ?string, so this file is only loaded where the nullable form is present —
 * see the guard in tests/unit.php. The plugin itself supports both.
 */
final class Project
{
    /** @var string */
    public $root;

    /** @var array<string, mixed> */
    private $rootExtra = [];

    /** @var array<string, array<string, mixed>> */
    private $packages = [];

    public function __construct(Sandbox $sandbox, string $name)
    {
        $this->root = $sandbox->mkdir('projects/' . $name);
        $sandbox->mkdir('projects/' . $name . '/vendor');
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function root(array $extra): self
    {
        $this->rootExtra = $extra;

        return $this;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function package(string $name, array $extra): self
    {
        $dir = $this->root . '/vendor/' . $name;

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dir);
        }

        $this->packages[$name] = $extra;

        return $this;
    }

    public function write(string $relative, string $contents): self
    {
        $path = $this->root . '/' . $relative;
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dir);
        }

        file_put_contents($path, $contents);

        return $this;
    }

    public function collector(): Collector
    {
        return new Collector($this->composer());
    }

    public function patcher(): Patcher
    {
        return Patcher::fromComposer($this->composer(), new \Composer\IO\NullIO());
    }

    private function composer(): Composer
    {
        $root = new RootPackage('project/root', '1.0.0.0', '1.0.0');
        $root->setExtra($this->rootExtra);

        $config = new Config(false, $this->root);
        $config->merge(['config' => ['vendor-dir' => $this->root . '/vendor']]);

        $repository = new InstalledArrayRepository();
        $installed = [];

        foreach ($this->packages as $name => $extra) {
            $package = new Package($name, '1.0.0.0', '1.0.0');
            $package->setExtra($extra);
            $repository->addPackage($package);
            $installed[$name] = $this->root . '/vendor/' . $name;
        }

        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage($root);

        $manager = RepositoryManager::class;
        $repositoryManager = new $manager(
            new \Composer\IO\NullIO(),
            $config,
            new \Composer\Util\HttpDownloader(new \Composer\IO\NullIO(), $config)
        );
        $repositoryManager->setLocalRepository($repository);
        $composer->setRepositoryManager($repositoryManager);

        $composer->setInstallationManager(new FixedInstallationManager($installed));

        return $composer;
    }
}

final class FixedInstallationManager extends InstallationManager
{
    /** @var array<string, string> */
    private $paths;

    /**
     * @param array<string, string> $paths
     */
    public function __construct(array $paths)
    {
        $this->paths = $paths;
    }

    public function getInstallPath(\Composer\Package\PackageInterface $package): ?string
    {
        return $this->paths[$package->getName()] ?? null;
    }
}
