<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Config\XmlConfigReader;
use Jul6Art\DevTools\Feedback\Categories;
use Jul6Art\DevTools\Impact\ImpactUnavailable;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Inspection\Model\WorkflowTypeRegistry;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Verification\VerificationCollector;
use Jul6Art\DevTools\Verification\VerificationPreparer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prepares what a verifier judges a change on, in a fresh context (ADR-0024).
 */
#[AsCommand(name: 'verify:prepare', description: 'Prepare the folder a verifier judges a change on: the routes and components it reaches, their pages, scenarios and captures')]
final class VerifyPrepareCommand extends Command
{
    use SelectsChanges;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
        private readonly GitClient $git = new GitClient(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->configureChanges();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $refused = is_dir($path) ? $this->refuseChanges($input) : \sprintf('"%s" is not a directory.', $path);

        if (null !== $refused) {
            $io->error($refused);

            return Command::INVALID;
        }

        $root = new ProjectRoot($path);
        $files = $this->changes($input, $root, $this->git);

        if (null === $files) {
            $io->error('git could not answer: the project is not in a repository, or the reference is unknown.');

            return Command::FAILURE;
        }

        $directory = DevToolsDirectory::documented($root);

        try {
            $config = new XmlConfigReader()->read($directory->configFile());
            $folder = new VerificationPreparer()->prepare($directory, new WorkflowTypeRegistry($config->customTypes), $files, $this->clock->now(), Categories::of($config));
        } catch (ImpactUnavailable|PathOutsideProject $refusal) {
            $io->error($refusal->getMessage());

            return $refusal instanceof PathOutsideProject ? Command::INVALID : Command::FAILURE;
        }

        if (null === $folder) {
            $io->text('Nothing to verify: the change reaches no route and no component.');

            return Command::SUCCESS;
        }

        $relative = $root->relative($folder);
        $io->success(\sprintf('Prepared %s.', $relative));
        $io->text([
            'In Claude Code, have the devtools-verifier subagent judge it — it reads that folder and the code it lists,',
            \sprintf('and answers with a verdict: write its answer, as it is, to %s/%s, then run verify:collect.', $relative, VerificationCollector::VERDICT),
        ]);

        return Command::SUCCESS;
    }
}
