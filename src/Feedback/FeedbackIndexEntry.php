<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

/**
 * One feedback in one line of `feedback/index.xml`: what a recurrence, a list or a retrospective reads.
 */
final readonly class FeedbackIndexEntry
{
    public function __construct(
        public string $id,
        public FeedbackStatus $status,
        public string $category,
        public \DateTimeImmutable $reportedAt,
        public FeedbackSource $by,
        public int $occurrences,
        public string $path,
        public string $summary,
        public FeedbackTarget $target,
        public ?string $commit = null,
        public ?string $promotedTo = null,
    ) {
    }

    public static function of(Feedback $feedback): self
    {
        $reported = $feedback->reported();

        return new self($feedback->id, $feedback->status, $feedback->category, $reported->at, $reported->by, \count($feedback->occurrences), $feedback->path(), $feedback->summary, $feedback->target, $reported->commit, $feedback->promotedTo);
    }
}
