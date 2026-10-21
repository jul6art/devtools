<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * One rule (ADR-0026): what came back twice, said once, in one to three sentences, where it applies.
 */
final readonly class Rule
{
    public const string ID = '/^rule-[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * A rule that needs a paragraph is two rules.
     */
    public const int SENTENCES_MAX = 3;

    /**
     * @param string                 $created   the date it was proposed, `Y-m-d`
     * @param non-empty-list<string> $origin    the feedback it was promoted from
     * @param string|null            $assertion the mechanical check that enforces it (ADR-0027)
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
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a rule identifier: rule-<words>.', $id));
        }
    }

    public function withStatus(RuleStatus $status): self
    {
        return new self($this->id, $status, $this->created, $this->origin, $this->appliesTo, $this->instruction, $this->assertion);
    }

    public function withAssertion(?string $assertion): self
    {
        return new self($this->id, $this->status, $this->created, $this->origin, $this->appliesTo, $this->instruction, $assertion);
    }

    /**
     * Where it is written, relative to `.devtools/rules/`.
     */
    public function path(): string
    {
        return $this->id.'.xml';
    }

    /**
     * The sentences of an instruction: an end of sentence is a `.`, `!` or `?` followed by a capital, or by
     * nothing — and a text that does not end with one still ends a sentence. "e.g. select2" is one sentence.
     */
    public static function sentences(string $instruction): int
    {
        $text = trim($instruction);

        if ('' === $text) {
            return 0;
        }

        $ends = preg_match_all('/[.!?…]+(?=\s+[^\s\p{Ll}]|\s*$)/u', $text);

        return (int) $ends + (1 === preg_match('/[.!?…]$/u', $text) ? 0 : 1);
    }
}
