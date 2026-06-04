@extends('layouts.erp')

@section('content')

    <x-erp.document-layout>
        <x-slot:header>
            @include('erp.sales.orders.partials.header')
        </x-slot>

        <x-slot:actions>
            @include('erp.sales.orders.partials.actions', ['showText' => true])
        </x-slot>

        <x-slot:items>
            @include('erp.sales.orders.partials.items')
        </x-slot>

        <x-slot:notes>
            {{-- intentionally blank, notes rendered inside summary --}}
        </x-slot>

        <x-slot:summary>
            @include('erp.sales.orders.partials.summary')
        </x-slot>
    </x-erp.document-layout>

    @include('erp.sales.orders.partials.pickup')

    @include('erp.sales._partials.relations-so')

    @if($so->status !== 'void')
        @include('erp._partials.print-shortcut', ['printUrl' => route('sales.orders.print', $so->id)])
    @endif

    @if($so->status === 'confirmed')
        @include('erp.sales.payment._midtrans_modals')
    @endif

@endsection