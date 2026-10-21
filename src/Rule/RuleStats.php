<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * What every rule did, by identifier (ADR-0026): `.devtools/stats/rules.xml`, never committed.
 */
final readonly class RuleStats
{
    /**
     * @var array<string, RuleStat> sorted by identifier
     */
    public array $rules;

    /**
     * @param array<string, RuleStat> $rules
     */
    public function __construct(array $rules = [])
    {
        ksort($rules, \SORT_STRING);
        $this->rules = $rules;
    }

    public function of(string $id): RuleStat
    {
        return $this->rules[$id] ?? new RuleStat($id);
    }

    /**
     * @param list<string> $ids the rules just given to Claude
     */
    public function applied(array $ids, \DateTimeImmutable $at): self
    {
        $rules = $this->rules;

        foreach ($ids as $id) {
            $rules[$id] = $this->of($id)->applied($at);
        }

        return new self($rules);
    }

    public function blocked(string $id, \DateTimeImmutable $at): self
    {
        return new self([...$this->rules, $id => $this->of($id)->blocked($at)]);
    }
}
