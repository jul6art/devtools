<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Console\ConsoleProgressReporter;
use Jul6Art\DevTools\Console\MemoryLimit;
use Jul6Art\DevTools\Console\SummaryRenderer;
use Jul6Art\DevTools\Inspection\Freshness\DecisionKind;
use Jul6Art\DevTools\Inspection\Freshness\FreshnessDecision;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Jul6Art\DevTools\Inspection\InspectionReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workflows:inspect', description: 'Document the workflows of a project in .devtools/')]
final class WorkflowsInspectCommand extends Command
{
    public function __construct(
        private readonly InspectionPipeline $pipeline = new InspectionPipeline(),
        private readonly ?string $defaultPath = null,
        private readonly ?string $defaultLanguage = null,
        private readonly ?string $defaultDocs = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project to inspect (default: the current directory)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Write the pages of one workflow type only (routes, commands, async…); the menu stays complete')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be rewritten and why; write nothing')
            ->addOption('force', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Rewrite every workflow, or the one named (repeatable): --force=route.order.new')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Compare with this commit, and hash every file')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete the page and tracking file of orphaned workflows')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Write no brief for Claude: factual pages only (CI)')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The language Claude writes the pages in (two letters); wins over .devtools/config.xml. Default: en')
            ->addOption('docs', null, InputOption::VALUE_REQUIRED, 'The directory the pages and the menu are written in, relative to the project. Default: docs/workflows');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $raised = MemoryLimit::raiseTo();

        if (null !== $raised) {
            $io->comment(\sprintf('Memory limit raised to %s for this inspection.', $raised));
        }

        $path = $input->getArgument('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $only = $input->getOption('only');

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $force = (array) $input->getOption('force');
        $since = $input->getOption('since');
        $locale = $input->getOption('locale');
        $docs = $input->getOption('docs');

        try {
            $options = new InspectionOptions(
                path: $path,
                only: \is_string($only) ? $only : null,
                dryRun: true === $input->getOption('dry-run'),
                force: array_values(array_filter($force, \is_string(...))),
                // `--force` without a value arrives as a null item.
                forceAll: \in_array(null, $force, true),
                since: \is_string($since) ? $since : null,
                prune: true === $input->getOption('prune'),
                noAi: true === $input->getOption('no-ai'),
                language: \is_string($locale) ? $locale : null,
                fallbackLanguage: $this->defaultLanguage,
                docs: \is_string($docs) ? $docs : $this->defaultDocs,
            );
        } catch (\InvalidArgumentException $invalid) {
            // An option DevTools refuses is a configuration error (exit 2), not a usage error of the console.
            $io->error($invalid->getMessage());

            return Command::INVALID;
        }

        $io->title(\sprintf('DevTools — inspection de %s', $path));
        $report = $this->pipeline->run($options, new ConsoleProgressReporter($io));
        $this->display($io, $report, true === $input->getOption('dry-run'));

        return $report->exitCode();
    }

    private function display(SymfonyStyle $io, InspectionReport $report, bool $dryRun): void
    {
        if ($dryRun) {
            $changes = array_filter($report->decisions, static fn (FreshnessDecision $decision): bool => DecisionKind::Keep !== $decision->kind);
            $io->table(['Workflow', 'Decision', 'Why'], array_map(static fn (FreshnessDecision $decision): array => [$decision->id->value, $decision->kind->value, $decision->describe()], array_values($changes)));
        }

        foreach ($report->fallbacks as $fallback) {
            $io->warning(\sprintf("%s — the console could not answer; attributes were read instead, with medium confidence.\n%s", $fallback['stack'], $fallback['cause']));
        }

        foreach ($report->warnings as $warning) {
            $io->warning($warning);
        }

        foreach ($report->errors as $error) {
            $io->error($error);
        }

        new SummaryRenderer()->inspection($io, $report, self::stacks($report));
    }

    /**
     * `Symfony 7.4 + Angular 22.0`, read from what the report recorded per stack.
     */
    private static function stacks(InspectionReport $report): string
    {
        return implode(' + ', array_map(static fn (array $stack): string => $stack['stack'], $report->stacks));
    }
}
