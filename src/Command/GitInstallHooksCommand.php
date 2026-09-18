<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\GitHooks;
use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `git:install-hooks` (ADR-0017): the check on `pre-commit`, the facts refreshed on `post-merge` and
 * `post-checkout`.
 */
#[AsCommand(name: 'git:install-hooks', description: 'Install the DevTools git hooks of this project')]
final class GitInstallHooksCommand extends Command
{
    public function __construct(
        private readonly GitHooks $hooks = new GitHooks(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Refuse the commit when the check fails, instead of warning');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $written = $this->hooks->install(new ProjectRoot($path), true === $input->getOption('strict'));

        if ([] === $written) {
            $io->error('This project is not a git repository: there is no hook to install.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Hooks installed: %s.', implode(', ', $written)));

        return Command::SUCCESS;
    }
}
