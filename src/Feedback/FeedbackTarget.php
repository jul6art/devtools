<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Feedback;

/**
 * What a feedback is about (ADR-0025): a workflow, an element of its page, a file — at least one of them.
 */
final readonly class FeedbackTarget
{
    public function __construct(public ?string $workflow = null, public ?string $selector = null, public ?string $file = null)
    {
    }

    public function isEmpty(): bool
    {
        return null === $this->workflow && null === $this->selector && null === $this->file;
    }

    /**
     * The words a reader recognises it by: what an identifier is made of.
     */
    public function label(): string
    {
        return match (true) {
            null !== $this->selector => $this->selector,
            null !== $this->file => pathinfo($this->file, \PATHINFO_FILENAME),
            default => (string) $this->workflow,
        };
    }
}
