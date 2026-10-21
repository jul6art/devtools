<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Command;

use Jul6Art\DevTools\Clock\Clock;
use Jul6Art\DevTools\Clock\SystemClock;
use Jul6Art\DevTools\Project\ProjectRoot;
use Jul6Art\DevTools\Retro\RetroMarkdown;
use Jul6Art\DevTools\Retro\Retrospective;
use Jul6Art\DevTools\Retro\XmlRetroWriter;
use Jul6Art\DevTools\Tracking\AtomicFileWriter;
use Jul6Art\DevTools\Tracking\DevToolsDirectory;
use Jul6Art\DevTools\Xml\InvalidXml;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Whether feedback goes down (ADR-0029): human remarks per task delivered, the categories that come back, the rules
 * that block and those that no longer serve — written to `.devtools/reports/retro-<date>.md`.
 */
#[AsCommand(name: 'retro', description: 'The retrospective: whether feedback goes down, and which rules serve')]
final class RetroCommand extends Command
{
    /**
     * The months a retrospective looks at by default: the last one and the three it is compared with.
     */
    private const int MONTHS = 4;

    public function __construct(private readonly Clock $clock = new SystemClock(), private readonly ?string $defaultPath = null)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'The first day looked at: 2026-07-01 (default: the first day of the month three months ago)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'md or xml', 'md')
            ->addOption('inactive-days', null, InputOption::VALUE_REQUIRED, 'Days without being given nor blocking after which a rule is proposed for archiving', (string) Retrospective::INACTIVE_DAYS)
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The project (default: the current directory)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) ? $path : ($this->defaultPath ?? (string) getcwd());
        $now = $this->clock->now();
        $since = $input->getOption('since');
        $since = \is_string($since) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $since, $now->getTimezone()) : $now->modify('first day of this month')->setTime(0, 0)->modify(\sprintf('-%d months', self::MONTHS - 1));
        $format = $input->getOption('format');
        $days = $input->getOption('inactive-days');
        $days = \is_string($days) && 1 === preg_match('/^[1-9]\d*$/', $days) ? (int) $days : 0;

        $refused = match (true) {
            !is_dir($path) => \sprintf('"%s" is not a directory.', $path),
            !$since instanceof \DateTimeImmutable || $since > $now => '--since is a day of the past: 2026-07-01.',
            !\in_array($format, ['md', 'xml'], true) => '--format is md or xml.',
            0 === $days => '--inactive-days is a number of days: 30.',
            default => null,
        };

        if (null !== $refused || !$since instanceof \DateTimeImmutable || !\is_string($format)) {
            $io->error($refused);

            return Command::INVALID;
        }

        $directory = DevToolsDirectory::documented(new ProjectRoot($path));

        try {
            $report = new Retrospective($directory, $this->clock)->report($since, $days);
        } catch (InvalidXml $invalid) {
            $io->error($invalid->getMessage());

            return Command::FAILURE;
        }

        $file = $directory->path(\sprintf('reports/retro-%s.%s', $now->format('Y-m-d'), $format));
        new AtomicFileWriter()->write($file, 'md' === $format ? RetroMarkdown::render($report) : new XmlRetroWriter()->serialize($report));
        $trend = $report->trend();

        $io->success(\sprintf('The retrospective: %s', $directory->root->relative($file)));
        $io->text(array_filter([
            null === $trend ? 'No trend yet: the last month and the three before it, each with a task delivered.' : \sprintf('%s: %s human remarks per task, %+d %% against the three months before.', $trend['month'], number_format($trend['perTask'], 2), $trend['change']),
            [] === $report->inactive ? null : \sprintf('%d rules proposed for archiving: devtools rules:archive <rule>.', \count($report->inactive)),
            [] === $report->unpromoted ? null : \sprintf('%d feedback seen twice, not promoted: devtools rules:promote <feedback>.', \count($report->unpromoted)),
        ]));

        return Command::SUCCESS;
    }
}
