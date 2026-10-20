<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Runner;

use Jul6Art\DevTools\Scenario\Run\ScenarioResult;
use Jul6Art\DevTools\Scenario\Scenario;

/**
 * Replays scenarios (ADR-0021). Panther is the only implementation; Playwright could be another, behind this.
 */
interface RunnerInterface
{
    /**
     * @param list<Scenario> $scenarios
     * @param string         $artifacts where the screenshots and the DOMs of this run go
     *
     * @return list<ScenarioResult> in the order given
     */
    public function run(array $scenarios, string $artifacts): array;
}
