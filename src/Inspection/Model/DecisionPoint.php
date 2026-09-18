<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * One place where the code decides that a field takes one value rather than another (ADR-0043).
 *
 * This is what the diagrams of a page are drawn from: a flowchart of files restates a table, a flowchart
 * of decisions answers the only question a reader really has — why this value and not the other one.
 *
 * Nothing here is interpreted: the condition and the value are the source as written, re-printed from the
 * syntax tree. Turning them into business language is Claude's job, under the validation of ADR-0043.
 */
final readonly class DecisionPoint
{
    public string $target;

    public string $value;

    /**
     * Where the decision is written. Only the path matters — a decision is a place in a file, not a
     * component — so the role is normalised away and a round-trip through XML gives the same object.
     */
    public FileRef $declaredIn;

    /**
     * @param string      $target    `App\Entity\Invoice::vatCategory`, or the variable name when the receiver's type is not known
     * @param string      $value     the assigned expression, as written
     * @param string|null $condition the condition that leads to it; null for an unconditional default
     */
    public function __construct(
        string $target,
        string $value,
        public ?string $condition,
        FileRef $declaredIn,
        public int $line,
        public Confidence $confidence = Confidence::High,
    ) {
        $this->declaredIn = new FileRef($declaredIn->path);
        $this->target = NonEmpty::string($target, 'target of a decision');
        $this->value = NonEmpty::string($value, \sprintf('value decided for "%s"', $target));

        if ($line < 1) {
            throw new InvalidModel(\sprintf('The decision on "%s" must name the line it is written at.', $target));
        }
    }

    /**
     * Only the targets whose value actually depends on something.
     *
     * An unconditional assignment written once is not a decision, it is a default: `$product->setPrice(new
     * Money(0))` answers no question. What a reader opens the page for is the field that takes one value
     * *rather than another* — so a target is kept when one of its values is conditional, or when it has
     * more than one value. Without this filter a page drew a diagram for every setter of every file it
     * traverses, which is the inventory this ADR removed.
     *
     * @param list<self> $points
     *
     * @return list<self>
     */
    public static function branching(array $points): array
    {
        $values = [];
        $conditional = [];

        foreach ($points as $point) {
            $values[$point->target][$point->value] = true;
            $conditional[$point->target] = ($conditional[$point->target] ?? false) || null !== $point->condition;
        }

        return array_values(array_filter(
            $points,
            static fn (self $point): bool => $conditional[$point->target] || 1 < \count($values[$point->target]),
        ));
    }

    /**
     * Sorted by what is decided first, then by where: two scans of an unchanged project build the same list.
     */
    public function sortKey(): string
    {
        return $this->target."\0".$this->declaredIn->path."\0".\sprintf('%09d', $this->line)."\0".$this->value;
    }
}
