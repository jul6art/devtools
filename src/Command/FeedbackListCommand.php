<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Feedback\FeedbackIndexEntry;
use Jul6Art\DevTools\Feedback\FeedbackRecorder;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The feedback of a project (ADR-0025), filtered — and the categories it may be filed under.
 */
#[AsCommand(name: 'feedback:list', description: 'List the feedback of the project, or its categories')]
final class FeedbackListCommand extends Command
{
    public function __construct(private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'open, fixed, promoted or wont-fix')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Only this category')
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Only about this workflow')
            ->addOption('categories', null, InputOption::VALUE_NONE, 'The categories a feedback may have, instead')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $status = $input->getOption('status');
        $status = \is_string($status) ? FeedbackStatus::tryFrom($status) : FeedbackStatus::Open;

        if (!is_dir($path) || null === $status) {
            $io->error(is_dir($path) ? '--status is open, fixed, promoted or wont-fix.' : \sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $recorder = FeedbackRecorder::for(DevToolsDirectory::documented(new ProjectRoot($path)));

        if (true === $input->getOption('categories')) {
            $io->table(['Category', 'Covers'], array_map(null, array_keys($recorder->categories()->all), array_values($recorder->categories()->all)));

            return Command::SUCCESS;
        }

        $category = $input->getOption('category');
        $workflow = $input->getOption('workflow');
        $entries = array_values(array_filter(
            $recorder->repository()->index()->entries,
            static fn (FeedbackIndexEntry $entry): bool => $entry->status === $status
                && (!\is_string($category) || $entry->category === $category)
                && (!\is_string($workflow) || $entry->target->workflow === $workflow),
        ));

        if ([] === $entries) {
            $io->text(\sprintf('No %s feedback.', $status->value));

            return Command::SUCCESS;
        }

        $io->table(['Feedback', 'Category', 'Seen', 'About', 'Summary'], array_map(
            static fn (FeedbackIndexEntry $entry): array => [$entry->id, $entry->category, (string) $entry->occurrences, implode(' · ', array_filter([$entry->target->workflow, $entry->target->selector, $entry->target->file], \is_string(...))), $entry->summary],
            $entries,
        ));

        return Command::SUCCESS;
    }
}
