<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use SamJUK\MagentoPatchInstaller\Command\PatchCommand;

class CommandProvider implements CommandProviderCapability
{
    /**
     * @return \Composer\Command\BaseCommand[]
     */
    public function getCommands(): array
    {
        return [
            new PatchCommand(
                'magento-patches:list',
                'List every patch the trusted packages declare, and which of them are for this install',
                PatchCommand::MODE_LIST
            ),
            new PatchCommand(
                'magento-patches:status',
                'Show this store, and whether every patch that applies to it is in place',
                PatchCommand::MODE_STATUS
            ),
            new PatchCommand(
                'magento-patches:apply',
                'Apply every patch that is missing from this install',
                PatchCommand::MODE_APPLY
            ),
            new PatchCommand(
                'magento-patches:verify',
                'Check that every applicable patch is applied, and exit non-zero when one is not',
                PatchCommand::MODE_VERIFY
            ),
        ];
    }
}
