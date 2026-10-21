<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Verification;

/**
 * One thing the verifier found (ADR-0024): on which workflow, how serious, of which category, where in the
 * page, and in a sentence. A blocking one is a proposed feedback (ADR-0025).
 */
final readonly class Finding
{
    public const string BLOCKING = 'blocking';

    public const string MINOR = 'minor';

    public function __construct(public string $workflow, public string $severity, public string $category, public string $text, public ?string $selector = null)
    {
    }

    public function isBlocking(): bool
    {
        return self::BLOCKING === $this->severity;
    }
}
