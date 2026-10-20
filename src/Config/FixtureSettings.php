<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

/**
 * How one fixture a scenario names is loaded, for `scenarios:run` (ADR-0021): a command of the project, run
 * from its root before the scenario.
 */
final readonly class FixtureSettings
{
    public function __construct(public string $name, public string $command)
    {
    }
}
