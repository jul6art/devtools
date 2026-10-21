<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Assertion\AssertionProposal;
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
 * Asks Claude for the mechanical assertion of a rule (ADR-0027): a brief in `.devtools/pending/`, its draft run by
 * `workflows:apply` — passing now, failing where the feedback was reported — before it is written.
 */
#[AsCommand(name: 'assertions:propose', description: 'Ask Claude for the assertion that enforces a rule in the gate')]
final class AssertionsProposeCommand extends Command
{
    public function __construct(private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('rule', InputArgument::REQUIRED, 'The rule: rule-product-fields-use-select2')
            ->addOption('before', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A commit where the defect is, and the assertion must fail (default: where its feedback was reported)')
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

        $rule = $input->getArgument('rule');
        $rule = \is_string($rule) ? $rule : '';
        $before = $input->getOption('before');
        $proposal = new AssertionProposal(DevToolsDirectory::documented(new ProjectRoot($path)));
        $refusals = $proposal->refusals($rule);

        if ([] !== $refusals) {
            $io->error($refusals);

            return Command::FAILURE;
        }

        try {
            $brief = $proposal->brief($rule, \is_array($before) ? array_values(array_filter($before, \is_string(...))) : []);
        } catch (\RuntimeException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Brief written: %s', $brief));
        $io->text([
            'Claude writes the assertion from it — the devtools-inspect skill follows every brief — and workflows:apply runs it:',
            'it must pass on the project as it is, and fail where the feedback was reported. The gate then runs it on every change it watches.',
        ]);

        return Command::SUCCESS;
    }
}
