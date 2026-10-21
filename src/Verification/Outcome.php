<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Verification;

/**
 * What `verify:collect` retained (ADR-0024): the verdict that counts, why when it is not the verifier's own, and
 * the findings — what the gate reads (ADR-0023).
 */
final readonly class Outcome
{
    /**
     * @param list<string>  $reasons  why the verdict is not the one the verifier gave
     * @param list<Finding> $findings
     */
    public function __construct(public VerificationVerdict $verdict, public \DateTimeImmutable $collectedAt, public array $reasons = [], public array $findings = [])
    {
    }
}
