<?php

namespace App\Event;

/*
 * A default value, an attribute argument, a static variable and a constant are constant expressions: they may hold
 * a new, never a call. The clock cannot go there; the PHPStan rule reports the reading, done by hand (a null default).
 */
const BOOTED_AT = new \DateTimeImmutable();

#[\App\Attribute\Dated(new \DateTimeImmutable())]
final class Occurred
{
    public function __construct(
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }

    public function since(\DateTimeImmutable $from = new \DateTimeImmutable('-1 day')): \DateTimeImmutable
    {
        static $first = new \DateTimeImmutable();

        return $first < $from ? $first : $from;
    }
}

?>
