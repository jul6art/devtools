<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Glossary;

/**
 * One word of the project's vocabulary (ADR-0037): where the model meets it, and — once Claude defined it and
 * `workflows:apply` checked the definition — what it means here.
 */
final readonly class Term
{
    /**
     * @param list<string> $synonyms  the words the code or the pages use for the same thing
     * @param list<string> $workflows the workflows it appears in, sorted
     * @param list<string> $files     the files that declare it, sorted
     */
    public function __construct(
        public string $name,
        public TermStatus $status,
        public int $occurrences,
        public ?string $definition = null,
        public ?string $label = null,
        public array $synonyms = [],
        public array $workflows = [],
        public array $files = [],
    ) {
    }

    public static function key(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
    }

    /**
     * @param list<string> $workflows
     * @param list<string> $files
     */
    public function seenIn(array $workflows, array $files): self
    {
        return new self($this->name, $this->status, \count($workflows), $this->definition, $this->label, $this->synonyms, $workflows, $files);
    }

    /**
     * @param list<string> $synonyms
     */
    public function defined(string $definition, ?string $label, array $synonyms): self
    {
        return new self($this->name, TermStatus::Defined, $this->occurrences, $definition, $label, $synonyms, $this->workflows, $this->files);
    }
}
