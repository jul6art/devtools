<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Gate\Gate;
use Jul6Art\DevTools\Gate\GateRenderer;
use Jul6Art\DevTools\Impact\ImpactUnavailable;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Project\PathOutsideProject;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The gate (ADR-0023): is the change finished? Freshness, scenarios, verification, assertions — on what the
 * change reaches, all of them, whatever the first finds. The working tree by default.
 */
#[AsCommand(name: 'gate', description: 'Judge a change before calling it finished: freshness, scenarios, verification, assertions')]
final class GateCommand extends Command
{
    use SelectsChanges;

    public function __construct(
        private readonly Gate $gate = new Gate(),
        private readonly GitClient $git = new GitClient(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->configureChanges();
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Where the application is served, to replay the scenarios (default: <scenarios base-url> of .devtools/config.xml)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds a scenario step waits for what it looks for')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on warnings too: a workflow without scenario, scenarios that could not run, code changed without a fact')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $format = $input->getOption('format');
        $baseUrl = $input->getOption('base-url');
        $timeout = $input->getOption('timeout');

        // Nothing named: the working tree, which is what a task in progress has changed.
        if ([] === self::named($input) && null === $input->getOption('commit') && null === $input->getOption('range')) {
            $input->setOption('changed', true);
        }

        $refused = match (true) {
            !is_dir($path) => \sprintf('"%s" is not a directory.', $path),
            !\in_array($format, ['text', 'json'], true) => \sprintf('"%s" is not a format: text or json.', \is_string($format) ? $format : ''),
            \is_string($timeout) && (int) $timeout <= 0 => '--timeout is a positive number of seconds.',
            default => $this->refuseChanges($input),
        };

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

        try {
            $result = $this->gate->run(DevToolsDirectory::documented($root), $files, true === $input->getOption('strict'), \is_string($baseUrl) ? $baseUrl : null, \is_string($timeout) ? (int) $timeout : null);
        } catch (ImpactUnavailable|PathOutsideProject $refusal) {
            $io->error($refusal->getMessage());

            return $refusal instanceof PathOutsideProject ? Command::INVALID : Command::FAILURE;
        }

        $renderer = new GateRenderer();
        $output->write('json' === $format ? json_encode($renderer->toArray($result), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n" : $renderer->text($result), false, OutputInterface::OUTPUT_RAW);

        return $result->passed() ? Command::SUCCESS : Command::FAILURE;
    }
}
