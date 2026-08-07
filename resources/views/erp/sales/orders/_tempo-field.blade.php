{{-- Kesepakatan pembayaran TEMPO (bayar belakangan).
     Dicentang → pesanan boleh diproses & dikirim tanpa menunggu uang masuk: di Pemrosesan
     Pesanan ia melewati gerbang "Belum Bayar" maupun "Belum Lunas".
     Termin boleh dikosongkan (tempo tanpa batas waktu) — yang hilang hanya peringatan
     jatuh temponya. --}}
@php
    $isTempo   = (bool) old('is_tempo', $so?->is_tempo);
    $tempoDays = old('tempo_days', $so?->tempo_days);
@endphp

<div class="border-t border-gray-200 pt-3 mt-3">
    {{-- Hidden dulu supaya kotak yang dilepas centangnya tetap terkirim sebagai 0. --}}
    <input type="hidden" name="is_tempo" value="0">
    <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" name="is_tempo" id="is_tempo" value="1" {{ $isTempo ? 'checked' : '' }}
            class="mt-0.5 rounded border-gray-300 text-indigo-500 focus:ring-indigo-400">
        <span>
            <span class="block text-xs font-bold text-gray-600 uppercase tracking-wider">Pembayaran Tempo</span>
            <span class="block text-[11px] text-gray-400 mt-0.5">
                Barang boleh dikirim sebelum dibayar. Pesanan langsung masuk antrean kerja tanpa
                menunggu DP maupun pelunasan.
            </span>
        </span>
    </label>

    <div id="tempoDaysBlock" class="mt-2 pl-6 {{ $isTempo ? '' : 'hidden' }}">
        <div class="flex items-center gap-2">
            <span class="text-[11px] text-gray-500">Termin</span>
            <input type="text" name="tempo_days" id="tempo_days" inputmode="numeric"
                value="{{ $tempoDays !== null && $tempoDays !== '' ? $tempoDays : '' }}"
                placeholder="30"
                class="border border-gray-300 rounded px-2 py-1 w-20 text-right bg-white focus:ring-blue-500 focus:border-blue-500">
            <span class="text-[11px] text-gray-500">hari</span>
            <span id="tempoDueHint" class="text-[11px] text-gray-400"></span>
        </div>
    </div>
</div>

<script>
(function () {
    const cbEl   = document.getElementById('is_tempo');
    const block  = document.getElementById('tempoDaysBlock');
    const daysEl = document.getElementById('tempo_days');
    const hintEl = document.getElementById('tempoDueHint');
    if (!cbEl || !block || !daysEl) return;

    // Tanggal SO — jatuh tempo dihitung darinya, bukan dari hari ini, supaya angka yang
    // dilihat saat mengisi sama dengan yang nanti disimpan server.
    function orderDate() {
        const el = document.querySelector('input[name="order_date"], input[name="date"]');
        const v = el ? el.value : '';
        const d = v ? new Date(v) : new Date();
        return isNaN(d.getTime()) ? new Date() : d;
    }

    function updateHint() {
        const n = parseInt(String(daysEl.value).replace(/[^\d]/g, ''), 10);
        if (!n || n <= 0) {
            hintEl.textContent = 'kosong = tempo tanpa batas waktu';
            return;
        }
        const d = orderDate();
        d.setDate(d.getDate() + n);
        hintEl.textContent = '→ jatuh tempo ' + d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    cbEl.addEventListener('change', function () {
        block.classList.toggle('hidden', !cbEl.checked);
        if (cbEl.checked) updateHint();
    });
    daysEl.addEventListener('input', updateHint);

    updateHint();
})();
</script>
