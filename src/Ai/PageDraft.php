<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai;

use Jul6Art\DevTools\Rendering\PageSection;

/**
 * A page draft written by Claude: a front-matter, then the sections Claude owns (ADR-0011).
 */
final readonly class PageDraft
{
    public const string PRECONDITIONS = 'Préconditions';

    public const string CHANGE = 'Changement';

    /**
     * @param array<string, string>                            $metadata front-matter
     * @param array<string, array{content: string, line: int}> $sections title => content and line of the heading
     */
    public function __construct(public array $metadata, public array $sections)
    {
    }

    /**
     * The sections a draft must hold, in this order, and nothing else.
     *
     * @return list<string>
     */
    public static function expectedSections(): array
    {
        return [
            PageSection::Summary->value,
            self::PRECONDITIONS,
            PageSection::Journey->value,
            PageSection::Decisions->value,
            PageSection::Data->value,
            PageSection::CrossCutting->value,
            PageSection::Attention->value,
            self::CHANGE,
        ];
    }

    public static function parse(string $markdown): self
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $metadata = [];
        $start = 0;

        if ('---' === trim($lines[0] ?? '')) {
            for ($i = 1, $count = \count($lines); $i < $count && '---' !== trim($lines[$i]); ++$i) {
                if (1 === preg_match('/^([a-z-]+):\s*(.*)$/', $lines[$i], $matches)) {
                    $metadata[$matches[1]] = trim($matches[2]);
                }
            }

            $start = $i + 1;
        }

        // Headings and contents are collected apart, then joined: each keeps a precise type.
        $lineOf = [];
        $contentOf = [];
        $current = null;
        $inFence = false;

        foreach (\array_slice($lines, $start, null, true) as $index => $line) {
            if (str_starts_with($line, '```')) {
                $inFence = !$inFence;
            }

            if (!$inFence && str_starts_with($line, '## ')) {
                $current = trim(substr($line, 3));
                $lineOf[$current] = $index + 1;
                $contentOf[$current] = '';

                continue;
            }

            if (null !== $current) {
                $contentOf[$current] .= $line."\n";
            }
        }

        $sections = [];

        foreach ($lineOf as $title => $headingLine) {
            $sections[$title] = ['content' => trim($contentOf[$title]), 'line' => $headingLine];
        }

        return new self($metadata, $sections);
    }

    public function content(string $section): ?string
    {
        return $this->sections[$section]['content'] ?? null;
    }
}
