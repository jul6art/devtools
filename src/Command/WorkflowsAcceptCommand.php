<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Console\ChangeRenderer;
use Jul6Art\DevTools\Review\ChangeReview;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `workflows:accept` (ADR-0047): the change was wanted, the documentation follows.
 *
 * Only the workflows carrying the accepted facts are rewritten — the freshness leaves the rest alone —
 * and their history names the fact rather than the file it lives in.
 */
#[AsCommand(name: 'workflows:accept', description: 'Accept changed facts: rewrite the pages that carry them')]
final class WorkflowsAcceptCommand extends Command
{
    public function __construct(
        private readonly ChangeReview $review = new ChangeReview(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::IS_ARRAY, 'The facts to accept, by target (`App\Entity\User::email`)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Accept every changed fact')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only this type of workflow')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Rewrite the facts without asking Claude for the prose');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $only = $input->getOption('only');
        [$report, $groups] = $this->review->read($path, \is_string($only) ? $only : null);

        foreach ($report->errors as $error) {
            $io->error($error);
        }

        if ([] !== $report->errors) {
            return Command::INVALID;
        }

        if ([] === $groups) {
            $io->success('No fact changed: there is nothing to accept.');

            return Command::SUCCESS;
        }

        /** @var list<string> $targets */
        $targets = $input->getArgument('target');
        $accepted = true === $input->getOption('all') ? $groups : ChangeReview::select($groups, $targets);

        if ([] === $accepted) {
            $io->error([
                [] === $targets ? 'Name the facts to accept, or pass --all.' : 'None of these targets has changed.',
                ...array_map(static fn (string $target): string => '  '.$target, ChangeReview::targets($groups)),
            ]);

            return Command::INVALID;
        }

        foreach ($accepted as $group) {
            $io->writeln(\sprintf(
                ' <info>✓</info> %s %s %s   <fg=gray>%d workflow%s</>',
                ChangeRenderer::natureOf($group->change->nature),
                ChangeRenderer::subjectOf($group->change->subject),
                $group->change->target,
                $group->count(),
                1 === $group->count() ? '' : 's',
            ));
        }

        $written = $this->review->accept($path, $accepted, true === $input->getOption('no-ai'), \is_string($only) ? $only : null);

        $io->newLine();
        $io->writeln(\sprintf(
            ' %d page%s rewritten · %d brief%s for Claude',
            $written->count('updated'),
            1 === $written->count('updated') ? '' : 's',
            $written->briefsWritten,
            1 === $written->briefsWritten ? '' : 's',
        ));
        $io->newLine();

        return [] === $written->errors ? Command::SUCCESS : 1;
    }
}
