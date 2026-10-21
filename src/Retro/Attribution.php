<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Retro;

use Jul6Art\DevTools\Feedback\Occurrence;
use Jul6Art\DevTools\Inspection\Freshness\GitClient;
use Jul6Art\DevTools\Project\ProjectRoot;

/**
 * Which delivered task a remark is about (ADR-0029): of the tasks delivered before it was made, the last whose
 * commits hold the one it was seen at — else the last that reached its workflow. One `git rev-list` per range of
 * commits, whatever the number of remarks.
 */
final class Attribution
{
    /**
     * @var array<string, list<string>> `base..head` => its commits
     */
    private array $ranges = [];

    /**
     * @param list<Task> $tasks in the order they were delivered
     */
    public function __construct(private readonly ProjectRoot $root, private readonly array $tasks, private readonly GitClient $git = new GitClient())
    {
    }

    /**
     * @param string|null $workflow the workflow of the feedback, when the occurrence names none
     */
    public function taskOf(Occurrence $occurrence, ?string $workflow = null): ?Task
    {
        $before = array_reverse(array_values(array_filter($this->tasks, static fn (Task $task): bool => $task->deliveredAt <= $occurrence->at)));

        if (null !== $occurrence->commit) {
            foreach ($before as $task) {
                if ($this->holds($task, $occurrence->commit)) {
                    return $task;
                }
            }
        }

        $workflow = $occurrence->target->workflow ?? $workflow;

        return null === $workflow ? null : array_find($before, static fn (Task $task): bool => \in_array($workflow, $task->workflows, true));
    }

    private function holds(Task $task, string $commit): bool
    {
        if (null === $task->head) {
            return false;
        }

        if (self::same($task->head, $commit)) {
            return true;
        }

        if (null === $task->base || self::same($task->base, $task->head)) {
            return false;
        }

        $range = $task->base.'..'.$task->head;
        $this->ranges[$range] ??= $this->git->commitsBetween($this->root, $task->base, $task->head) ?? [];

        return array_any($this->ranges[$range], static fn (string $known): bool => self::same($known, $commit));
    }

    /**
     * A commit written in full or abbreviated.
     */
    private static function same(string $one, string $other): bool
    {
        return str_starts_with($one, $other) || str_starts_with($other, $one);
    }
}
