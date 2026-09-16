<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Freshness;

enum DecisionKind: string
{
    case Create = 'create';
    case Rewrite = 'rewrite';
    case Keep = 'keep';
    case Orphan = 'orphan';
    /** A workflow marked manual whose files changed: its page is never rewritten, only flagged. */
    case ManualStale = 'manual-stale';
}
