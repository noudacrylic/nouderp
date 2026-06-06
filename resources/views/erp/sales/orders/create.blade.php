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

    @if(!empty($prefill))
    <script>
        // Prefill dari Cek Ongkir: pilih customer + set ongkir/dimensi/metode pengiriman.
        document.addEventListener('DOMContentLoaded', function () {
            const pf = @json($prefill);
            if (pf.customer_id && document.getElementById('customer_id')) {
                document.getElementById('customer_id').value = pf.customer_id;
                const cs = document.getElementById('customer_search');
                if (cs) cs.value = pf.customer_name || '';
            }
            const dm = document.getElementById('delivery_method');
            if (dm && pf.delivery_method) { dm.value = pf.delivery_method; dm.dispatchEvent(new Event('change')); }
            if (pf.weight_gram) { const w = document.getElementById('ship_weight'); if (w) w.value = pf.weight_gram; }
            // Muat alamat customer (area/koordinat) lalu set ongkir & dimensi terpilih.
            if (window.reloadShippingAddress) window.reloadShippingAddress();
            if (window.setShippingDetail) window.setShippingDetail(pf.shipping);
        });
    </script>
    @endif
@endsection