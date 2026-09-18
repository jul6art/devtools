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
 * `workflows:reject` (ADR-0047): the change was not wanted, so the documentation does not move and the
 * code goes back.
 *
 * ⚠️ Without `--restore` it writes nothing at all — it prints the command. With it, it is the only place
 * in DevTools that touches a project's own code, and it refuses a file that carries another changed fact.
 */
#[AsCommand(name: 'workflows:reject', description: 'Refuse changed facts: say how to bring the code back')]
final class WorkflowsRejectCommand extends Command
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
            ->addArgument('target', InputArgument::IS_ARRAY, 'The facts to refuse, by target')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refuse every changed fact')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only this type of workflow')
            ->addOption('restore', null, InputOption::VALUE_NONE, 'Run the restore instead of printing it');
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
            $io->success('Aucun fait n\'a changé : il n\'y a rien à refuser.');

            return Command::SUCCESS;
        }

        /** @var list<string> $targets */
        $targets = $input->getArgument('target');
        $refused = true === $input->getOption('all') ? $groups : ChangeReview::select($groups, $targets);

        if ([] === $refused) {
            $io->error([
                [] === $targets ? 'Name the facts to refuse, or pass --all.' : 'None of these targets has changed.',
                ...array_map(static fn (string $target): string => '  '.$target, ChangeReview::targets($groups)),
            ]);

            return Command::INVALID;
        }

        foreach ($refused as $group) {
            $io->writeln(\sprintf(
                ' <fg=red>✗</> %s %s %s',
                ChangeRenderer::natureOf($group->change->nature),
                ChangeRenderer::subjectOf($group->change->subject),
                $group->change->target,
            ));
        }

        $io->newLine();

        if (true !== $input->getOption('restore')) {
            $commands = $this->review->restoreCommands($report, $refused);

            if ([] === $commands) {
                $io->warning('These facts are not carried by a file under git: there is nothing to restore.');

                return Command::SUCCESS;
            }

            $io->writeln(' <options=bold>À lancer pour ramener le code :</>');
            $io->newLine();

            foreach ($commands as $command) {
                $io->writeln('   '.$command);
            }

            $io->newLine();

            return Command::SUCCESS;
        }

        [$restored, $left] = $this->review->restore($path, $report, $refused, $groups);

        foreach ($restored as $file) {
            $io->writeln(\sprintf(' <info>↩</info> %s ramené', $file));
        }

        foreach ($left as $file) {
            $io->writeln(\sprintf(' <comment>⚠</comment> %s porte un autre fait changé : à vous de voir', $file));
        }

        $io->newLine();

        return [] === $left ? Command::SUCCESS : 1;
    }
}
