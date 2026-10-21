<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Assertion;

use Jul6Art\DevTools\Rule\Glob;

/**
 * One mechanical assertion (ADR-0027): the files it watches, how it checks them, what is wrong when it fails — and
 * the rule it enforces.
 */
final readonly class Assertion
{
    public const string ID = '/^assert-[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param non-empty-list<string> $watch project-relative globs
     */
    public function __construct(
        public string $id,
        public string $rule,
        public array $watch,
        public PatternCheck|DomCheck|ToolCheck $check,
        public string $message,
    ) {
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an assertion identifier: assert-<words>.', $id));
        }
    }

    /**
     * Those of these files it watches.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public function watched(array $files): array
    {
        return array_values(array_filter($files, fn (string $file): bool => array_any($this->watch, static fn (string $glob): bool => new Glob($glob)->matches($file))));
    }

    /**
     * `pattern`, `dom` or `tool`.
     */
    public function family(): string
    {
        return match (true) {
            $this->check instanceof PatternCheck => 'pattern',
            $this->check instanceof DomCheck => 'dom',
            default => 'tool',
        };
    }

    public function path(): string
    {
        return $this->id.'.xml';
    }
}
