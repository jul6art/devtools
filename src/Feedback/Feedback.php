<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

/**
 * A feedback as data (ADR-0025): tied to a target, filed under a closed category, summed up in one line, and
 * counted — every time it is seen again is an occurrence, never a duplicate.
 */
final readonly class Feedback
{
    public const int SUMMARY_MAX = 200;

    public const string ID = '/^fb-\d{4}-\d{2}-\d{2}-[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param non-empty-list<Occurrence> $occurrences the first is the report itself
     */
    public function __construct(
        public string $id,
        public FeedbackStatus $status,
        public FeedbackTarget $target,
        public string $category,
        public string $summary,
        public array $occurrences,
        public ?string $promotedTo = null,
    ) {
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a feedback identifier: fb-<date>-<words>.', $id));
        }
    }

    public function reported(): Occurrence
    {
        return $this->occurrences[0];
    }

    public function seenAgain(Occurrence $occurrence): self
    {
        // Seen again once fixed: it came back, and is open again.
        $status = FeedbackStatus::Fixed === $this->status ? FeedbackStatus::Open : $this->status;

        return new self($this->id, $status, $this->target, $this->category, $this->summary, [...$this->occurrences, $occurrence], $this->promotedTo);
    }

    public function withStatus(FeedbackStatus $status, ?string $rule = null): self
    {
        return new self($this->id, $status, $this->target, $this->category, $this->summary, $this->occurrences, $rule ?? $this->promotedTo);
    }

    /**
     * Where it is written, relative to `.devtools/feedback/`: one directory per year.
     */
    public function path(): string
    {
        return $this->reported()->at->format('Y').'/'.$this->id.'.xml';
    }
}
