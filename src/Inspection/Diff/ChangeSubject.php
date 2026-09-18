<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Diff;

/**
 * The kind of fact a change is about (ADR-0046).
 *
 * ⚠️ There is no `file` subject on purpose: a file whose bytes changed without changing any of these
 * is what triggers the comparison, never what it reports.
 */
enum ChangeSubject: string
{
    case EntryPoint = 'entrypoint';
    case Attribute = 'attribute';
    case Decision = 'decision';
    case Mechanism = 'mechanism';
    case Dependency = 'dependency';
    case Test = 'test';
    case Package = 'package';
}
