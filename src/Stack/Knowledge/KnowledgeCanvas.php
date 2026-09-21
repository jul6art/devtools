<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack\Knowledge;

use Jul6Art\DevTools\Resources;

/**
 * The fixed canvas every knowledge file follows (`resources/knowledge/_canvas.md`, ADR-0012).
 */
final class KnowledgeCanvas
{
    public const array SECTIONS = [
        'Entry cycle',
        'Extension mechanisms',
        'Dependency injection and naming conventions',
        'Entry points by type',
        'Tests',
        'Known traps',
        'Sources',
    ];

    public static function path(): string
    {
        return Resources::path('knowledge/_canvas.md');
    }

    /**
     * @return list<string> what makes the file deviate from the canvas
     */
    public function problems(string $markdown): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $firstLine = trim((string) current(array_filter($lines, static fn (string $line): bool => '' !== trim($line))));
        $problems = str_starts_with($firstLine, '# ') ? [] : ['The file must start with its title: "# <Stack> <major version>".'];

        $sections = [];
        $current = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                $current = trim(substr($line, 3));
                $sections[$current] = '';
            } elseif (null !== $current) {
                $sections[$current] .= $line."\n";
            }
        }

        foreach (self::SECTIONS as $section) {
            if (!isset($sections[$section])) {
                $problems[] = \sprintf('The section "%s" is missing.', $section);
            } elseif ('' === trim($sections[$section]) || '—' === trim($sections[$section])) {
                $problems[] = \sprintf('The section "%s" is empty.', $section);
            }
        }

        foreach (array_keys($sections) as $section) {
            if (!\in_array($section, self::SECTIONS, true)) {
                $problems[] = \sprintf('The section "%s" is not part of the canvas.', $section);
            }
        }

        if ([] === $problems && self::SECTIONS !== array_keys($sections)) {
            $problems[] = 'The sections are not in the order of the canvas.';
        }

        if (isset($sections['Sources']) && 1 !== preg_match('#https?://\S+#', $sections['Sources'])) {
            $problems[] = 'The section "Sources" must list the documentation consulted, one URL per line.';
        }

        return array_values(array_unique($problems));
    }
}
