<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Project\ProjectRoot;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the tools of the project for the assertions (ADR-0027), and counts how many times it did: the checker calls
 * it **once** per command, however many assertions read its output.
 */
final class ToolRunner
{
    public const float TIMEOUT = 600.0;

    private int $executions = 0;

    public function __construct(private readonly float $timeout = self::TIMEOUT)
    {
    }

    /**
     * @param list<string> $files what `{files}` stands for in the command
     */
    public function run(ProjectRoot $root, string $command, array $files): ToolOutput
    {
        $line = str_replace(ToolCheck::FILES, implode(' ', array_map(escapeshellarg(...), $files)), $command);
        ++$this->executions;
        $process = Process::fromShellCommandline($line, $root->path, timeout: $this->timeout);

        try {
            $process->run();

            return new ToolOutput($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
        } catch (ProcessTimedOutException) {
            return new ToolOutput(null, '', \sprintf('stopped after %d s', (int) $this->timeout));
        }
    }

    public function executions(): int
    {
        return $this->executions;
    }
}
