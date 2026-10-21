<?php

namespace App\Listeners;

use App\Events\OrderPlaced;

final class NotifyWarehouse
{
    public function handle(OrderPlaced $event): void
    {
    }
}
