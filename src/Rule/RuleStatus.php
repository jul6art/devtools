<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * Where a rule stands (ADR-0026): proposed by Claude, active once a human said so — the only status given to
 * Claude — and archived when it no longer serves (ADR-0029).
 */
enum RuleStatus: string
{
    case Proposed = 'proposed';
    case Active = 'active';
    case Archived = 'archived';
}
