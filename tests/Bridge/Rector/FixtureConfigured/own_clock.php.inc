<?php

namespace App\Service;

final class Expiry
{
    public function deadline(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('+1 day');
    }
}

?>
-----
<?php

namespace App\Service;

final class Expiry
{
    public function deadline(): \DateTimeImmutable
    {
        return \App\Clock::now('+1 day');
    }
}

?>
