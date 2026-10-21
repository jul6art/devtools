<?php

namespace App\View\Components;

use Illuminate\View\Component;

final class Alert extends Component
{
    public function __construct(public string $type = 'info')
    {
    }

    public function render()
    {
        return view('components.alert');
    }
}
