<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Ai\Api\AiRunner;
use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `devtools ai:run`: the pending briefs written through the Claude API, then applied (ADR-0035) — off unless the
 * project's `config.xml` turns it on, since it spends tokens.
 */
#[AsCommand(name: 'ai:run', description: 'Write the pending briefs through the Claude API, then apply them — off unless <ai enabled="true"/>: it spends tokens')]
final class AiRunCommand extends Command
{
    public function __construct(private readonly AiRunner $runner = new AiRunner(), private readonly Clock $clock = new SystemClock())
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('tasks', null, InputOption::VALUE_REQUIRED, 'The kinds of brief to write: '.implode(',', AiRunner::TASKS), implode(',', AiRunner::TASKS))
            ->addOption('max-tasks', null, InputOption::VALUE_REQUIRED, 'At most this many briefs sent this run; the others are kept', '20');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $directory = DevToolsDirectory::documented(new ProjectRoot(\is_string($path) ? $path : (string) getcwd()));
        $config = new XmlConfigReader()->read($directory->configFile());

        if (!$config->aiEnabled) {
            $io->error('ai:run is off: it sends briefs to the Claude API and spends tokens. To let this project use it, set <ai enabled="true"/> in .devtools/config.xml — with a budget-tokens that suits you.');

            return Command::FAILURE;
        }

        if (!$this->runner->hasKey()) {
            $io->error('ANTHROPIC_API_KEY is not set: ai:run reads the key from that variable only — never from config.xml.');

            return Command::FAILURE;
        }

        $tasks = $input->getOption('tasks');
        $tasks = array_values(array_filter(array_map(trim(...), explode(',', \is_string($tasks) ? $tasks : ''))));
        $unknown = array_diff($tasks, AiRunner::TASKS);
        $maxTasks = $input->getOption('max-tasks');
        $maxTasks = \is_string($maxTasks) && ctype_digit($maxTasks) ? (int) $maxTasks : 0;

        if ([] !== $unknown || [] === $tasks || $maxTasks < 1) {
            $io->error(\sprintf('--tasks takes %s; --max-tasks a positive number.', implode(', ', AiRunner::TASKS)));

            return Command::INVALID;
        }

        $report = $this->runner->run($directory, $config, $tasks, $maxTasks);
        $file = $directory->path('reports/ai-run-'.$this->clock->now()->format('Y-m-d-His').'.md');
        @mkdir(\dirname($file), 0o777, true);
        file_put_contents($file, $report->toMarkdown($this->clock->now()));

        $io->writeln($report->toMarkdown($this->clock->now()));
        $io->comment('Report: '.$file);

        return [] === $report->refused && [] === $report->applied->refused ? Command::SUCCESS : Command::FAILURE;
    }
}
