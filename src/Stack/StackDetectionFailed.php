<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Stack;

final class StackDetectionFailed extends \RuntimeException
{
    public static function malformedManifest(string $file, string $reason): self
    {
        return new self(\sprintf('The manifest "%s" cannot be read: %s', $file, $reason));
    }
}
