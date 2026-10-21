<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\View;

final class AboutController extends Controller
{
    public function __invoke(): \Illuminate\Contracts\View\View
    {
        return View::make('about');
    }
}
