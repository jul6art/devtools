<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Bridge\PHPStan;

/**
 * Whether a class-like declaration is longer than a project accepts (ADR-0054).
 *
 * The decision lives here, without PHPStan: {@see ClassLengthRule} only hands it what the node says.
 *
 * ⚠️ **The message never carries the number of lines**, the tip does. A project freezes its long classes in a
 * PHPStan baseline, and a baseline compares messages: with the count in it, every line added to or removed from a
 * frozen class would push it out of the list — even when the class gets shorter.
 */
final readonly class ClassLength
{
    public const int DEFAULT_MAX = 500;

    /**
     * An entity carries its columns, fixtures carry their data: neither is a class that does too much.
     *
     * @var list<string>
     */
    public const array DEFAULT_EXCLUDE = ['*/Entity/*', '*/DataFixtures/*'];

    /**
     * @param list<string> $exclude fnmatch() patterns on the path of the file
     */
    public function __construct(
        private int $max = self::DEFAULT_MAX,
        private array $exclude = self::DEFAULT_EXCLUDE,
    ) {
    }

    /**
     * @param int $firstLine the first line of the declaration — its docblock, or its first attribute, when it has one
     *
     * @return array{message: string, tip: string}|null null when the declaration is short enough, or its file excluded
     */
    public function of(string $class, string $file, int $firstLine, int $lastLine): ?array
    {
        foreach ($this->exclude as $pattern) {
            if (fnmatch($pattern, $file)) {
                return null;
            }
        }

        $lines = $lastLine - $firstLine + 1;

        if ($lines <= $this->max) {
            return null;
        }

        return [
            'message' => \sprintf('Class %s is longer than %d lines.', $class, $this->max),
            'tip' => \sprintf('%d lines from its docblock to its closing brace: split what it does into classes of their own.', $lines),
        ];
    }
}
