<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Adapter\Claude;

/**
 * What Claude needs to find the workflows of a stack (ADR-0013).
 */
final readonly class DiscoveryBrief
{
    /**
     * @param list<string> $sourceDirectories relative to the stack root
     * @param list<string> $excludes
     * @param list<string> $types             workflow types in force
     * @param list<string> $limitedTo         the only files to look at; empty for a whole discovery
     */
    public function __construct(
        public string $slug,
        public string $root,
        public array $sourceDirectories,
        public array $excludes,
        public ?string $knowledgePath,
        public array $types,
        public string $schemaPath,
        public string $promptPath,
        public array $limitedTo,
        public string $draftPath,
    ) {
    }

    public static function fileName(string $slug, string $kind): string
    {
        return \sprintf('discovery.%s.%s', $slug, $kind);
    }
}
