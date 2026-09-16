<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

interface ConsoleRunnerInterface
{
    /**
     * @param list<string> $command one argument per item — never a string for a shell
     */
    public function run(array $command, string $workingDirectory, float $timeout): ConsoleRun;
}
