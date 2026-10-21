<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Jobs\SendOrderConfirmation;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OrderController extends Controller
{
    public function index(): View
    {
        return view('orders.index', ['orders' => Order::query()->latest()->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $order = Order::create($request->validate(['reference' => 'required|string|max:32']));

        OrderPlaced::dispatch($order);
        SendOrderConfirmation::dispatch($order);

        return redirect()->route('orders.show', $order);
    }

    public function show(Order $order): View
    {
        return view('orders.show', ['order' => $order]);
    }
}
