<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Claude\ClaudeSettings;
use Jul6Art\DevTools\Mcp\McpServer;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Resources;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What DevTools adds to a project for Claude Code: the skills that write the pages (ADR-0011) and answer from
 * them (ADR-0039), and on demand the hooks that give Claude the impact of a change before it makes it
 * (ADR-0019), the MCP server that serves the pages one at a time (ADR-0018) and the verifier that takes a
 * second look at a change (ADR-0024).
 */
#[AsCommand(name: 'claude:install', description: 'Install the Claude Code skills that write the workflow pages and answer from them, and on demand the hooks and the MCP server')]
final class ClaudeInstallCommand extends Command
{
    /**
     * The skills: writing the pages (ADR-0011), answering from them (ADR-0039).
     */
    private const array SKILLS = ['claude/skills/devtools-inspect/SKILL.md', 'claude/skills/devtools-ask/SKILL.md'];

    /**
     * The verifier of ADR-0024: read-only, a fresh context.
     */
    public const string VERIFIER = 'claude/agents/devtools-verifier.md';

    /**
     * The hooks of ADR-0019 and the gate of ADR-0023: event => matcher.
     */
    public const array HOOKS = ['PreToolUse' => 'Edit|Write|MultiEdit', 'SessionStart' => null, 'Stop' => null];

    /**
     * Seconds Claude Code waits for each: the gate may replay scenarios in a browser.
     */
    public const array TIMEOUTS = ['PreToolUse' => 10, 'SessionStart' => 10, 'Stop' => 600];

    public function __construct(
        private readonly AtomicFileWriter $writer = new AtomicFileWriter(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite a skill that was modified in the project, or a "devtools" MCP server DevTools did not write')
            ->addOption('skill', null, InputOption::VALUE_NONE, 'The skills that write the pages and answer from them — what is installed when nothing else is asked')
            ->addOption('hooks', null, InputOption::VALUE_NONE, 'The hooks of .claude/settings.json: the impact of a file before Claude edits it')
            ->addOption('mcp', null, InputOption::VALUE_NONE, 'The MCP server of .mcp.json: the pages, the impact and the knowledge, read on demand')
            ->addOption('agents', null, InputOption::VALUE_NONE, 'The devtools-verifier subagent of .claude/agents/: a second look at a change to the interface');
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

        $root = new ProjectRoot($path);
        $hooks = true === $input->getOption('hooks');
        $mcp = true === $input->getOption('mcp');
        $agents = true === $input->getOption('agents');
        $force = true === $input->getOption('force');
        $status = Command::SUCCESS;

        if (true === $input->getOption('skill') || (!$hooks && !$mcp && !$agents)) {
            foreach (self::SKILLS as $skill) {
                $status = max($status, $this->installFile($io, $root, $skill, $force, 'the skill'));
            }
        }

        if ($agents) {
            $status = max($status, $this->installFile($io, $root, self::VERIFIER, $force, 'the verifier'));
        }

        if ($hooks) {
            try {
                new ClaudeSettings($this->writer)->installHooks($root->absolute('.claude/settings.json'), self::HOOKS, self::binary($root), self::TIMEOUTS);
            } catch (\InvalidArgumentException $refusal) {
                $io->error($refusal->getMessage());

                return Command::FAILURE;
            }

            $io->success('Installed the DevTools hooks in .claude/settings.json: '.implode(', ', array_keys(self::HOOKS)).'. Commit it: the whole team gets them.');
        }

        if ($mcp) {
            try {
                new ClaudeSettings($this->writer)->installMcpServer($root->absolute('.mcp.json'), self::mcpCommand($root), [McpServeCommand::NAME], $force);
            } catch (\InvalidArgumentException $refusal) {
                $io->error($refusal->getMessage());

                return Command::FAILURE;
            }

            $io->success(array_filter([
                'Installed the DevTools MCP server in .mcp.json. Commit it: Claude Code asks each teammate to approve it once.',
                McpServer::available() ? null : 'It needs the MCP SDK, which DevTools only suggests: composer require --dev '.McpServer::PACKAGE,
            ]));
        }

        return $status;
    }

    /**
     * How Claude Code runs DevTools in this project: the one Composer installed in it, or the one on the
     * PATH of a global installation.
     */
    public static function binary(ProjectRoot $root): string
    {
        return is_file($root->path.'/vendor/bin/devtools') ? '"${CLAUDE_PROJECT_DIR}/vendor/bin/devtools"' : 'devtools';
    }

    /**
     * The same, for `.mcp.json`: no shell runs the command, and Claude Code expands a variable of its own
     * environment only — `CLAUDE_PROJECT_DIR` is not one, hence the default.
     */
    public static function mcpCommand(ProjectRoot $root): string
    {
        return is_file($root->path.'/vendor/bin/devtools') ? '${CLAUDE_PROJECT_DIR:-.}/vendor/bin/devtools' : 'devtools';
    }

    private function installFile(SymfonyStyle $io, ProjectRoot $root, string $resource, bool $force, string $what): int
    {
        $relative = '.'.$resource;
        $target = $root->absolute($relative);
        $shipped = (string) file_get_contents(Resources::path($resource));

        // A file adapted to the project is the team's work: never overwritten without --force.
        if (is_file($target) && file_get_contents($target) !== $shipped && !$force) {
            $io->warning(\sprintf('%s was modified in this project and was left as is. Use --force to replace it.', $relative));

            return Command::FAILURE;
        }

        $this->writer->write($target, $shipped);
        $io->success(\sprintf('Installed %s. Commit it: the whole team gets %s.', $relative, $what));

        return Command::SUCCESS;
    }
}
