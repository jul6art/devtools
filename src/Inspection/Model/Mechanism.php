<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * Something that runs inside a workflow without being one: a listener, a subscriber, a middleware, a
 * Doctrine filter (ADR-0043).
 *
 * A listener used to be a workflow of its own, with a page nobody opened. What a reader wants to know
 * is what it can do **during this workflow** — hence the event, the priority, and the fields it writes.
 */
final readonly class Mechanism
{
    public const array KINDS = ['listener', 'subscriber', 'middleware', 'doctrine-filter'];

    public string $kind;

    public string $name;

    public string $event;

    /**
     * @var list<DecisionPoint> what it can change while it runs
     */
    public array $decisions;

    /**
     * @param list<DecisionPoint> $decisions
     */
    public function __construct(
        string $kind,
        string $name,
        string $event,
        public FileRef $declaredIn,
        public ?int $priority = null,
        array $decisions = [],
    ) {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new InvalidModel(\sprintf('"%s" is not a kind of mechanism: %s.', $kind, implode(', ', self::KINDS)));
        }

        $this->kind = $kind;
        $this->name = NonEmpty::string($name, 'name of a mechanism');
        $this->event = NonEmpty::string($event, \sprintf('event of the mechanism "%s"', $name));
        $this->decisions = SortedList::of($decisions, static fn (DecisionPoint $decision): string => $decision->sortKey(), \sprintf('decision of the mechanism "%s"', $name));
    }

    public function sortKey(): string
    {
        return $this->name."\0".$this->event;
    }
}
