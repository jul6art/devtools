<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * A navigation edge: the route or screen a workflow leads to, and how (`link`, `redirect`, …).
 */
final readonly class Edge
{
    public string $target;

    public ?string $label;

    public function __construct(string $target, ?string $label = null)
    {
        $this->target = NonEmpty::string($target, 'navigation target');
        $this->label = null === $label ? null : NonEmpty::string($label, 'navigation label');
    }

    public function sortKey(): string
    {
        return $this->target."\0".($this->label ?? '');
    }
}
