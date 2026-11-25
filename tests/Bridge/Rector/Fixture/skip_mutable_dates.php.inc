<?php

namespace App\Service;

final class SkipMutableDates
{
    /**
     * The clock gives immutable dates only: a mutable one is changed by hand, where its type is decided.
     */
    public function dates(): array
    {
        return [
            new \DateTime(),
            new \DateTime('today'),
            date_create(),
        ];
    }
}

?>
