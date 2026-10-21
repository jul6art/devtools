<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

/**
 * The project's glossary (ADR-0037), its terms by key — `Order`, `order` and `ORDER` are one term.
 */
final readonly class Glossary
{
    /**
     * @var array<string, Term>
     */
    public array $terms;

    /**
     * @param list<Term> $terms
     */
    public function __construct(array $terms = [])
    {
        $byKey = [];

        foreach ($terms as $term) {
            $byKey[Term::key($term->name)] = $term;
        }

        ksort($byKey, \SORT_STRING);
        $this->terms = $byKey;
    }

    public function get(string $name): ?Term
    {
        return $this->terms[Term::key($name)] ?? null;
    }

    /**
     * @return list<Term>
     */
    public function withStatus(TermStatus $status): array
    {
        return array_values(array_filter($this->terms, static fn (Term $term): bool => $term->status === $status));
    }

    /**
     * What this inspection found merged into what the project already decided: a definition and a status
     * survive, an `ignored` term is never proposed again, a candidate no longer met goes.
     *
     * @param array<string, array{name: string, workflows: list<string>, files: list<string>}> $collected
     */
    public function merge(array $collected): self
    {
        $terms = [];

        foreach ($collected as $key => $found) {
            $existing = $this->terms[$key] ?? null;
            $terms[$key] = ($existing ?? new Term($found['name'], TermStatus::Candidate, 0))->seenIn($found['workflows'], $found['files']);
        }

        foreach ($this->terms as $key => $term) {
            if (!isset($terms[$key]) && TermStatus::Candidate !== $term->status) {
                $terms[$key] = $term->seenIn([], []);
            }
        }

        return new self(array_values($terms));
    }

    public function with(Term $term): self
    {
        return new self(array_values([...$this->terms, Term::key($term->name) => $term]));
    }

    /**
     * The defined terms a workflow's page should use: those met in it.
     *
     * @return array<string, string> term => its definition
     */
    public function of(string $workflow): array
    {
        $terms = [];

        foreach ($this->withStatus(TermStatus::Defined) as $term) {
            if (\in_array($workflow, $term->workflows, true) && null !== $term->definition) {
                $terms[$term->name] = $term->definition;
            }
        }

        return $terms;
    }

    /**
     * The synonyms a text uses where the glossary has a term.
     *
     * @return list<array{string, string}> [synonym, term]
     */
    public function synonymsIn(string $text): array
    {
        $found = [];

        foreach ($this->withStatus(TermStatus::Defined) as $term) {
            foreach ($term->synonyms as $synonym) {
                if (1 === preg_match('/(?<![\p{L}\p{N}])'.preg_quote($synonym, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                    $found[] = [$synonym, $term->name];
                }
            }
        }

        return $found;
    }
}
