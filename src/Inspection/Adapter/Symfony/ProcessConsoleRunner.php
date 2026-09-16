<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessConsoleRunner implements ConsoleRunnerInterface
{
    #[\Override]
    public function run(array $command, string $workingDirectory, float $timeout): ConsoleRun
    {
        // SHELL_VERBOSITY is inherited from whoever runs DevTools — PHPUnit sets it to -1 — and a quiet
        // console prints no JSON at all.
        $process = new Process($command, $workingDirectory, ['SHELL_VERBOSITY' => '0', 'COLUMNS' => '200'], null, $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new ConsoleFailed(\sprintf('`%s` did not answer within %d seconds.', implode(' ', $command), (int) $timeout));
        }

        return new ConsoleRun($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }
}
