<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ask;

/**
 * A page a question selected (ADR-0039): found by its words, or one hop away from one that was — `via`.
 */
final readonly class SelectedPage
{
    public function __construct(
        public string $id,
        public string $title,
        public string $page,
        public float $score,
        public string $status,
        public string $updated,
        public ?string $via = null,
    ) {
    }

    public function isStale(): bool
    {
        return 'fresh' !== $this->status;
    }

    /**
     * @return array{id: string, title: string, page: string, score: float, status: string, updated: string, via: string|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'page' => $this->page, 'score' => round($this->score, 3), 'status' => $this->status, 'updated' => $this->updated, 'via' => $this->via];
    }
}
