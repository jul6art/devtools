<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Order::create(['reference' => 'A-1']);
    }
}
