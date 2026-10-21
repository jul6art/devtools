<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rule\RulePromotion;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asks Claude for the rule some feedback should become (ADR-0026): a brief in `.devtools/pending/`, its draft
 * checked by `workflows:apply`, the rule proposed until a human activates it.
 */
#[AsCommand(name: 'rules:promote', description: 'Ask Claude for the rule some feedback should become')]
final class RulesPromoteCommand extends Command
{
    public function __construct(private readonly Clock $clock = new SystemClock(), private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('feedback', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'The feedback the rule is promoted from: fb-2026-09-20-order-product')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
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

        $ids = $input->getArgument('feedback');
        $ids = \is_array($ids) ? array_values(array_filter($ids, \is_string(...))) : [];
        $promotion = new RulePromotion(DevToolsDirectory::documented(new ProjectRoot($path)), $this->clock);
        $refusals = $promotion->refusals($ids);

        if ([] !== $refusals || [] === $ids) {
            $io->error($refusals);

            return Command::FAILURE;
        }

        $brief = $promotion->brief($ids);
        $io->success(\sprintf('Brief written: %s', $brief));
        $io->text([
            'Claude writes the rule from it — the devtools-inspect skill follows every brief — and workflows:apply checks it.',
            'The rule is then proposed: read it, and rules:activate <rule> gives it to Claude where it applies.',
        ]);

        return Command::SUCCESS;
    }
}
