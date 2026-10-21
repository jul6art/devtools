<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

final class ImportOrders extends Command
{
    protected $signature = 'orders:import {file? : A CSV export}';

    protected $description = 'Imports the orders of a CSV export';

    public function handle(): int
    {
        Order::query()->count();

        return self::SUCCESS;
    }
}
