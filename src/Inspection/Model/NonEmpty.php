<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * @internal
 */
final class NonEmpty
{
    public static function string(string $value, string $what): string
    {
        $value = trim($value);

        if ('' === $value) {
            throw new InvalidModel(\sprintf('The %s cannot be empty.', $what));
        }

        return $value;
    }

    public static function slug(string $value, string $what): string
    {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $value)) {
            throw new InvalidModel(\sprintf('The %s "%s" must be lowercase letters, digits and hyphens, starting with a letter.', $what, $value));
        }

        return $value;
    }
}
