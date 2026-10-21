@extends('layouts.app')

@section('content')
    <x-alert type="success"/>
    <livewire:cart-summary/>
    <a href="{{ route('orders.index') }}">Orders</a>
@endsection
