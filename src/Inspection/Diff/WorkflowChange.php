<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Diff;

use Jul6Art\DevTools\Inspection\Model\FileRef;

/**
 * One fact that changed in a workflow (ADR-0046): what it is about, what it said, what it says now.
 *
 * ⚠️ `declaredIn` and `line` are display data, never identity: two workflows that carry the same fact
 * carry it at the same place in the code but report it from their own tracking, and a method moved down
 * a file must not read as a change. `key()` is what groups them.
 */
final readonly class WorkflowChange
{
    /**
     * @param string      $target the fact: `route:app_order_new`, `App\Entity\User::email`, `symfony/form`…
     * @param string|null $before the value it had, null when the fact did not exist
     * @param string|null $after  the value it has, null when the fact is gone
     * @param string|null $aspect what exactly changed, when a fact has several parts: `condition`, `value`, `priority`
     */
    public function __construct(
        public ChangeNature $nature,
        public ChangeSubject $subject,
        public string $target,
        public ?string $before = null,
        public ?string $after = null,
        public ?FileRef $declaredIn = null,
        public ?int $line = null,
        public ?string $aspect = null,
    ) {
    }

    /**
     * The identity of the fact, shared by every workflow that carries it.
     */
    public function key(): string
    {
        return implode("\0", [$this->nature->value, $this->subject->value, $this->target, $this->aspect ?? '', $this->before ?? '', $this->after ?? '']);
    }
}
