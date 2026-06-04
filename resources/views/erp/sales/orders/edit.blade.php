@extends('erp.layouts.transaction')

@section('content')

    <x-transaction-header title="Edit Sales Order" />

    <x-transaction-form type="sales_order" :customers="$customers" :warehouses="$warehouses" :products="$products" :so="$so"
        action="{{ route('sales.orders.update', $so->id) }}" method="PUT" />

    @include('erp._partials.print-shortcut-form')

@endsection