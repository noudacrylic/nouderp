@php
    /**
     * Field pilih Metode Pengiriman (Kurir / Ambil di Toko).
     * Param opsional:
     *   $model      → model dgn properti delivery_method (quotation/so/invoice)
     *   $formAttr   → nama form (untuk field di luar <form>, mis. 'transactionForm')
     *   $labelClass → kelas label agar selaras dgn header sekitarnya
     */
    $model      = $model ?? null;
    $formAttr   = $formAttr ?? null;
    $labelClass = $labelClass ?? 'block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1';
    $current    = old('delivery_method', $model->delivery_method ?? 'kurir');
@endphp

<div>
    <label class="{{ $labelClass }}">Metode Pengiriman</label>
    <select name="delivery_method" id="delivery_method" @if($formAttr) form="{{ $formAttr }}" @endif
        class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none transition">
        @foreach(\App\Modules\Sales\Models\SalesOrder::DELIVERY_METHODS as $val => $label)
            <option value="{{ $val }}" {{ $current == $val ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
</div>

@once
<script>
(function () {
    // Saat "Ambil di Toko" → ongkir dikunci 0 (pickup tidak ada ongkir kurir).
    function syncShipping() {
        var sel  = document.getElementById('delivery_method');
        var ship = document.getElementById('shipping');
        if (!sel || !ship) return;
        if (sel.value === 'ambil_toko') {
            ship.value = '0';
            ship.dataset.raw = 0;
            ship.readOnly = true;
            ship.classList.add('bg-gray-100');
        } else {
            ship.readOnly = false;
            ship.classList.remove('bg-gray-100');
        }
        if (typeof recalculate === 'function') recalculate();
    }
    document.addEventListener('DOMContentLoaded', function () {
        var sel = document.getElementById('delivery_method');
        if (!sel) return;
        sel.addEventListener('change', syncShipping);
        syncShipping();
    });
})();
</script>
@endonce
