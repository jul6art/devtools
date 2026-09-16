<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Symfony;

final readonly class ConsoleRun
{
    public function __construct(public int $exitCode, public string $output, public string $errorOutput)
    {
    }
}
