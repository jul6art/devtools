<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Claude\ClaudeSettings;
use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes back what `claude:install` added to the Claude Code configuration of a project, and nothing else
 * (ADR-0019).
 */
#[AsCommand(name: 'claude:uninstall', description: 'Remove what claude:install added to the Claude Code configuration')]
final class ClaudeUninstallCommand extends Command
{
    public function __construct(
        private readonly ClaudeSettings $settings = new ClaudeSettings(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('hooks', null, InputOption::VALUE_NONE, 'The DevTools hooks of .claude/settings.json');
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

        if (true !== $input->getOption('hooks')) {
            $io->error('Say what to remove: --hooks.');

            return Command::INVALID;
        }

        try {
            $changed = $this->settings->uninstallHooks(new ProjectRoot($path)->absolute('.claude/settings.json'));
        } catch (\InvalidArgumentException $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        }

        $io->success($changed ? 'Removed the DevTools hooks from .claude/settings.json.' : 'No DevTools hook in .claude/settings.json.');

        return Command::SUCCESS;
    }
}
