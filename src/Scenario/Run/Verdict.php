<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Run;

/**
 * What became of a step or a scenario (ADR-0021). `failed`: the application did not do what the scenario
 * says; `error`: the run could not tell — a login, a fixture, the browser.
 */
enum Verdict: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
    case Skipped = 'skipped';

    /**
     * The verdict of a scenario: its worst step.
     *
     * @param list<self> $verdicts
     */
    public static function worst(array $verdicts): self
    {
        foreach ([self::Error, self::Failed] as $bad) {
            if (\in_array($bad, $verdicts, true)) {
                return $bad;
            }
        }

        return [] !== $verdicts && [] === array_filter($verdicts, static fn (self $verdict): bool => self::Skipped !== $verdict) ? self::Skipped : self::Passed;
    }
}
