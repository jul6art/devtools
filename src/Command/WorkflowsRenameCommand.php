<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Inspection\Model\InvalidModel;
use Jul6Art\DevTools\Inspection\Model\WorkflowId;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Tracking\Rename\RenameRefused;
use Jul6Art\DevTools\Tracking\Rename\WorkflowRenamer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `devtools workflows:rename <old> <new>`: a workflow carried to a new identifier with its page, its history and
 * every reference to it (ADR-0036).
 */
#[AsCommand(name: 'workflows:rename', description: 'Rename a workflow: its page, tracking file, history and every reference follow')]
final class WorkflowsRenameCommand extends Command
{
    public function __construct(private readonly WorkflowRenamer $renamer = new WorkflowRenamer())
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('old', InputArgument::REQUIRED, 'The identifier the workflow has: route.order.new')
            ->addArgument('new', InputArgument::REQUIRED, 'The identifier it takes: route.order.create')
            ->addArgument('path', InputArgument::OPTIONAL, 'The project (default: the current directory)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written; write nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $directory = DevToolsDirectory::documented(new ProjectRoot(\is_string($path) ? $path : (string) getcwd()));
        $dryRun = true === $input->getOption('dry-run');

        try {
            $old = new WorkflowId(self::argument($input, 'old'));
            $new = new WorkflowId(self::argument($input, 'new'));
            $plan = $this->renamer->rename($directory, new WorkflowTypeRegistry(new XmlConfigReader()->read($directory->configFile())->customTypes), $old, $new, $dryRun);
        } catch (InvalidModel|RenameRefused $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }

        $io->listing($plan->changed($directory->root->path));

        foreach ($plan->notes as $note) {
            $io->warning($note);
        }

        $io->success(\sprintf($dryRun ? '%s would become %s: %d files written — nothing was.' : '%s is now %s: %d files written, its history kept, no page asked of Claude.', $old->value, $new->value, \count($plan->writes)));

        return Command::SUCCESS;
    }

    private static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : '';
    }
}
