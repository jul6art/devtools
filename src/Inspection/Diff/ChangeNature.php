<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Diff;

/**
 * What happened to a fact between the tracking file and the model the inspection just rebuilt (ADR-0046).
 */
enum ChangeNature: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Modified = 'modified';
}
