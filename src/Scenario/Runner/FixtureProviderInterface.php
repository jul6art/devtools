<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

/**
 * Loads the data a scenario names, before it runs (ADR-0021). The project provides it.
 */
interface FixtureProviderInterface
{
    /**
     * @throws \RuntimeException when the fixture cannot be loaded
     */
    public function load(string $name): void;
}
