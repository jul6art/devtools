<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\GitHooks;
use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `git:uninstall-hooks` (ADR-0017): removes exactly what `git:install-hooks` added, and nothing else.
 */
#[AsCommand(name: 'git:uninstall-hooks', description: 'Remove the DevTools git hooks of this project')]
final class GitUninstallHooksCommand extends Command
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
        $this->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)');
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

        $cleaned = $this->hooks->uninstall(new ProjectRoot($path));

        $io->success([] === $cleaned ? 'No DevTools hook to remove.' : \sprintf('Hooks cleaned: %s.', implode(', ', $cleaned)));

        return Command::SUCCESS;
    }
}
