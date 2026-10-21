<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Feedback\FeedbackNotFound;
use Jul6Art\DevTools\Feedback\FeedbackRecorder;
use Jul6Art\DevTools\Feedback\FeedbackStatus;
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
 * Closes a feedback (ADR-0025): fixed, or set aside on purpose. Promoted is what `rules:promote` does.
 */
#[AsCommand(name: 'feedback:resolve', description: 'Close a feedback: fixed, or wont-fix')]
final class FeedbackResolveCommand extends Command
{
    public function __construct(private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The feedback: fb-2026-09-20-order-product')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'fixed or wont-fix', 'fixed')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $status = $input->getOption('status');
        $status = \is_string($status) ? FeedbackStatus::tryFrom($status) : null;
        $id = $input->getArgument('id');
        $id = \is_string($id) ? $id : '';

        if (!is_dir($path) || !\in_array($status, [FeedbackStatus::Fixed, FeedbackStatus::WontFix], true)) {
            $io->error(is_dir($path) ? '--status is fixed or wont-fix: promoted is what rules:promote does.' : \sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        try {
            $feedback = FeedbackRecorder::for(DevToolsDirectory::documented(new ProjectRoot($path)))->resolve($id, $status);
        } catch (FeedbackNotFound $missing) {
            $io->error($missing->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s is %s.', $feedback->id, $feedback->status->value));

        return Command::SUCCESS;
    }
}
