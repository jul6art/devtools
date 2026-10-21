@extends('layouts.app')

@section('content')
    @include('orders._row')
    <form method="post" action="{{ route('orders.store') }}">@csrf</form>
@endsection
