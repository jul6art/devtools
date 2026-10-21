<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rule\RuleNotFound;
use Jul6Art\DevTools\Rule\RuleRepository;
use Jul6Art\DevTools\Rule\RuleStatus;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A rule that no longer serves is archived (ADR-0029): no longer given to Claude, its assertion no longer run by the
 * gate — kept, with its history, and `rules:activate` brings it back. Only a human archives: `retro` proposes.
 */
#[AsCommand(name: 'rules:archive', description: 'Archive a rule that no longer serves: no longer given, no longer checked')]
final class RulesArchiveCommand extends Command
{
    public function __construct(private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('rules', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'The rules: rule-product-fields-use-select2')
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

        $repository = new RuleRepository(DevToolsDirectory::documented(new ProjectRoot($path)));
        $ids = $input->getArgument('rules');
        $failed = false;

        foreach (\is_array($ids) ? array_filter($ids, \is_string(...)) : [] as $id) {
            try {
                $rule = $repository->get($id);
            } catch (RuleNotFound $missing) {
                $io->error($missing->getMessage());
                $failed = true;

                continue;
            }

            if (RuleStatus::Archived === $rule->status) {
                $io->text(\sprintf('%s is already archived.', $rule->id));

                continue;
            }

            $repository->save($rule->withStatus(RuleStatus::Archived));
            $io->success(\sprintf('%s is archived: no longer given to Claude%s. rules:activate brings it back.', $rule->id, null === $rule->assertion ? '' : ', its assertion no longer run'));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
