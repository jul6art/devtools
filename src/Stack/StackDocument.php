<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack;

/**
 * `.devtools/stack.xml`: the project's name and its stacks, sorted by root.
 */
final readonly class StackDocument
{
    /**
     * @var list<StackProfile>
     */
    public array $stacks;

    /**
     * @param list<StackProfile> $stacks
     */
    public function __construct(public string $projectName, array $stacks, public bool $projectNameLocked = false)
    {
        if ([] === $stacks) {
            throw new \InvalidArgumentException('A project has at least one stack, if only an unknown one.');
        }

        usort($stacks, static fn (StackProfile $a, StackProfile $b): int => strcmp($a->root, $b->root));
        $this->stacks = $stacks;
    }

    public function stackAt(string $root): ?StackProfile
    {
        foreach ($this->stacks as $stack) {
            if ($stack->root === $root) {
                return $stack;
            }
        }

        return null;
    }
}
