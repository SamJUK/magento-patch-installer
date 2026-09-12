<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use SamJUK\MagentoPatchInstaller\Patcher;
use SamJUK\MagentoPatchInstaller\Renderer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One implementation behind patch:list, patch:apply and patch:verify. The three
 * differ only in whether they write and how they exit.
 */
class PatchCommand extends BaseCommand
{
    public const MODE_LIST = 'list';
    public const MODE_STATUS = 'status';
    public const MODE_APPLY = 'apply';
    public const MODE_VERIFY = 'verify';

    /** @var string */
    private $mode;

    /** @var string */
    private $summary;

    public function __construct(string $name, string $description, string $mode)
    {
        $this->mode = $mode;
        $this->summary = $description;

        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription($this->summary);
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a report');

        if ($this->mode === self::MODE_APPLY) {
            // The config key is right for trialling this across a whole store.
            // It is the wrong shape for "show me what this one run would do".
            $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing');
        }

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->composer();
        $patcher = Patcher::fromComposer($composer, $this->getIO());

        // The catalogue never touches the working tree, so it is the one command
        // that answers before `composer install` has ever run.
        if ($this->mode === self::MODE_LIST) {
            return $this->catalogue($patcher, $input, $output);
        }

        // A trial has dry-run set precisely so nothing is written; running
        // magento-patches:apply by hand on that store must not quietly ignore it.
        // The option only exists on apply. Reading it anywhere else throws
        // "The --dry-run option does not exist" before a single line is
        // printed, which is every command failing at once for one reason.
        $dryRun = $patcher->isDryRun()
            || ($this->mode === self::MODE_APPLY && (bool) $input->getOption('dry-run'));
        $write = $this->mode === self::MODE_APPLY && !$dryRun;
        $results = $patcher->run($write);

        // Configuration problems are discovered while collecting declarations,
        // which happens inside run() — reading them before it always found none.
        $errors = $patcher->errors();

        // Errors go to stderr, always. On stdout they land in the middle of the
        // JSON body and nothing downstream can parse it — which is how this hid
        // for so long: the acceptance harness stripped everything before the
        // first `{` and never saw the problem.
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        foreach ($errors as $error) {
            $errorOutput->writeln('<error>Patch configuration: ' . Renderer::safe($error) . '</error>');
        }

        if ($input->getOption('json')) {
            $output->writeln(Renderer::json($results, $errors));

            return $this->resultCode($errors, $results);
        }

        $advisories = $patcher->advisories();

        // The store first, then the count, then what is wrong with it. A
        // headline about a store nobody has named yet reads backwards.
        if ($this->mode === self::MODE_STATUS) {
            $output->writeln('');

            foreach (Renderer::environment($patcher->environment()) as $line) {
                $output->writeln($line);
            }

            $output->writeln('');
        }

        $output->writeln(sprintf(
            '%s%s',
            Renderer::summary($results, $errors),
            $this->mode === self::MODE_APPLY && !$write ? ' (dry run — nothing written)' : ''
        ));

        foreach ($advisories as $advisory) {
            $output->writeln(Renderer::advisoryLines($advisory));
        }

        $output->writeln('');

        if ($results !== [] || $errors === []) {
            $failuresOnly = $this->mode === self::MODE_VERIFY && !$output->isVerbose();

            foreach (Renderer::lines($results, $output->isVerbose(), $failuresOnly) as $line) {
                $output->writeln($line);
            }
        }

        $output->writeln('');
        $output->writeln(Renderer::banner($results, $errors, $advisories));

        return $this->resultCode($errors, $results);
    }

    /**
     * @param string[] $errors
     * @param array<int, array<string, mixed>> $results
     */
    private function resultCode(array $errors, array $results): int
    {
        // A configuration error outranks every other verdict, in both output
        // modes. It means declarations were dropped before anything was
        // classified, so the results describe less than the project asked for
        // — and a clean exit there is indistinguishable from a patched store.
        if ($errors !== []) {
            return Renderer::CONFIG_ERROR;
        }

        // Reporting does not judge; asserting does.
        return $this->mode === self::MODE_STATUS ? Renderer::OK : Renderer::exitCode($results);
    }

    /**
     * `magento-patches:list` — the catalogue, with nothing read from the working tree.
     */
    private function catalogue(Patcher $patcher, InputInterface $input, OutputInterface $output): int
    {
        $entries = $patcher->catalogue();
        $errors = $patcher->errors();

        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        foreach ($errors as $error) {
            $errorOutput->writeln('<error>Patch configuration: ' . Renderer::safe($error) . '</error>');
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                ['exit_code' => $errors === [] ? Renderer::OK : Renderer::CONFIG_ERROR,
                 'errors' => $errors,
                 'patches' => $entries],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return $errors === [] ? Renderer::OK : Renderer::CONFIG_ERROR;
        }

        foreach (Renderer::catalogue($entries, $output->isVerbose()) as $line) {
            $output->writeln($line);
        }

        return $errors === [] ? Renderer::OK : Renderer::CONFIG_ERROR;
    }

    /**
     * getComposer() spans the whole supported range: it is the only accessor in
     * Composer 2.2, and in 2.10 it is a thin wrapper over requireComposer()
     * that raises no runtime deprecation. requireComposer() would need a
     * version shim for 2.2, and a shim is unanalysable by definition — one
     * branch is always dead for whichever version is being looked at.
     *
     * Revisit if Composer 3 removes it.
     */
    private function composer(): Composer
    {
        $composer = $this->getComposer();

        if ($composer === null) {
            // Only reachable if the command is run outside a project. Composer
            // itself would have failed first, but a null here would surface as
            // an unrelated error much further down.
            throw new \RuntimeException('No composer.json found in the current directory');
        }

        return $composer;
    }
}
