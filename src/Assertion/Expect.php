<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * What an assertion expects (ADR-0027): a pattern found or absent, an element present or absent.
 */
enum Expect: string
{
    case MustMatch = 'must-match';
    case MustNotMatch = 'must-not-match';
    case MustExist = 'must-exist';
    case MustNotExist = 'must-not-exist';

    /**
     * Whether what is looked for must be there.
     */
    public function present(): bool
    {
        return self::MustMatch === $this || self::MustExist === $this;
    }
}
