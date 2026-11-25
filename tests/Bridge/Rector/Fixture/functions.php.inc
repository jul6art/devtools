<?php

namespace App\Service;

use function Symfony\Component\Clock\now;

final class Functions
{
    public function read(int $microseconds): array
    {
        sleep(2);
        usleep($microseconds);

        return [
            time(),
            date('Y'),
            strtotime('+1 day'),
            date_create_immutable(),
            now('-1 hour'),
        ];
    }
}

?>
-----
<?php

namespace App\Service;

use function Symfony\Component\Clock\now;

final class Functions
{
    public function read(int $microseconds): array
    {
        \Jul6Art\CoreBundle\Util\Clock::sleep(2);
        \Jul6Art\CoreBundle\Util\Clock::sleep($microseconds / 1_000_000);

        return [
            \Jul6Art\CoreBundle\Util\Clock::timestamp(),
            \Jul6Art\CoreBundle\Util\Clock::now()->format('Y'),
            \Jul6Art\CoreBundle\Util\Clock::now('+1 day')->getTimestamp(),
            \Jul6Art\CoreBundle\Util\Clock::now(),
            \Jul6Art\CoreBundle\Util\Clock::now('-1 hour'),
        ];
    }
}

?>
