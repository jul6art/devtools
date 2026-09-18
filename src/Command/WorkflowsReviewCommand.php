<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Console\ChangeRenderer;
use Jul6Art\DevTools\Inspection\Diff\ChangeGroup;
use Jul6Art\DevTools\Inspection\Model\FileRef;
use Jul6Art\DevTools\Review\ChangeReview;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `workflows:review` (ADR-0047): the changed facts, one by one, accepted or refused.
 *
 * ⚠️ Nothing is written before the last answer. A review interrupted halfway leaves the project exactly
 * as it was — someone who quits because they are unsure must not have half-decided.
 */
#[AsCommand(name: 'workflows:review', description: 'Review the changed facts one by one: accept or refuse')]
final class WorkflowsReviewCommand extends Command
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
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only this type of workflow')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Rewrite the facts of accepted changes without asking Claude for the prose');
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

        // ⚠️ A review without someone to answer is not a review: in CI it says what changed and fails.
        if (!$input->isInteractive()) {
            $io->error('workflows:review needs a terminal. In CI, use workflows:check or workflows:diff.');

            return Command::FAILURE;
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
            $io->success('Aucun fait n\'a changé.');

            return Command::SUCCESS;
        }

        $accepted = [];
        $refused = [];

        foreach ($groups as $index => $group) {
            $io->newLine();
            $io->writeln(\sprintf(' <fg=gray>%d/%d</>', $index + 1, \count($groups)));
            $io->writeln($this->describe($group));

            // ⚠️ A ChoiceQuestion with keys answers with the KEY, not the label: matching the label
            // silently skipped every answer, and the review accepted nothing.
            $answer = $io->askQuestion(new ChoiceQuestion('  accepter, refuser ou passer ?', ['a' => 'accepter', 'r' => 'refuser', 'p' => 'passer'], 'p'));

            match ($answer) {
                'a', 'accepter' => $accepted[] = $group,
                'r', 'refuser' => $refused[] = $group,
                default => null,
            };
        }

        $io->newLine();

        if ([] !== $accepted) {
            $written = $this->review->accept($path, $accepted, true === $input->getOption('no-ai'), \is_string($only) ? $only : null);
            $io->writeln(\sprintf(' <info>✓</info> %d fait%s accepté%s · %d page%s réécrite%s', \count($accepted), 1 === \count($accepted) ? '' : 's', 1 === \count($accepted) ? '' : 's', $written->count('updated'), 1 === $written->count('updated') ? '' : 's', 1 === $written->count('updated') ? '' : 's'));
        }

        foreach ($this->review->restoreCommands($report, $refused) as $command) {
            $io->writeln('   '.$command);
        }

        $io->newLine();

        return Command::SUCCESS;
    }

    private function describe(ChangeGroup $group): string
    {
        $change = $group->change;
        $lines = [\sprintf(
            ' <options=bold>%s</> <fg=cyan>%s</> %s%s',
            ChangeRenderer::natureOf($change->nature),
            ChangeRenderer::subjectOf($change->subject),
            $change->target,
            $change->declaredIn instanceof FileRef ? \sprintf('  <fg=gray>%s%s</>', $change->declaredIn->path, null === $change->line ? '' : ':'.$change->line) : '',
        )];

        if (null !== $change->before) {
            $lines[] = \sprintf('   <fg=red>- %s</>', $change->before);
        }

        if (null !== $change->after) {
            $lines[] = \sprintf('   <fg=green>+ %s</>', $change->after);
        }

        $lines[] = \sprintf('   <fg=gray>%d workflow%s</>', $group->count(), 1 === $group->count() ? '' : 's');

        return implode("\n", $lines);
    }
}
