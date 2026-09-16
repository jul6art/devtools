<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * A stable, readable workflow identifier: `<prefix>.<segment>[.<segment>…]` (ADR-0003).
 *
 * It names the page, the tracking file and every link to them, so it must survive re-scans: it is
 * derived from the entry point, never numbered. The strict format also keeps it safe to use as a
 * file name — no separator, no dot segment.
 */
final readonly class WorkflowId implements \Stringable
{
    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+$/';

    public function __construct(public string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidModel(\sprintf('"%s" is not a valid workflow identifier: expected "<prefix>.<segment>", lowercase letters, digits and inner hyphens.', $value));
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function prefix(): string
    {
        return strstr($this->value, '.', true) ?: $this->value;
    }

    /**
     * The identifier without its prefix: the page of `route.order.create` is `order.create.md`.
     */
    public function pageName(): string
    {
        return substr($this->value, \strlen($this->prefix()) + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
