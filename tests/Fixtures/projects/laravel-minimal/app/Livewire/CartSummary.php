<?php

namespace App\Livewire;

use Livewire\Component;

final class CartSummary extends Component
{
    public int $items = 0;

    public function render()
    {
        return view('livewire.cart-summary');
    }
}
