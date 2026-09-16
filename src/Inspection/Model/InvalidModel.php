<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Inspection\Model;

/**
 * An invariant of the intermediate model was violated.
 *
 * Thrown at construction, never later: an object of the model that exists is a valid one, so no
 * renderer, writer or validator further down the pipeline has to check again.
 */
final class InvalidModel extends \InvalidArgumentException
{
}
