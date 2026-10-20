<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Scenario\Visual;

/**
 * A capture against its baseline (ADR-0022). Only `different` fails the scenario; `no-baseline` says that
 * nobody accepted one yet.
 */
enum VisualVerdict: string
{
    case Identical = 'identical';
    case WithinThreshold = 'within-threshold';
    case Different = 'different';
    case NoBaseline = 'no-baseline';
}
