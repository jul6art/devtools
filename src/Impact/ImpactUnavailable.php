<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Impact;

final class ImpactUnavailable extends \RuntimeException
{
    public static function noMap(string $file): self
    {
        return new self(\sprintf('No impact map in %s: run workflows:inspect first.', $file));
    }
}
