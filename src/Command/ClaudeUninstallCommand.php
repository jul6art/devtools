<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Claude\ClaudeSettings;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes back what `claude:install` added to the Claude Code configuration of a project, and nothing else
 * (ADR-0019, ADR-0018, ADR-0024).
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
            ->addOption('hooks', null, InputOption::VALUE_NONE, 'The DevTools hooks of .claude/settings.json')
            ->addOption('mcp', null, InputOption::VALUE_NONE, 'The DevTools MCP server of .mcp.json')
            ->addOption('agents', null, InputOption::VALUE_NONE, 'The devtools-verifier subagent of .claude/agents/, unless the project modified it');
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

        $hooks = true === $input->getOption('hooks');
        $mcp = true === $input->getOption('mcp');
        $agents = true === $input->getOption('agents');

        if (!$hooks && !$mcp && !$agents) {
            $io->error('Say what to remove: --hooks, --mcp, --agents.');

            return Command::INVALID;
        }

        $root = new ProjectRoot($path);

        try {
            if ($hooks) {
                $io->success($this->settings->uninstallHooks($root->absolute('.claude/settings.json')) ? 'Removed the DevTools hooks from .claude/settings.json.' : 'No DevTools hook in .claude/settings.json.');
            }

            if ($mcp) {
                $io->success($this->settings->uninstallMcpServer($root->absolute('.mcp.json')) ? 'Removed the DevTools MCP server from .mcp.json.' : 'No DevTools MCP server in .mcp.json.');
            }

            if ($agents) {
                $this->uninstallVerifier($io, $root);
            }
        } catch (\InvalidArgumentException $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * The verifier DevTools installed goes; one the team adapted stays — it is their work now.
     */
    private function uninstallVerifier(SymfonyStyle $io, ProjectRoot $root): void
    {
        $target = $root->absolute('.'.ClaudeInstallCommand::VERIFIER);

        if (!is_file($target)) {
            $io->success('No DevTools verifier in .claude/agents/.');
        } elseif (file_get_contents($target) !== file_get_contents(Resources::path(ClaudeInstallCommand::VERIFIER))) {
            $io->warning('.claude/agents/devtools-verifier.md was modified in this project: it stays. Remove it yourself if you mean to.');
        } else {
            unlink($target);
            $io->success('Removed .claude/agents/devtools-verifier.md.');
        }
    }
}
