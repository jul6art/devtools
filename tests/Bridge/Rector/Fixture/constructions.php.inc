<?php

namespace App\Service;

final class Constructions
{
    public function dates(string $interval, int $days, \DateTimeZone $timezone): array
    {
        return [
            new \DateTimeImmutable(),
            new \DateTimeImmutable('now'),
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('-14 days', $timezone),
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')),
            new \DateTimeImmutable('-'.$interval),
            new \DateTimeImmutable(sprintf('-%d days', $days)),
        ];
    }
}

?>
-----
<?php

namespace App\Service;

final class Constructions
{
    public function dates(string $interval, int $days, \DateTimeZone $timezone): array
    {
        return [
            \Jul6Art\CoreBundle\Util\Clock::now(),
            \Jul6Art\CoreBundle\Util\Clock::now(),
            \Jul6Art\CoreBundle\Util\Clock::now('today'),
            \Jul6Art\CoreBundle\Util\Clock::now('-14 days', $timezone),
            \Jul6Art\CoreBundle\Util\Clock::now('now', new \DateTimeZone('Europe/Brussels')),
            \Jul6Art\CoreBundle\Util\Clock::now('-'.$interval),
            \Jul6Art\CoreBundle\Util\Clock::now(sprintf('-%d days', $days)),
        ];
    }
}

?>
