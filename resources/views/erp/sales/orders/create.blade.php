@extends('erp.layouts.transaction')

@section('content')

    <x-transaction-header title="Create Sales Order" />

    <x-transaction-form
        type="sales_order"
        :customers="$customers"
        :warehouses="$warehouses"
        :products="$products"
        :quotation="$quotation ?? null"
        :quotations="$draftQuotations"
        action="{{ route('sales.orders.store') }}"
        method="POST"
    />

    @include('erp._partials.print-shortcut-form')

@endsection

@section('scripts')
    <script>
        $(document).ready(function() {
            const defaultId = '{{ $quotationId ?? '' }}';
            if (defaultId) {
                $('#quotation_select').val(defaultId).trigger('change');
            }
        });
    </script>
@endsection