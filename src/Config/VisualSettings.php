<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Config;

/**
 * How a capture is compared with its baseline (ADR-0022): the share of pixels that may differ, and what is
 * hidden before the capture because it changes on every run — a date, an avatar.
 */
final readonly class VisualSettings
{
    /**
     * Percent of the pixels.
     */
    public const float DEFAULT_THRESHOLD = 0.1;

    /**
     * @param list<string> $masks CSS selectors
     */
    public function __construct(public float $threshold = self::DEFAULT_THRESHOLD, public array $masks = [])
    {
        if ($threshold < 0 || $threshold > 100) {
            throw new InvalidConfig(\sprintf('The visual threshold is a percentage of the pixels, between 0 and 100: %s given.', $threshold));
        }
    }
}
