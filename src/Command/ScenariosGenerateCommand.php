<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Scenario\ScenarioGenerator;
use Jul6Art\DevTools\Scenario\ScenariosUnavailable;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scenarios for the routes and components of a project (ADR-0020): a skeleton from the facts where one can
 * be replayed at once, a brief for Claude for the rest, nothing for a scenario a human wrote.
 */
#[AsCommand(name: 'scenarios:generate', description: 'Write a skeleton scenario for each route that allows one, and ask Claude for the rest')]
final class ScenariosGenerateCommand extends Command
{
    public function __construct(
        private readonly ScenarioGenerator $generator = new ScenarioGenerator(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('workflow', InputArgument::OPTIONAL, 'One workflow, e.g. route.order.new (default: every route and component)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skeletons only: no brief for Claude')
            ->addOption('reviewed', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A scenario you reviewed after its workflow was rewritten: it stays as it is, and is yours from now on');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $workflow = $input->getArgument('workflow');
        $reviewed = $input->getOption('reviewed');
        $reviewed = \is_array($reviewed) ? array_values(array_filter($reviewed, \is_string(...))) : [];

        if (!is_dir($path)) {
            $io->error(\sprintf('"%s" is not a directory.', $path));

            return Command::INVALID;
        }

        $directory = DevToolsDirectory::documented(new ProjectRoot($path));

        try {
            $only = \is_string($workflow) ? new WorkflowId($workflow) : null;
            $result = $this->generator->generate($directory, new WorkflowTypeRegistry(new XmlConfigReader()->read($directory->configFile())->customTypes), $only, true === $input->getOption('no-ai'), $reviewed);
        } catch (InvalidModel $invalid) {
            $io->error($invalid->getMessage());

            return Command::INVALID;
        } catch (ScenariosUnavailable|InvalidXml $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->written as $id) {
            $io->writeln(\sprintf('<info>✓</info> %s', $id));
        }

        foreach ($result->reviewed as $id) {
            $io->writeln(\sprintf('<info>✓</info> %s reviewed: it is yours, and stays as it is', $id));
        }

        foreach ($result->briefs as $id) {
            $io->writeln(\sprintf('<comment>→</comment> %s: brief for Claude in .devtools/pending/', $id));
        }

        foreach ($result->kept as $id => $why) {
            $io->writeln(\sprintf('<comment>!</comment> %s: %s', $id, $why));
        }

        if ([] !== $result->skipped) {
            $io->text(\sprintf('No skeleton for %s: a parameter, or no GET — Claude writes those, or you do.', implode(', ', $result->skipped)));
        }

        foreach ($result->errors as $where => $error) {
            $io->error(\sprintf('%s: %s', $where, $error));
        }

        if ([] !== $result->briefs) {
            $io->text('Run /devtools-inspect in Claude Code, or have Claude follow the briefs, then workflows:apply.');
        }

        if ([] === $result->written && [] === $result->briefs && [] === $result->reviewed && [] === $result->errors) {
            $io->text('Nothing to generate: every scenario is up to date.');
        }

        return [] === $result->errors ? Command::SUCCESS : Command::FAILURE;
    }
}
