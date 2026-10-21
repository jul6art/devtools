<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

/**
 * Reads a Blade template's literal references with regular expressions on its source (ADR-0030), the
 * pendant of {@see TwigReferenceExtractor}: the views it extends and includes, the components it renders,
 * the routes it links to. A reference built at runtime (`@include($view)`) is not seen.
 *
 * Views come out as paths under the views directory — `orders.index` is `orders/index.blade.php` — and
 * components as the classes Laravel resolves them to, which the graph locates like any other class.
 */
final class BladeReferenceExtractor
{
    private const string PARENT_PATTERN = '/@extends\(\s*([\'"])([^\'"]+)\1/';

    private const array VIEW_PATTERNS = [
        '/@(?:extends|include|includeIf|component|each)\(\s*([\'"])([^\'"]+)\1/',
        '/@includeWhen\([^,]+,\s*([\'"])([^\'"]+)\1/',
    ];

    private const string COMPONENT_PATTERN = '/<x-([a-z0-9][a-z0-9.\-]*)/';

    private const array LIVEWIRE_PATTERNS = ['/<livewire:([a-z0-9][a-z0-9.\-]*)/', '/@livewire\(\s*[\'"]([a-z0-9][a-z0-9.\-]*)[\'"]/'];

    private const string ROUTE_PATTERN = '/\broute\(\s*([\'"])([^\'"]+)\1/';

    /**
     * @var array<string, BladeReferences>
     */
    private array $parsed = [];

    public function __construct(private readonly string $applicationNamespace = 'App')
    {
    }

    /**
     * `orders.index` → `orders/index.blade.php`; a namespaced view (`mail::message`) belongs to a package.
     */
    public static function viewPath(string $name): ?string
    {
        return str_contains($name, '::') || '' === $name ? null : str_replace('.', '/', $name).'.blade.php';
    }

    public function extract(string $absolutePath): BladeReferences
    {
        if (isset($this->parsed[$absolutePath])) {
            return $this->parsed[$absolutePath];
        }

        $source = (string) file_get_contents($absolutePath);
        $views = [];

        foreach (self::VIEW_PATTERNS as $pattern) {
            $views = [...$views, ...self::matches($pattern, $source, 2)];
        }

        $classes = [];

        foreach (self::matches(self::COMPONENT_PATTERN, $source, 1) as $component) {
            // `<x-slot>` and `<x-dynamic-component>` are Blade's own.
            if (!\in_array($component, ['slot', 'dynamic-component'], true) && !str_starts_with($component, 'slot:')) {
                $classes[] = $this->className('View\Components', $component);
                $views[] = 'components.'.$component;
            }
        }

        foreach (self::LIVEWIRE_PATTERNS as $pattern) {
            foreach (self::matches($pattern, $source, 1) as $component) {
                $classes[] = $this->className('Livewire', $component);
            }
        }

        return $this->parsed[$absolutePath] = new BladeReferences(
            self::sorted(array_values(array_filter(array_map(self::viewPath(...), $views), \is_string(...)))),
            self::sorted($classes),
            self::sorted(self::matches(self::ROUTE_PATTERN, $source, 2)),
            self::sorted(array_values(array_filter(array_map(self::viewPath(...), self::matches(self::PARENT_PATTERN, $source, 2)), \is_string(...)))),
        );
    }

    /**
     * `forms.text-input` → `App\View\Components\Forms\TextInput`.
     */
    private function className(string $namespace, string $component): string
    {
        $segments = array_map(static fn (string $segment): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $segment))), explode('.', $component));

        return $this->applicationNamespace.'\\'.$namespace.'\\'.implode('\\', $segments);
    }

    /**
     * @return list<string>
     */
    private static function matches(string $pattern, string $source, int $group): array
    {
        preg_match_all($pattern, $source, $matches);

        return array_values($matches[$group]);
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
