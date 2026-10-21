<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Release\SelfUpdater;
use Jul6Art\DevTools\Release\UpdateRefused;
use Jul6Art\DevTools\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `devtools self-update`: the phar replaced by the latest release, verified first (ADR-0034). Installed with
 * Composer, DevTools is updated by Composer.
 */
#[AsCommand(name: 'self-update', description: 'Replace this phar by the latest release, once its checksum and provenance are verified')]
final class SelfUpdateCommand extends Command
{
    /**
     * @param string|null $phar the phar to replace; null for the one running
     */
    public function __construct(private readonly SelfUpdater $updater = new SelfUpdater(), private readonly ?string $phar = null, private readonly ?string $current = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $phar = $this->phar ?? \Phar::running(false);

        if ('' === $phar) {
            $io->error('DevTools was installed with Composer: update it with composer update jul6art/devtools.');

            return Command::FAILURE;
        }

        try {
            [$latest, $replaced] = $this->updater->update($phar, $this->current ?? Version::current());
        } catch (UpdateRefused $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }

        if (null === $replaced) {
            $io->success(\sprintf('DevTools %s is the latest release.', $latest));
        } else {
            $io->success(\sprintf('DevTools %s replaced %s: its SHA-256 and its provenance were verified.', $latest, $replaced));
        }

        return Command::SUCCESS;
    }
}
