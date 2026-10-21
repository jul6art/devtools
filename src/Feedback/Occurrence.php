<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

/**
 * One time it was seen (ADR-0025): when, by whom, where, and at which commit.
 */
final readonly class Occurrence
{
    public function __construct(
        public \DateTimeImmutable $at,
        public FeedbackSource $by,
        public FeedbackTarget $target = new FeedbackTarget(),
        public ?string $commit = null,
    ) {
    }
}
