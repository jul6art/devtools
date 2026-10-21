<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Rule;

final class RuleNotFound extends \RuntimeException
{
    public static function id(string $id): self
    {
        return new self(\sprintf('No rule "%s": rules:list gives the identifiers.', $id));
    }
}
