<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

/**
 * A glob of project-relative paths (ADR-0026): `*` and `?` never cross a `/`, `**` does, `{a,b}` is one or the
 * other; a `**` followed by a slash also stands for no directory at all.
 */
final readonly class Glob
{
    public string $regex;

    public function __construct(public string $pattern)
    {
        $this->regex = '#^'.self::compile($pattern).'$#';
    }

    public function matches(string $path): bool
    {
        return 1 === preg_match($this->regex, $path);
    }

    /**
     * The paths among these it matches.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function filter(array $paths): array
    {
        return array_values(array_filter($paths, $this->matches(...)));
    }

    /**
     * How much of it is written out rather than left to a wildcard: the longer, the more specific.
     */
    public function literal(): int
    {
        return \strlen((string) preg_replace('/\*+|\?|\{[^}]*\}/', '', $this->pattern));
    }

    private static function compile(string $pattern): string
    {
        $regex = '';
        $length = \strlen($pattern);

        for ($i = 0; $i < $length; ++$i) {
            $char = $pattern[$i];

            if ('*' === $char && '*' === ($pattern[$i + 1] ?? '')) {
                $slash = '/' === ($pattern[$i + 2] ?? '');
                $regex .= $slash ? '(?:.*/)?' : '.*';
                $i += $slash ? 2 : 1;
            } elseif ('*' === $char) {
                $regex .= '[^/]*';
            } elseif ('?' === $char) {
                $regex .= '[^/]';
            } elseif ('{' === $char && false !== ($end = strpos($pattern, '}', $i))) {
                $regex .= '(?:'.implode('|', array_map(self::compile(...), explode(',', substr($pattern, $i + 1, $end - $i - 1)))).')';
                $i = $end;
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return $regex;
    }
}
