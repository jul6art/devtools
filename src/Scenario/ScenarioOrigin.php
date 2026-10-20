<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario;

/**
 * Who wrote a scenario (ADR-0020). Only what DevTools or Claude wrote, unchanged since, is ever written again.
 */
enum ScenarioOrigin: string
{
    /** Derived from the facts by `scenarios:generate`, without AI. */
    case Skeleton = 'skeleton';
    /** A draft Claude wrote and `workflows:apply` accepted. */
    case Claude = 'claude';
    /** Written or modified by hand: never regenerated. */
    case Human = 'human';
}
