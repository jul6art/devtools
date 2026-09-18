<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Console;

use Jul6Art\DevTools\Inspection\Progress\ProgressReporter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The progress of an inspection on a terminal (ADR-0042).
 *
 * A bar is drawn only when the output is decorated: redirected to a file or read by a CI, it would
 * write thousands of control sequences nobody reads. There, and whenever the work is not countable,
 * the stage announces itself in one line. At `--quiet` nothing is written at all, because
 * `SymfonyStyle` already swallows everything below `normal`.
 */
final class ConsoleProgressReporter implements ProgressReporter
{
    private ?ProgressBar $bar = null;

    private string $stage = '';

    public function __construct(private readonly SymfonyStyle $io)
    {
    }

    #[\Override]
    public function stage(string $name, ?int $steps = null): void
    {
        $this->finish();
        $this->stage = $name;

        if (!$this->drawsBar() || null === $steps || 0 === $steps) {
            $this->io->writeln(\sprintf(' <comment>·</comment> %s%s', $name, null === $steps ? '' : \sprintf(' (%d)', $steps)), OutputInterface::VERBOSITY_VERBOSE);

            return;
        }

        $this->bar = $this->io->createProgressBar($steps);
        $this->bar->setFormat(' %current%/%max% [%bar%] '.$name.' <info>%message%</info>');
        $this->bar->setMessage('');
        $this->bar->start();
    }

    #[\Override]
    public function advance(string $label): void
    {
        if ($this->bar instanceof ProgressBar) {
            $this->bar->setMessage($label);
            $this->bar->advance();

            return;
        }

        $this->io->writeln('   '.$label, OutputInterface::VERBOSITY_DEBUG);
    }

    #[\Override]
    public function finish(): void
    {
        if ($this->bar instanceof ProgressBar) {
            $this->bar->finish();
            $this->io->newLine(2);
            $this->bar = null;
        }

        $this->stage = '';
    }

    /**
     * The stage currently open, or an empty string. Read by tests, which cannot see a terminal.
     */
    public function current(): string
    {
        return $this->stage;
    }

    private function drawsBar(): bool
    {
        return $this->io->isDecorated() && !$this->io->isQuiet();
    }
}
