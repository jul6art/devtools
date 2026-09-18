<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Console\ChangeRenderer;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\InspectionOptions;
use Jul6Art\DevTools\Inspection\InspectionPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `workflows:diff` (ADR-0046): what changed in the workflows since their pages were written.
 *
 * The freshness of ADR-0010 answers « this page must be rewritten » ; this answers « this route lost its
 * security attribute, and it touches thirteen workflows ». It runs the pipeline in read-only mode and
 * writes nothing at all — reports included.
 */
#[AsCommand(name: 'workflows:diff', description: 'Show what changed in the workflows since their pages were written')]
final class WorkflowsDiffCommand extends Command
{
    public function __construct(
        private readonly InspectionPipeline $pipeline = new InspectionPipeline(),
        private readonly GitClient $git = new GitClient(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only this type of workflow')
            ->addOption('code', null, InputOption::VALUE_NONE, 'Show the code diff of each changed fact, since the commit its page was written from')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or md', 'text')
            ->addOption('exit-code', null, InputOption::VALUE_NONE, 'Exit with 1 when a fact changed');
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

        $only = $input->getOption('only');
        $format = $input->getOption('format');

        if (!\in_array($format, ['text', 'md'], true)) {
            $io->error(\sprintf('"%s" is not a format: text or md.', \is_string($format) ? $format : 'null'));

            return Command::INVALID;
        }

        // ⚠️ Read-only: `dryRun` is what keeps a diff from rewriting the pages it is asked to describe.
        $report = $this->pipeline->run(new InspectionOptions($path, only: \is_string($only) ? $only : null, dryRun: true, noAi: true));

        foreach ($report->errors as $error) {
            $io->error($error);
        }

        if ([] !== $report->errors) {
            return Command::INVALID;
        }

        $groups = $report->changeGroups();
        $renderer = new ChangeRenderer($this->git);
        $code = true === $input->getOption('code');

        $output->writeln('md' === $format ? $renderer->markdown($report, $groups, $path, $code) : $renderer->text($report, $groups, $path, $code));

        return [] !== $groups && true === $input->getOption('exit-code') ? 1 : Command::SUCCESS;
    }
}
