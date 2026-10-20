<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Mcp\McpServer;
use Jul6Art\DevTools\Mcp\ProjectQueries;
use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The MCP server of the project, on stdin and stdout (ADR-0018): Claude Code launches it, from `.mcp.json`.
 *
 * Registered only when the MCP SDK is installed. Standard output belongs to the protocol: the command
 * itself never writes there, its errors go to standard error.
 */
#[AsCommand(name: 'mcp:serve', description: 'Serve the documentation to Claude Code over MCP, on stdin and stdout')]
final class McpServeCommand extends Command
{
    public const string NAME = 'mcp:serve';

    /**
     * Where Claude Code says the project is, in the environment of the servers it launches.
     */
    public const string PROJECT_ENV = 'CLAUDE_PROJECT_DIR';

    /**
     * @param resource|null $input  the protocol's input, standard input by default
     * @param resource|null $output the protocol's output, standard output by default
     */
    public function __construct(
        private readonly ?string $defaultPath = null,
        private readonly mixed $input = null,
        private readonly mixed $output = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: $CLAUDE_PROJECT_DIR, else the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('path');
        $environment = getenv(self::PROJECT_ENV);
        $path = match (true) {
            \is_string($path) => $path,
            null !== $this->defaultPath => $this->defaultPath,
            \is_string($environment) && '' !== $environment => $environment,
            default => (string) getcwd(),
        };

        if (!is_dir($path)) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln(\sprintf('<error>"%s" is not a directory.</error>', $path));

            return Command::INVALID;
        }

        new McpServer(new ProjectQueries(new ProjectRoot($path)))->serve(\is_resource($this->input) ? $this->input : \STDIN, \is_resource($this->output) ? $this->output : \STDOUT);

        return Command::SUCCESS;
    }
}
