<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

/**
 * Who reported it: a human, or the verifier of ADR-0024 — what the retrospective compares (ADR-0029).
 */
enum FeedbackSource: string
{
    case Human = 'human';
    case Verifier = 'verifier';
}
