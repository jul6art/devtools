<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

/**
 * What Claude needs to write the knowledge of a stack DevTools knows nothing about (ADR-0012) — or to
 * complete a sheet a project showed to be incomplete (ADR-0044).
 */
final readonly class KnowledgeBrief
{
    public const string CREATE = 'create';

    public const string AMEND = 'amend';

    /**
     * @param string       $kind           {@see self::CREATE} a new sheet, {@see self::AMEND} the whole sheet rewritten
     * @param string|null  $currentPath    the sheet being amended, relative to the project
     * @param list<string> $uncovered      files the last inspection could attach to no workflow
     * @param list<string> $fallbacks      what a native adapter could not ask the framework, in words
     * @param list<string> $withoutAdapter roots of the stacks of this key that no native adapter reads
     */
    public function __construct(
        public string $key,
        public string $language,
        public ?string $framework,
        public ?string $version,
        public string $canvasPath,
        public string $promptPath,
        public string $draftPath,
        public string $kind = self::CREATE,
        public ?string $currentPath = null,
        public array $uncovered = [],
        public array $fallbacks = [],
        public array $withoutAdapter = [],
    ) {
    }

    public function amends(): bool
    {
        return self::AMEND === $this->kind;
    }

    public static function fileName(string $key, string $kind): string
    {
        return \sprintf('knowledge.%s.%s', $key, $kind);
    }

    /**
     * Named apart from the briefs the inspection writes, which it removes at every run: an amendment is
     * asked for by someone, and stays until it is written.
     */
    public static function amendmentFileName(string $key, string $kind): string
    {
        return \sprintf('knowledge-amend.%s.%s', $key, $kind);
    }
}
