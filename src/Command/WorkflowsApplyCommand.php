<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Ai\DraftApplier;
use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workflows:apply', description: 'Validate the page drafts Claude wrote and apply them')]
final class WorkflowsApplyCommand extends Command
{
    public function __construct(
        private readonly DraftApplier $applier = new DraftApplier(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)');
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

        $result = $this->applier->apply(new ProjectRoot($path));

        foreach ($result->accepted as $id) {
            $io->writeln(\sprintf('<info>✓</info> %s', $id));
        }

        foreach ($result->refused as $id => $errors) {
            $io->error([\sprintf('%s — draft refused:', $id), ...$errors]);
        }

        if ([] === $result->accepted && [] === $result->refused) {
            $io->text('No draft to apply.');
        }

        return [] === $result->refused ? Command::SUCCESS : 1;
    }
}
