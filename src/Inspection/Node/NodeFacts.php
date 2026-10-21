<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Node;

/**
 * What the Node bridge read in each file (ADR-0031), as it read it: nothing here is interpreted. A literal
 * is a string, a number, a boolean, null, a list, a map — or a marker map saying what else it is:
 * `$identifier`, `$template` (its parts), `$function` (with the `$imports` it makes), `$import`, `$call`,
 * `$new`, `$expression`.
 */
final readonly class NodeFacts
{
    /**
     * @param array<string, array<string, mixed>>        $files  project path => facts
     * @param list<array{file: string, message: string}> $errors
     */
    public function __construct(public string $typescript, private array $files, public array $errors = [])
    {
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return array_keys($this->files);
    }

    /**
     * @return list<array{specifier: string, kind: string, resolved: string|null, package: string|null, names: array<string, string>}> names: local => imported
     */
    public function imports(string $file): array
    {
        $imports = [];

        foreach ($this->list($file, 'imports') as $import) {
            $specifier = self::string($import, 'specifier');

            if (null === $specifier) {
                continue;
            }

            $names = [];

            foreach (self::values($import, 'names') as $name) {
                $local = self::string($name, 'local');
                $imported = self::string($name, 'imported');

                if (null !== $local && null !== $imported) {
                    $names[$local] = $imported;
                }
            }

            $imports[] = ['specifier' => $specifier, 'kind' => self::string($import, 'kind') ?? 'static', 'resolved' => self::string($import, 'resolved'), 'package' => self::string($import, 'package'), 'names' => $names];
        }

        return $imports;
    }

    /**
     * Where a name a file uses comes from: the file it imports it from and the name it has there — or the
     * file itself, when it declares it.
     *
     * @return array{string, string}|null [project path, exported name]
     */
    public function origin(string $file, string $local): ?array
    {
        foreach ($this->imports($file) as $import) {
            if (isset($import['names'][$local]) && null !== $import['resolved']) {
                return [$import['resolved'], $import['names'][$local]];
            }
        }

        foreach ([...array_column($this->classes($file), 'name'), ...array_column($this->variables($file), 'name')] as $declared) {
            if ($declared === $local) {
                return [$file, $local];
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, target: string|null, member: string|null, arguments: list<mixed>}>
     */
    public function decorators(string $file): array
    {
        $decorators = [];

        foreach ($this->list($file, 'decorators') as $decorator) {
            $name = self::string($decorator, 'name');

            if (null !== $name) {
                $decorators[] = ['name' => $name, 'target' => self::string($decorator, 'target'), 'member' => self::string($decorator, 'member'), 'arguments' => self::values($decorator, 'arguments')];
            }
        }

        return $decorators;
    }

    /**
     * @return list<array{callee: string, arguments: list<mixed>, class: string|null, line: int}>
     */
    public function calls(string $file): array
    {
        $calls = [];

        foreach ($this->list($file, 'calls') as $call) {
            $callee = self::string($call, 'callee');

            if (null !== $callee) {
                $calls[] = ['callee' => $callee, 'arguments' => self::values($call, 'arguments'), 'class' => self::string($call, 'class'), 'line' => self::int($call, 'line')];
            }
        }

        return $calls;
    }

    /**
     * @return list<array{class: string, arguments: list<mixed>, line: int}>
     */
    public function news(string $file): array
    {
        $news = [];

        foreach ($this->list($file, 'news') as $new) {
            $class = self::string($new, 'class');

            if (null !== $class) {
                $news[] = ['class' => $class, 'arguments' => self::values($new, 'arguments'), 'line' => self::int($new, 'line')];
            }
        }

        return $news;
    }

    /**
     * @return list<array{name: string, type: string|null, exported: bool, value: mixed, line: int}>
     */
    public function variables(string $file): array
    {
        $variables = [];

        foreach ($this->list($file, 'variables') as $variable) {
            $name = self::string($variable, 'name');

            if (null !== $name && \is_array($variable)) {
                $variables[] = ['name' => $name, 'type' => self::string($variable, 'type'), 'exported' => true === ($variable['exported'] ?? false), 'value' => $variable['value'] ?? null, 'line' => self::int($variable, 'line')];
            }
        }

        return $variables;
    }

    /**
     * @return list<array{name: string, extends: string|null, implements: list<string>, injects: list<string>, properties: list<array{name: string, type: string|null, value: mixed}>, exported: bool}>
     */
    public function classes(string $file): array
    {
        $classes = [];

        foreach ($this->list($file, 'classes') as $class) {
            $name = self::string($class, 'name');

            if (null === $name || !\is_array($class)) {
                continue;
            }

            $properties = [];

            foreach (self::values($class, 'properties') as $property) {
                $propertyName = self::string($property, 'name');

                if (null !== $propertyName && \is_array($property)) {
                    $properties[] = ['name' => $propertyName, 'type' => self::string($property, 'type'), 'value' => $property['value'] ?? null];
                }
            }

            $classes[] = [
                'name' => $name,
                'extends' => self::string($class, 'extends'),
                'implements' => self::strings($class, 'implements'),
                'injects' => self::strings($class, 'injects'),
                'properties' => $properties,
                'exported' => true === ($class['exported'] ?? false),
            ];
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    public function exports(string $file): array
    {
        return array_values(array_filter($this->list($file, 'exports'), \is_string(...)));
    }

    /**
     * A literal read as a string: itself, or the text of a template whose parts are all literal; null when
     * anything in it is computed.
     */
    public static function text(mixed $literal): ?string
    {
        if (\is_string($literal)) {
            return $literal;
        }

        if (!\is_array($literal) || !\is_array($literal['$template'] ?? null)) {
            return null;
        }

        $text = '';

        foreach ($literal['$template'] as $part) {
            if (!\is_string($part)) {
                return null;
            }

            $text .= $part;
        }

        return $text;
    }

    /**
     * The name a `$identifier` literal holds, or null.
     */
    public static function identifier(mixed $literal): ?string
    {
        return \is_array($literal) && \is_string($literal['$identifier'] ?? null) ? $literal['$identifier'] : null;
    }

    /**
     * @return list<mixed>
     */
    private function list(string $file, string $query): array
    {
        $facts = $this->files[$file][$query] ?? [];

        return \is_array($facts) ? array_values($facts) : [];
    }

    private static function string(mixed $data, string $key): ?string
    {
        $value = \is_array($data) ? ($data[$key] ?? null) : null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function int(mixed $data, string $key): int
    {
        $value = \is_array($data) ? ($data[$key] ?? null) : null;

        return \is_int($value) ? $value : 0;
    }

    /**
     * @return list<mixed>
     */
    private static function values(mixed $data, string $key): array
    {
        $value = \is_array($data) ? ($data[$key] ?? null) : null;

        return \is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $data, string $key): array
    {
        return array_values(array_filter(self::values($data, $key), \is_string(...)));
    }
}
