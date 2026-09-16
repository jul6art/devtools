<?php

declare(strict_types=1);

namespace Acme;

final class Mailer
{
    public function send(string $to, string $body): void
    {
        mail($to, 'Acme', $body);
    }
}
