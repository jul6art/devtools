<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * Reads a workflow page back into its sections, ignoring headings inside fenced code blocks.
 */
final class PageParser
{
    public function parse(string $markdown): ParsedPage
    {
        $title = '';
        $header = '';
        $sections = [];
        $current = null;
        $inFence = false;

        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
            if (str_starts_with($line, '```')) {
                $inFence = !$inFence;
            }

            if (!$inFence && null === $current && '' === $title && str_starts_with($line, '# ')) {
                $title = substr($line, 2);

                continue;
            }

            if (!$inFence && str_starts_with($line, '## ')) {
                $current = substr($line, 3);
                $sections[$current] = '';

                continue;
            }

            if (null === $current) {
                if ('' === $header && '' !== trim($line)) {
                    $header = trim($line);
                }

                continue;
            }

            $sections[$current] .= $line."\n";
        }

        return new ParsedPage($title, $header, array_map(trim(...), $sections));
    }
}
