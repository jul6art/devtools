<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * One line of `rules/index.xml`: all the hook needs to give a rule to Claude, without opening its file.
 */
final readonly class RuleIndexEntry
{
    /**
     * @param non-empty-list<string> $origin
     */
    public function __construct(
        public string $id,
        public RuleStatus $status,
        public string $created,
        public array $origin,
        public AppliesTo $appliesTo,
        public string $instruction,
        public ?string $assertion = null,
    ) {
    }

    public static function of(Rule $rule): self
    {
        return new self($rule->id, $rule->status, $rule->created, $rule->origin, $rule->appliesTo, $rule->instruction, $rule->assertion);
    }

    public function path(): string
    {
        return $this->id.'.xml';
    }
}
