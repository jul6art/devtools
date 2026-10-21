<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering\Site;

/**
 * The Markdown of DevTools' pages as HTML (ADR-0034): headings, paragraphs, lists, tables, quotes, fenced code
 * — a ```mermaid block becoming a diagram the site renders — inline code, emphasis and links. No raw HTML goes
 * through: a page holds prose Claude wrote, and the site escapes it all.
 */
final readonly class MarkdownRenderer
{
    /**
     * @param \Closure(string): ?string $link what a link's target becomes in the site; null to keep its text only
     */
    public function __construct(private \Closure $link)
    {
    }

    public function render(string $markdown): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", (string) preg_replace('/<!--.*?-->/s', '', $markdown)));
        $html = [];
        $paragraph = [];
        $count = \count($lines);

        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];

            if (1 === preg_match('/^```\s*([\w-]*)\s*$/', $line, $fence)) {
                $this->flush($paragraph, $html);
                $code = [];

                for (++$i; $i < $count && 1 !== preg_match('/^```\s*$/', $lines[$i]); ++$i) {
                    $code[] = $lines[$i];
                }

                $content = self::escape(implode("\n", $code));
                $html[] = 'mermaid' === $fence[1] ? '<pre class="mermaid">'.$content.'</pre>' : '<pre><code'.('' === $fence[1] ? '' : ' class="language-'.self::escape($fence[1]).'"').'>'.$content.'</code></pre>';

                continue;
            }

            if ('' === trim($line)) {
                $this->flush($paragraph, $html);

                continue;
            }

            if (1 === preg_match('/^(#{1,6})\s+(.*)$/', $line, $heading)) {
                $this->flush($paragraph, $html);
                $level = \strlen($heading[1]);
                $html[] = \sprintf('<h%d id="%s">%s</h%1$d>', $level, self::slug($heading[2]), $this->inline($heading[2]));

                continue;
            }

            if (1 === preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) {
                $this->flush($paragraph, $html);
                $html[] = '<hr>';

                continue;
            }

            if (str_starts_with(ltrim($line), '|') && 1 === preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$i + 1] ?? '')) {
                $this->flush($paragraph, $html);
                $rows = [self::cells($line)];

                for ($i += 2; $i < $count && str_starts_with(ltrim($lines[$i]), '|'); ++$i) {
                    $rows[] = self::cells($lines[$i]);
                }

                --$i;
                $html[] = $this->table($rows);

                continue;
            }

            if (1 === preg_match('/^\s*([-*]|\d+\.)\s+/', $line, $marker)) {
                $this->flush($paragraph, $html);
                $ordered = ctype_digit(rtrim($marker[1], '.'));
                $items = [];

                for (; $i < $count; ++$i) {
                    if (1 === preg_match('/^\s*(?:[-*]|\d+\.)\s+(.*)$/', $lines[$i], $item)) {
                        $items[] = $item[1];
                    } elseif ('' !== trim($lines[$i]) && str_starts_with($lines[$i], '  ') && [] !== $items) {
                        $items[\count($items) - 1] .= ' '.trim($lines[$i]);
                    } else {
                        break;
                    }
                }

                --$i;
                $tag = $ordered ? 'ol' : 'ul';
                $html[] = '<'.$tag.'>'.implode('', array_map(fn (string $item): string => '<li>'.$this->inline($item).'</li>', $items)).'</'.$tag.'>';

                continue;
            }

            if (str_starts_with($line, '>')) {
                $this->flush($paragraph, $html);
                $quote = [];

                for (; $i < $count && str_starts_with($lines[$i], '>'); ++$i) {
                    $quote[] = ltrim(substr($lines[$i], 1));
                }

                --$i;
                $html[] = '<blockquote><p>'.$this->inline(implode(' ', $quote)).'</p></blockquote>';

                continue;
            }

            $paragraph[] = $line;
        }

        $this->flush($paragraph, $html);

        return implode("\n", $html)."\n";
    }

    /**
     * @param list<string> $paragraph the lines of the paragraph being read, emptied
     * @param list<string> $html
     */
    private function flush(array &$paragraph, array &$html): void
    {
        if ([] !== $paragraph) {
            $html[] = '<p>'.$this->inline(implode(' ', array_map(trim(...), $paragraph))).'</p>';
            $paragraph = [];
        }
    }

    /**
     * The first heading of a page, as text.
     */
    public static function title(string $markdown): ?string
    {
        return 1 === preg_match('/^#\s+(.+)$/m', $markdown, $title) ? trim(str_replace('`', '', $title[1])) : null;
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /**
     * Inline Markdown: links first — their text may hold code — then code spans, inside which nothing is
     * Markdown, then emphasis.
     */
    private function inline(string $text, bool $links = true): string
    {
        $anchors = [];

        if ($links) {
            $text = (string) preg_replace_callback('/\[((?:[^\[\]`]|`[^`]*`)+)\]\(([^)\s]+)\)/', function (array $match) use (&$anchors): string {
                $target = ($this->link)($match[2]);
                $anchors[] = null === $target ? $this->inline($match[1], false) : '<a href="'.self::escape($target).'">'.$this->inline($match[1], false).'</a>';

                return "\u{1}".(\count($anchors) - 1)."\u{1}";
            }, $text);
        }

        $parts = preg_split('/(`+)(.+?)\1/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';

        for ($i = 0, $count = \count($parts); $i < $count; ++$i) {
            if (1 === $i % 3) {
                $html .= '<code>'.self::escape(trim($parts[$i + 1])).'</code>';
                ++$i;

                continue;
            }

            $html .= self::emphasis(self::escape($parts[$i]));
        }

        return (string) preg_replace_callback("/\u{1}(\\d+)\u{1}/", static fn (array $match): string => $anchors[(int) $match[1]] ?? '', $html);
    }

    private static function emphasis(string $escaped): string
    {
        $bold = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);

        return (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $bold);
    }

    /**
     * @param list<list<string>> $rows
     */
    private function table(array $rows): string
    {
        $head = array_shift($rows) ?? [];
        $html = '<table><thead><tr>'.implode('', array_map(fn (string $cell): string => '<th>'.$this->inline($cell).'</th>', $head)).'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'.implode('', array_map(fn (string $cell): string => '<td>'.$this->inline($cell).'</td>', $row)).'</tr>';
        }

        return $html.'</tbody></table>';
    }

    /**
     * `| a | b \| c |` → ['a', 'b | c'].
     *
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = str_starts_with($line, '|') ? substr($line, 1) : $line;
        $line = str_ends_with($line, '|') && !str_ends_with($line, '\|') ? substr($line, 0, -1) : $line;

        return array_map(static fn (string $cell): string => trim(str_replace('\|', '|', $cell)), preg_split('/(?<!\\\\)\|/', $line) ?: []);
    }

    private static function slug(string $heading): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags($heading))), '-');
    }
}
