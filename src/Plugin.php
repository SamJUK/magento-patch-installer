<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    /**
     * Deeply negative on purpose.
     *
     * magento/magento-composer-installer deploys ~120 files from magento2-base
     * to the project root at priority 1, and listeners registered at the same
     * priority have no guaranteed order between them — measured running both
     * before and after that deploy across two runs of the same project. Root
     * composer.json scripts dispatch after priority 0. Sitting below all of it
     * means every package is installed, every root file is deployed, and every
     * project script has finished before a single patch is considered.
     */
    private const PRIORITY = -1000;

    /** @var Composer */
    private $composer;

    /** @var IOInterface */
    private $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => [['onPostRun', self::PRIORITY]],
            ScriptEvents::POST_UPDATE_CMD => [['onPostRun', self::PRIORITY]],
            // `composer dump-autoload` fires this and never fires the two
            // above. vaimo/composer-patches hooks PRE_AUTOLOAD_DUMP, so on a
            // bare dump-autoload — routine in Magento build and deploy scripts
            // — it runs its full reset-and-repatch cycle while we never
            // execute at all. Measured: a green store, one
            // `composer dump-autoload -o` with vaimo configured, and
            // magento-patches:verify goes from 0 to 1 with no opportunity to heal.
            ScriptEvents::POST_AUTOLOAD_DUMP => [['onAutoloadDump', self::PRIORITY]],
        ];
    }

    /**
     * Only when dump-autoload is the command being run.
     *
     * This event also fires partway through install, update and require, well
     * before magento-composer-installer has deployed the root files — so acting
     * on it there would both duplicate the report and inspect the mirror at the
     * wrong moment. Those runs are already covered, later and correctly, by
     * POST_INSTALL_CMD and POST_UPDATE_CMD.
     */
    public function onAutoloadDump(): void
    {
        if (!self::isDumpAutoloadCommand()) {
            return;
        }

        $this->onPostRun();
    }

    /**
     * Composer resolves abbreviations, so the command can arrive as
     * dump-autoload, dumpautoload, dump-auto or du. When the command cannot be
     * determined at all, say yes: running twice is noisy, and not running is
     * how a store ends up unpatched.
     */
    private static function isDumpAutoloadCommand(): bool
    {
        $argv = $_SERVER['argv'] ?? null;

        if (!is_array($argv)) {
            return true;
        }

        foreach (array_slice($argv, 1) as $argument) {
            if (!is_string($argument) || $argument === '' || $argument[0] === '-') {
                continue;
            }

            return strpos($argument, 'du') === 0;
        }

        return true;
    }

    public function onPostRun(): void
    {
        $patcher = Patcher::fromComposer($this->composer, $this->io);
        $dryRun = $patcher->isDryRun();
        $results = $patcher->run(!$dryRun);

        foreach ($patcher->errors() as $error) {
            $this->io->writeError('<error>Patch configuration: ' . Renderer::safe($error) . '</error>');
        }

        if ($results === [] && $patcher->errors() === []) {
            return;
        }

        $this->io->write(sprintf(
            '<info>Magento security patches</info>%s: %s',
            $dryRun ? ' <comment>(dry run — nothing written)</comment>' : '',
            Renderer::summary($results, $patcher->errors())
        ));

        $advisories = $patcher->advisories();

        foreach ($advisories as $advisory) {
            foreach (Renderer::advisoryLines($advisory, '  ') as $line) {
                $this->io->write($line);
            }
        }

        if ($results !== []) {
            foreach (Renderer::lines($results, false) as $line) {
                $this->io->write($line);
            }
        }

        // Last, so it is the line still on screen when a thousand lines of
        // composer output stop scrolling.
        $this->io->write('');
        $this->io->write(Renderer::banner($results, $patcher->errors(), $advisories));
        $this->io->write('');

        // A configuration error is a failure in its own right, not just when it
        // happens to leave a patch unapplied. Dropping every declaration — a
        // missing trust entry, a renamed patch file — leaves no results at all,
        // and an exit code derived only from those results says OK. That is a
        // green pipeline on a store with nothing applied: the exact outcome
        // this plugin exists to prevent.
        $failed = Renderer::exitCode($results) !== Renderer::OK || $patcher->errors() !== [];

        if (!$failed || $dryRun || $patcher->allowsUnpatched()) {
            return;
        }

        // Loud inside 500 lines of composer output is not loud. Failing the run
        // is the only signal a deploy pipeline reliably notices.
        throw new \RuntimeException(
            'Security patches could not be applied. Run "composer magento-patches:list -v" for detail, '
            . 'or set extra.magento-patches.allow-unpatched to true to continue anyway.'
        );
    }
}
