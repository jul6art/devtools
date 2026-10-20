<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Graph;

use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;

/**
 * One argument of a PHP attribute, by name or by its position among the unnamed ones — the two ways
 * `#[Route('/x', name: 'y')]` and `#[Assert\When(expression: …)]` are written.
 *
 * @internal
 */
final class AttributeArgument
{
    /**
     * @param int $position among the unnamed arguments; -1 to accept the argument by name only
     */
    public static function of(Attribute $attribute, int $position, string $name): ?Expr
    {
        $index = 0;

        foreach ($attribute->args as $argument) {
            if (null !== $argument->name) {
                if ($name === $argument->name->toString()) {
                    return $argument->value;
                }

                continue;
            }

            if ($index++ === $position) {
                return $argument->value;
            }
        }

        return null;
    }
}
