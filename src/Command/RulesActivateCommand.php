<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Feedback\Categories;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectFiles;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Rule\RuleChecks;
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
 * A human agrees with a rule (ADR-0026): from now on it is given to Claude before it edits a file the rule applies
 * to. Checked again first — the project may have moved since the rule was proposed.
 */
#[AsCommand(name: 'rules:activate', description: 'Give a rule to Claude where it applies, once you agree with it')]
final class RulesActivateCommand extends Command
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

        $root = new ProjectRoot($path);
        $directory = DevToolsDirectory::documented($root);
        $config = new XmlConfigReader()->read($directory->configFile());
        $repository = new RuleRepository($directory);
        $checks = new RuleChecks(new ProjectFiles()->of($root, $config->excludes()), new WorkflowTypeRegistry($config->customTypes)->all(), Categories::of($config));
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

            if (RuleStatus::Active === $rule->status) {
                $io->text(\sprintf('%s is already active.', $rule->id));

                continue;
            }

            $refusals = $checks->refusals($rule);

            if ([] !== $refusals) {
                $io->error([\sprintf('%s cannot be activated:', $rule->id), ...$refusals]);
                $failed = true;

                continue;
            }

            $repository->save($rule->withStatus(RuleStatus::Active));
            $io->success(\sprintf('%s is active: %s', $rule->id, $rule->instruction));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
