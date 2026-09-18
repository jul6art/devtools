<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Diff;

/**
 * One fact, and every workflow that carries it (ADR-0046 § 5).
 *
 * ⚠️ The review is grouped by fact and not by workflow because of a measured order of magnitude: on
 * cereezer, 233 of the 259 workflows traverse `src/Entity/User.php`. One changed line in a setter of
 * that entity is one thing to read, not 233.
 */
final readonly class ChangeGroup
{
    /**
     * @param list<string> $workflows the identifiers carrying this fact, sorted
     */
    public function __construct(public WorkflowChange $change, public array $workflows)
    {
    }

    /**
     * @param array<string, list<WorkflowChange>> $byWorkflow workflow identifier => its changes
     *
     * @return list<self> widest first, then by fact, so that two runs print the same thing
     */
    public static function of(array $byWorkflow): array
    {
        $changes = [];
        $workflows = [];

        foreach ($byWorkflow as $id => $list) {
            foreach ($list as $change) {
                $key = $change->key();
                $changes[$key] ??= $change;
                $workflows[$key][$id] = true;
            }
        }

        $groups = [];

        foreach ($changes as $key => $change) {
            $ids = array_keys($workflows[$key]);
            sort($ids, \SORT_STRING);
            $groups[] = new self($change, $ids);
        }

        usort($groups, static fn (self $first, self $second): int => [$second->count(), $first->change->key()] <=> [$first->count(), $second->change->key()]);

        return $groups;
    }

    public function count(): int
    {
        return \count($this->workflows);
    }
}
