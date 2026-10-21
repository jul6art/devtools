<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Verification\Finding;
use Jul6Art\DevTools\Verification\VerificationCollector;
use Jul6Art\DevTools\Verification\VerificationVerdict;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the verdict the verifier gave and records the one that counts (ADR-0024): approved exits 0, anything
 * else 1.
 */
#[AsCommand(name: 'verify:collect', description: 'Check the verdict of the verifier and record the one that counts')]
final class VerifyCollectCommand extends Command
{
    public function __construct(
        private readonly Clock $clock = new SystemClock(),
        private readonly ?string $defaultPath = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('folder', InputArgument::OPTIONAL, 'The verification folder (default: the latest of .devtools/pending/)')
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
        $asked = $input->getArgument('folder');
        $folders = glob(DevToolsDirectory::documented($root)->path('pending/verification.*'), \GLOB_ONLYDIR) ?: [];
        sort($folders, \SORT_STRING);
        $folder = \is_string($asked) ? $root->absolute($asked) : array_last($folders);

        if (null === $folder || !is_file($folder.'/manifest.xml')) {
            $io->error(null === $folder ? 'No verification folder: verify:prepare writes one.' : \sprintf('%s is not a verification folder.', $root->relative($folder)));

            return Command::INVALID;
        }

        try {
            $outcome = new VerificationCollector()->collect($folder, $this->clock->now());
        } catch (InvalidXml $invalid) {
            $io->error($invalid->getMessage());

            return Command::FAILURE;
        }

        foreach ($outcome->findings as $finding) {
            $io->writeln(\sprintf('%s %s · %s%s — %s', $finding->isBlocking() ? '<error>✗</error>' : '<comment>!</comment>', $finding->workflow, $finding->category, null === $finding->selector ? '' : ' · '.$finding->selector, $finding->text));
        }

        $line = \sprintf('Verdict: %s — %s', $outcome->verdict->value, $root->relative($folder.'/'.VerificationCollector::OUTCOME));

        match ($outcome->verdict) {
            VerificationVerdict::Approved => $io->success($line),
            VerificationVerdict::Rejected => $io->error([$line, \sprintf('%d blocking findings.', \count(array_filter($outcome->findings, static fn (Finding $finding): bool => $finding->isBlocking())))]),
            VerificationVerdict::Inconclusive => $io->warning([$line, ...$outcome->reasons]),
        };

        return VerificationVerdict::Approved === $outcome->verdict ? Command::SUCCESS : Command::FAILURE;
    }
}
