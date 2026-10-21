<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * Where a rule applies (ADR-0026): files, kinds of workflow, or both — never everywhere. Of one kind, any of
 * them; of both kinds, one of each.
 */
final readonly class AppliesTo
{
    /**
     * @param list<string> $globs         project-relative globs, {@see Glob}
     * @param list<string> $workflowTypes `routes`, `commands`…
     * @param string|null  $category      to group, never to match
     */
    public function __construct(
        public array $globs = [],
        public array $workflowTypes = [],
        public ?string $category = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->globs && [] === $this->workflowTypes;
    }

    /**
     * How closely it applies to a file, compared from the first figure to the last: the longest glob written
     * out that matches it, then how many kinds of criterion matched, then how few kinds of workflow it names.
     * Null when it does not apply.
     *
     * @param list<string> $types the kinds of the workflows the file is part of
     *
     * @return array{int, int, int}|null
     */
    public function specificity(string $file, array $types): ?array
    {
        $literal = 0;

        if ([] !== $this->globs) {
            $matching = array_filter(array_map(static fn (string $glob): Glob => new Glob($glob), $this->globs), static fn (Glob $glob): bool => $glob->matches($file));

            if ([] === $matching) {
                return null;
            }

            $literal = max(array_map(static fn (Glob $glob): int => $glob->literal(), $matching));
        }

        if ([] !== $this->workflowTypes && [] === array_intersect($this->workflowTypes, $types)) {
            return null;
        }

        return [$literal, ([] !== $this->globs ? 1 : 0) + ([] !== $this->workflowTypes ? 1 : 0), -\count($this->workflowTypes)];
    }
}
