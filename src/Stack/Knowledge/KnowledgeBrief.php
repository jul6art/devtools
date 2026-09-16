<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

/**
 * What Claude needs to write the knowledge of a stack DevTools knows nothing about (ADR-0012).
 */
final readonly class KnowledgeBrief
{
    public function __construct(
        public string $key,
        public string $language,
        public ?string $framework,
        public ?string $version,
        public string $canvasPath,
        public string $promptPath,
        public string $draftPath,
    ) {
    }

    public static function fileName(string $key, string $kind): string
    {
        return \sprintf('knowledge.%s.%s', $key, $kind);
    }
}
