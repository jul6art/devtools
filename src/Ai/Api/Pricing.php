<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Ai\Api;

/**
 * What a run probably cost (ADR-0035): Anthropic's list prices per million tokens, as of 2026-10-06 — an
 * estimate for the report, never a bill. A model this table does not know is priced as unknown.
 */
final class Pricing
{
    /**
     * model => [input, output, cache read] in US dollars per million tokens; a cache write costs 1.25 × input.
     */
    private const array PRICES = [
        'claude-fable-5-1' => [10.0, 50.0, 0.25],
        'claude-fable-5' => [10.0, 50.0, 1.0],
        'claude-opus-5-5' => [4.0, 20.0, 0.20],
        'claude-opus-5' => [5.0, 25.0, 0.5],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.20],
        'claude-sonnet-5' => [2.0, 10.0, 0.2],
        'claude-haiku-5-5' => [0.10, 0.50, 0.01],
    ];

    /**
     * @param array{input: int, output: int, cacheRead: int, cacheWrite: int} $usage
     */
    public static function estimate(string $model, array $usage): ?float
    {
        if (!isset(self::PRICES[$model])) {
            return null;
        }

        [$input, $output, $cacheRead] = self::PRICES[$model];

        return ($usage['input'] * $input + $usage['output'] * $output + $usage['cacheRead'] * $cacheRead + $usage['cacheWrite'] * $input * 1.25) / 1_000_000;
    }
}
