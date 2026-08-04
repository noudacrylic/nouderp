{{-- Kesepakatan batas minimal DP untuk SO ini.
     Bawaan 50% dari grand total; diturunkan di sini bila ada kesepakatan lain dengan
     pelanggan. Nilainya dipakai otomatis oleh tautan pembayaran DP (Midtrans).
     Dua kotak saling menghitung — yang terakhir diubah itulah yang disimpan. --}}
@php
    $mdPercent = old('min_dp_percent', $so?->min_dp_percent);
    $mdAmount  = old('min_dp_amount', $so?->min_dp_amount);
    $mdBasis   = old('min_dp_basis', $so && $so->min_dp_amount !== null && $so->min_dp_percent === null ? 'nominal' : 'percent');
@endphp

<div class="border-t border-gray-200 pt-3 mt-3" id="minDpBlock">
    <div class="flex items-center justify-between">
        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Minimal DP</span>
        <span class="text-[11px] text-gray-400">bawaan {{ \App\Modules\Sales\Models\SalesOrder::DEFAULT_MIN_DP_PERCENT }}%</span>
    </div>

    <div class="flex gap-2 mt-2">
        <div class="flex-1">
            <div class="flex items-center gap-1">
                <input type="text" name="min_dp_percent" id="min_dp_percent" inputmode="decimal"
                    value="{{ $mdPercent !== null && $mdPercent !== '' ? rtrim(rtrim(number_format((float) $mdPercent, 2, ',', '.'), '0'), ',') : '' }}"
                    placeholder="{{ \App\Modules\Sales\Models\SalesOrder::DEFAULT_MIN_DP_PERCENT }}"
                    class="border border-gray-300 rounded px-2 py-1 w-full text-right bg-white focus:ring-blue-500 focus:border-blue-500">
                <span class="text-xs text-gray-500">%</span>
            </div>
        </div>
        <div class="flex-1">
            <div class="flex items-center gap-1">
                <span class="text-xs text-gray-500">Rp</span>
                <input type="text" name="min_dp_amount" id="min_dp_amount" inputmode="numeric"
                    value="{{ $mdAmount !== null && $mdAmount !== '' ? number_format((float) $mdAmount, 0, ',', '.') : '' }}"
                    class="rupiah-input border border-gray-300 rounded px-2 py-1 w-full text-right bg-white focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>
    </div>

    <input type="hidden" name="min_dp_basis" id="min_dp_basis" value="{{ $mdBasis }}">
    <p class="text-[11px] text-gray-400 mt-1" id="min_dp_hint">
        Kosongkan untuk memakai bawaan {{ \App\Modules\Sales\Models\SalesOrder::DEFAULT_MIN_DP_PERCENT }}%. Isi <b>0</b> bila DP boleh berapa pun.
    </p>
</div>

<script>
(function () {
    const pctEl = document.getElementById('min_dp_percent');
    const rpEl = document.getElementById('min_dp_amount');
    const basisEl = document.getElementById('min_dp_basis');
    const hintEl = document.getElementById('min_dp_hint');
    if (!pctEl || !rpEl || !basisEl) return;

    const num = (v) => (window.cleanNumber ? window.cleanNumber(v) : (parseFloat(String(v ?? '').replace(/[^\d.-]/g, '')) || 0));
    const fmt = (n) => Math.round(n).toLocaleString('id-ID');
    const kosong = (el) => String(el.value ?? '').trim() === '';

    // Grand total dibaca dari ringkasan yang sudah dihitung recalculate().
    function grandTotal() {
        const hidden = document.getElementById('grand_total_input');
        return hidden ? num(hidden.value) : 0;
    }

    // Sinkronisasi satu arah sesuai kotak yang sedang diketik, supaya tidak saling
    // menimpa sementara angkanya belum selesai diketik.
    function fromPercent() {
        basisEl.value = 'percent';
        if (kosong(pctEl)) { rpEl.value = ''; return updateHint(); }
        const total = grandTotal();
        rpEl.value = total > 0 ? fmt(total * num(pctEl.value) / 100) : '';
        updateHint();
    }

    function fromAmount() {
        basisEl.value = 'nominal';
        if (kosong(rpEl)) { pctEl.value = ''; return updateHint(); }
        const total = grandTotal();
        pctEl.value = total > 0 ? String(Math.round(num(rpEl.value) / total * 1000) / 10).replace('.', ',') : '';
        updateHint();
    }

    function updateHint() {
        if (!hintEl) return;
        const total = grandTotal();
        if (kosong(pctEl) && kosong(rpEl)) {
            hintEl.innerHTML = 'Kosongkan untuk memakai bawaan {{ \App\Modules\Sales\Models\SalesOrder::DEFAULT_MIN_DP_PERCENT }}%. Isi <b>0</b> bila DP boleh berapa pun.';
            return;
        }
        const rp = num(rpEl.value);
        hintEl.innerHTML = rp > 0
            ? `Pembeli minimal membayar <b>Rp ${fmt(rp)}</b> dari total Rp ${fmt(total)}.`
            : 'Tanpa batas minimal — pembeli boleh membayar berapa pun sebagai DP.';
    }

    pctEl.addEventListener('input', fromPercent);
    rpEl.addEventListener('input', fromAmount);

    // Total berubah (item/diskon/ongkir diubah) → nominal ikut menyesuaikan bila
    // kesepakatannya persentase. Kalau yang disepakati angka rupiah, biarkan tetap.
    document.addEventListener('erp:recalculated', function () {
        if (basisEl.value === 'percent' && !kosong(pctEl)) fromPercent();
        else updateHint();
    });

    updateHint();
})();
</script>
