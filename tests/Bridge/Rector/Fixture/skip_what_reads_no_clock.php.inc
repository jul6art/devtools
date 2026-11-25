<?php

namespace App\Service;

final class SkipWhatReadsNoClock
{
    public function convert(string $value, int $timestamp, int $year, array $arguments): array
    {
        return [
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('@'.$timestamp),
            new \DateTimeImmutable($value),
            new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year)),
            new \DateTimeImmutable(datetime: 'now'),
            new \DateTimeImmutable(...$arguments),
            date('Y', $timestamp),
            strtotime($value),
            gmdate('Y'),
            mktime(0, 0, 0),
            time(...),
        ];
    }
}

?>
