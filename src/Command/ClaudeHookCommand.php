<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Claude\Hook\HookHandlerInterface;
use Jul6Art\DevTools\Claude\Hook\HookInput;
use Jul6Art\DevTools\Claude\Hook\PreToolUseHandler;
use Jul6Art\DevTools\Claude\Hook\SessionStartHandler;
use Jul6Art\DevTools\Claude\Hook\StopHandler;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The one entry point of every Claude Code hook DevTools answers (ADR-0019): the JSON of the event on the
 * standard input, the answer on the standard output.
 *
 * ⚠️ **A DevTools hook never keeps Claude from working because of itself.** Whatever goes wrong — no index,
 * a broken XML, an input it does not understand — the command exits 0 and says nothing on the standard
 * output; the cause goes to the standard error, which Claude never sees.
 */
#[AsCommand(name: 'claude:hook', description: 'Answer a Claude Code hook (PreToolUse, SessionStart) from the documentation')]
final class ClaudeHookCommand extends Command
{
    /**
     * @var array<string, HookHandlerInterface>
     */
    private readonly array $handlers;

    /**
     * @param list<HookHandlerInterface>|null $handlers
     */
    public function __construct(
        private readonly string $stdin = 'php://stdin',
        private readonly ?string $defaultPath = null,
        ?array $handlers = null,
    ) {
        $byEvent = [];

        foreach ($handlers ?? [new PreToolUseHandler(), new SessionStartHandler(), new StopHandler()] as $handler) {
            $byEvent[$handler->event()] = $handler;
        }

        $this->handlers = $byEvent;

        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('event', InputArgument::REQUIRED, 'The Claude Code event: '.implode(', ', array_keys($this->handlers)))
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: CLAUDE_PROJECT_DIR, then the cwd Claude Code sends)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $event = $input->getArgument('event');
            $handler = $this->handlers[\is_string($event) ? $event : ''] ?? throw new \InvalidArgumentException(\sprintf('DevTools answers no "%s" hook.', \is_string($event) ? $event : ''));
            $hook = HookInput::parse((string) file_get_contents($this->stdin));
            $path = $input->getOption('path');
            $project = getenv('CLAUDE_PROJECT_DIR');
            $root = match (true) {
                \is_string($path) => $path,
                null !== $this->defaultPath => $this->defaultPath,
                \is_string($project) && '' !== $project => $project,
                default => $hook->cwd ?? (string) getcwd(),
            };

            $answer = $handler->handle($hook, DevToolsDirectory::documented(new ProjectRoot($root)));
        } catch (\Throwable $failure) {
            $errors->writeln('DevTools hook: '.$failure->getMessage());

            return Command::SUCCESS;
        }

        if (null !== $answer) {
            $output->writeln(json_encode($answer, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        }

        return Command::SUCCESS;
    }
}
