<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

/**
 * A task delivered (ADR-0029): a piece of work the `Stop` hook let end because the gate passed.
 */
final readonly class Task
{
    /**
     * @param string       $id        the moment it was delivered, `Y-m-d-His`
     * @param string|null  $base      the commit it started at
     * @param string|null  $head      the commit it was delivered at
     * @param int          $attempts  the passages of the gate it took, the green one included
     * @param list<string> $workflows the workflows its change reached
     * @param list<string> $steps     the steps of the gate that failed on the way
     * @param list<string> $blocked   the rules whose assertion stopped it on the way
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $deliveredAt,
        public ?string $base = null,
        public ?string $head = null,
        public int $attempts = 1,
        public array $workflows = [],
        public array $steps = [],
        public array $blocked = [],
    ) {
    }

    public static function id(\DateTimeImmutable $deliveredAt): string
    {
        return $deliveredAt->format('Y-m-d-His');
    }

    public function month(): string
    {
        return $this->deliveredAt->format('Y-m');
    }
}
