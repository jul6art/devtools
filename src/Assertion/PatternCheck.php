<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

/**
 * A regular expression on the text of each watched file — any stack (ADR-0027).
 */
final readonly class PatternCheck
{
    /**
     * @param string $regex a PCRE, delimiters included: `/<select\b/i`
     */
    public function __construct(public string $regex, public Expect $expect)
    {
        if (!\in_array($expect, [Expect::MustMatch, Expect::MustNotMatch], true)) {
            throw new \InvalidArgumentException('A pattern must match, or must not.');
        }
    }

    /**
     * Null when PHP reads the expression, else why it does not.
     */
    public function error(): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $valid = false !== preg_match($this->regex, '');
        } finally {
            restore_error_handler();
        }

        return $valid ? null : \sprintf('"%s" is not a regular expression PHP reads: %s.', $this->regex, preg_last_error_msg());
    }
}
