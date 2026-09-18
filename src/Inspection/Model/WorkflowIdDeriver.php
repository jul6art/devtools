<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * Derives a workflow identifier from its main entry point, deterministically (table of ADR-0003).
 *
 * ⚠️ Every rule here is a contract with the projects using DevTools: changing how an identifier is
 * derived orphans their pages on the next scan, even with a green suite.
 */
final readonly class WorkflowIdDeriver
{
    /**
     * Transliteration without ext-intl, which DevTools does not require. Anything left outside
     * [a-z0-9] afterwards becomes a hyphen.
     */
    private const array TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a', 'Æ' => 'ae',
        'Ç' => 'c', 'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i', 'Ñ' => 'n',
        'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ø' => 'o', 'Œ' => 'oe',
        'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y',
    ];

    public function __construct(private string $routePrefix = 'app_')
    {
    }

    public function derive(WorkflowType $type, EntryPoint $entryPoint): WorkflowId
    {
        $segments = match ($type->name) {
            'routes' => self::split($this->withoutRoutePrefix($entryPoint->name), '/[_.]+/'),
            'commands' => self::split($entryPoint->name, '/[:.]+/'),
            'async' => [self::kebab($entryPoint->attributes['message'] ?? $entryPoint->name)],
            'ui' => [self::kebab($entryPoint->name)],
            default => self::split($entryPoint->name, '/[:_.\/]+/'),
        };

        $segments = array_values(array_filter(array_map(self::normaliseSegment(...), $segments), static fn (string $segment): bool => '' !== $segment));

        if ([] === $segments) {
            throw new InvalidModel(\sprintf('No workflow identifier can be derived from the %s entry point "%s"; declare an alias in .devtools/config.xml.', $entryPoint->kind, $entryPoint->name));
        }

        return new WorkflowId($type->idPrefix.'.'.implode('.', $segments));
    }

    private function withoutRoutePrefix(string $name): string
    {
        if ('' === $this->routePrefix || !str_starts_with($name, $this->routePrefix)) {
            return $name;
        }

        $stripped = substr($name, \strlen($this->routePrefix));

        // A route named exactly like the prefix keeps it rather than deriving nothing.
        return '' === trim($stripped, '_.') ? $name : $stripped;
    }

    /**
     * @return list<string>
     */
    private static function split(string $name, string $separators): array
    {
        return preg_split($separators, $name) ?: [$name];
    }

    /**
     * `App\Message\OrderCreated` → `order-created`, `HTTPCacheListener` → `http-cache-listener`.
     */
    private static function kebab(string $class): string
    {
        $short = ltrim(strrchr('\\'.$class, '\\') ?: $class, '\\');

        return preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $short) ?? $short;
    }

    private static function normaliseSegment(string $segment): string
    {
        $segment = strtolower(strtr($segment, self::TRANSLITERATION));
        $segment = preg_replace('/[^a-z0-9]+/', '-', $segment) ?? '';

        return trim($segment, '-');
    }
}
