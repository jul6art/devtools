<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rendering;

/**
 * Markdown built line by line, without a template engine. Every value coming from the analysed code is
 * escaped where it lands: a `|` in a route breaks a table, a backtick in a name breaks inline code.
 */
final class MarkdownWriter
{
    public const string EMPTY = '—';

    public static function code(string $value): string
    {
        return str_contains($value, '`') ? '``'.$value.'``' : '`'.$value.'`';
    }

    /**
     * @param list<string> $cells already formatted; their pipes are escaped here
     */
    public static function row(array $cells): string
    {
        return '| '.implode(' | ', array_map(static fn (string $cell): string => str_replace(['|', "\n"], ['\|', ' '], $cell), $cells)).' |';
    }

    /**
     * @param list<string>       $header
     * @param list<list<string>> $rows
     */
    public static function table(array $header, array $rows): string
    {
        if ([] === $rows) {
            return self::EMPTY;
        }

        return implode("\n", [
            self::row($header),
            '|'.str_repeat('---|', \count($header)),
            ...array_map(self::row(...), $rows),
        ]);
    }

    public static function link(string $label, string $target): string
    {
        return '['.str_replace(['[', ']'], ['\[', '\]'], $label).']('.str_replace([' ', ')'], ['%20', '%29'], $target).')';
    }
}
