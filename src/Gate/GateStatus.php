<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Gate;

/**
 * Where a step of the gate stands (ADR-0023). A warning lets the task end; `--strict` makes it a failure.
 */
enum GateStatus: string
{
    case Passed = 'passed';
    case Warning = 'warning';
    case Failed = 'failed';
}
