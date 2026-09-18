<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

/**
 * What became of a knowledge file offered to the library (ADR-0041).
 *
 * Only {@see self::Deposited} wrote anything: a file already there is never overwritten, and a library
 * nobody can write to is a warning, never a failure.
 */
enum DepositOutcome: string
{
    case Deposited = 'deposited';
    case AlreadyPresent = 'already-present';
    case NotWritable = 'not-writable';
    case Declined = 'declined';
}
