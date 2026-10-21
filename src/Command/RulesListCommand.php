<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rule\RuleIndexEntry;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The rules of a project (ADR-0026): where each applies, what it says, and what it did — given to Claude, and
 * stopping a change once it has an assertion (ADR-0027).
 */
#[AsCommand(name: 'rules:list', description: 'List the rules: where each applies, what it says, what it did')]
final class RulesListCommand extends Command
{
    public function __construct(private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'proposed, active or archived (default: proposed and active)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $status = $input->getOption('status');
        $statuses = \is_string($status) ? array_filter([RuleStatus::tryFrom($status)]) : [RuleStatus::Proposed, RuleStatus::Active];

        if (!is_dir($path) || [] === $statuses) {
            $io->error(is_dir($path) ? '--status is proposed, active or archived.' : \sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $repository = new RuleRepository(DevToolsDirectory::documented(new ProjectRoot($path)));
        $stats = $repository->stats();
        $entries = array_values(array_filter($repository->index()->entries, static fn (RuleIndexEntry $entry): bool => \in_array($entry->status, $statuses, true)));

        if ([] === $entries) {
            $io->text(\sprintf('No %s rule.', implode(' or ', array_map(static fn (RuleStatus $one): string => $one->value, $statuses))));

            return Command::SUCCESS;
        }

        $io->table(['Rule', 'Status', 'Applies to', 'Given', 'Blocked', 'Instruction'], array_map(
            static fn (RuleIndexEntry $entry): array => [
                $entry->id,
                $entry->status->value,
                implode(' · ', [...$entry->appliesTo->globs, ...$entry->appliesTo->workflowTypes]),
                (string) $stats->of($entry->id)->applied,
                (string) $stats->of($entry->id)->blocked,
                $entry->instruction,
            ],
            $entries,
        ));

        return Command::SUCCESS;
    }
}
