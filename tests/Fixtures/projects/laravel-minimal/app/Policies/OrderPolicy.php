<?php

namespace App\Policies;

use App\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;

final class OrderPolicy
{
    public function view(Authenticatable $user, Order $order): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }
}
