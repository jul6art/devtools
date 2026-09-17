<?php

declare(strict_types=1);

namespace Jul6Art\DevTools\Clock;

/**
 * Now — or the instant given by SOURCE_DATE_EPOCH, the reproducible-builds convention, so that two runs
 * on an unchanged project can be compared byte for byte (ADR-0009, ADR-0015).
 */
final class SystemClock implements Clock
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        $epoch = getenv('SOURCE_DATE_EPOCH');

        return \is_string($epoch) && 1 === preg_match('/^\d+$/', $epoch) ? new \DateTimeImmutable('@'.$epoch)->setTimezone(new \DateTimeZone(date_default_timezone_get())) : new \DateTimeImmutable();
    }
}
