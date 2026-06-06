@extends('layouts.erp')

@section('content')


    <x-erp.document-layout>
        <x-slot:header>
            @include('erp.sales.invoices.partials.header')
        </x-slot>

        <x-slot:actions>
            @include('erp.sales.invoices.partials.actions')
        </x-slot>

        <x-slot:items>
            @include('erp.sales.invoices.partials.items')
        </x-slot>

        <x-slot:notes>
            @include('erp.sales.invoices.partials.notes')
        </x-slot>

        <x-slot:summary>
            @include('erp.sales.invoices.partials.summary')
        </x-slot>
    </x-erp.document-layout>

    @include('erp.sales.invoices.partials.shipping-info')

    @include('erp.sales._partials.relations-invoice')

    @include('erp.sales.payment._midtrans_modals')

@endsection