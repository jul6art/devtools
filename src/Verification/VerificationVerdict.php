<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Verification;

/**
 * What the verifier concluded (ADR-0024). `inconclusive` is also what an answer DevTools cannot check counts as.
 */
enum VerificationVerdict: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Inconclusive = 'inconclusive';
}
