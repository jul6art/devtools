<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

/**
 * Reads a Twig template's literal references with regular expressions on its source — not with the Twig
 * engine, which DevTools does not require. A reference built at runtime (`{% include variable %}`) is not
 * seen, and the menu shows what it misses in "Non couvert".
 */
final class TwigReferenceExtractor
{
    private const string PARENT_PATTERN = '/\\{%-?\\s*extends\\s+([\'"])([^\'"]+)\\1/';

    private const array TEMPLATE_PATTERNS = [
        '/\{%-?\s*(?:extends|include|embed|use|import|from)\s+([\'"])([^\'"]+)\1/',
        '/\binclude\(\s*([\'"])([^\'"]+)\1/',
    ];

    private const string COMPONENT_PATTERN = '/\bcomponent\(\s*([\'"])([^\'"]+)\1/';

    private const string ROUTE_PATTERN = '/\b(?:path|url)\(\s*([\'"])([^\'"]+)\1/';

    /**
     * @var array<string, TwigReferences>
     */
    private array $parsed = [];

    public function extract(string $absolutePath): TwigReferences
    {
        if (isset($this->parsed[$absolutePath])) {
            return $this->parsed[$absolutePath];
        }

        $source = (string) file_get_contents($absolutePath);
        $templates = [];

        foreach (self::TEMPLATE_PATTERNS as $pattern) {
            $templates = [...$templates, ...self::matches($pattern, $source)];
        }

        return $this->parsed[$absolutePath] = new TwigReferences(
            self::sorted($templates),
            self::sorted(self::matches(self::COMPONENT_PATTERN, $source)),
            self::sorted(self::matches(self::ROUTE_PATTERN, $source)),
            self::sorted(self::matches(self::PARENT_PATTERN, $source)),
        );
    }

    /**
     * @return list<string>
     */
    private static function matches(string $pattern, string $source): array
    {
        preg_match_all($pattern, $source, $matches);

        return $matches[2];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, \SORT_STRING);

        return $values;
    }
}
