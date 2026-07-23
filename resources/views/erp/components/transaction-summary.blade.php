<div class="grid grid-cols-2 gap-6 mt-6 mb-8">

    {{-- ══ LEFT: Catatan (Always Editable) ══ --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <label class="block font-semibold text-gray-700 text-sm">Catatan</label>
            @if(isset($updateNotesUrl))
                <span id="notes-status" class="text-xs text-gray-400 hidden"></span>
            @endif
        </div>

        @if(isset($updateNotesUrl))
            <textarea
                id="notes-input"
                rows="4"
                class="w-full border border-gray-200 rounded-lg p-3 text-sm text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-blue-300 resize-none transition"
                placeholder="Tulis catatan di sini... (Ctrl+Enter untuk menyimpan)">{{ ($notes ?? '') === '-' ? '' : ($notes ?? '') }}</textarea>

            <div class="flex items-center gap-3 mt-2">
                <button type="button"
                    id="btn-save-notes"
                    onclick="saveNotes('{{ $updateNotesUrl }}')"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition active:scale-95 shadow-sm">
                    Simpan
                </button>
                <span class="text-[11px] text-gray-400">atau tekan Ctrl+Enter</span>
            </div>
        @else
            <div class="border border-gray-200 rounded-lg p-3 min-h-[80px] bg-gray-50 text-gray-600 text-sm whitespace-pre-wrap">
                {{ $notes ?? '-' }}
            </div>
        @endif
    </div>

    {{-- ══ RIGHT: Summary Keuangan ══ --}}
    <div class="border p-4 rounded-lg bg-white shadow-sm text-sm">

        <div class="flex justify-between mb-2 text-gray-600">
            <span>Subtotal</span>
            <span class="font-medium">{{ number_format($subtotal) }}</span>
        </div>

        @if(($discountItem ?? 0) > 0)
        <div class="flex justify-between mb-2 text-gray-500">
            <span>Diskon Item</span>
            <span class="text-red-500">- {{ number_format($discountItem) }}</span>
        </div>
        @endif

        @if(($discountGlobal ?? 0) > 0)
        <div class="flex justify-between mb-2 text-gray-500">
            <span>Diskon Global</span>
            <span class="text-red-500">- {{ number_format($discountGlobal) }}</span>
        </div>
        @endif

        @if(($ppn ?? 0) > 0)
        <div class="flex justify-between mb-2 text-gray-600">
            <span>PPN</span>
            <span>{{ number_format($ppn) }}</span>
        </div>
        @endif

        @php
            $shipNet      = $shipping ?? 0;
            $shipDiscount = $shippingDiscount ?? 0;
            $shipGross    = ($shippingGross ?? 0) > 0 ? $shippingGross : $shipNet;
        @endphp
        @if($shipNet > 0 || $shipDiscount > 0)
        <div class="flex justify-between mb-2 text-gray-600">
            <span>Ongkos Kirim</span>
            <span>{{ number_format($shipDiscount > 0 ? $shipGross : $shipNet) }}</span>
        </div>
        @endif

        @if(($shipDiscount ?? 0) > 0)
        <div class="flex justify-between mb-2 text-gray-500">
            <span>Diskon Ongkir</span>
            <span class="text-red-500">- {{ number_format($shipDiscount) }}</span>
        </div>
        @endif

        @if(($expense ?? 0) > 0)
        <div class="flex justify-between mb-2 text-gray-600">
            <span>Biaya Lain</span>
            <span>{{ number_format($expense) }}</span>
        </div>
        @endif

        @if(($marketplaceFee ?? 0) > 0)
        <div class="flex justify-between mb-2 text-gray-500">
            <span>Biaya Admin Marketplace</span>
            <span class="text-red-500">- {{ number_format($marketplaceFee) }}</span>
        </div>
        @endif

        @if((int) ($uniqueCode ?? 0) !== 0)
        {{-- Positif = kode unik transfer (mengurangi). Negatif = selisih unik QRIS (menambah). --}}
        <div class="flex justify-between mb-2 text-gray-500">
            <span>
                {{ (int) $uniqueCode > 0 ? 'Kode Unik' : 'Penyesuaian QRIS' }}
                <span class="text-[10px] text-gray-400">({{ (int) $uniqueCode > 0 ? 'pembayaran transfer web' : 'nominal unik QRIS' }})</span>
            </span>
            <span class="{{ (int) $uniqueCode > 0 ? 'text-red-500' : 'text-gray-700' }}">
                {{ (int) $uniqueCode > 0 ? '- ' : '+ ' }}{{ number_format(abs((int) $uniqueCode)) }}
            </span>
        </div>
        @endif

        <hr class="my-3 border-gray-100">

        <div class="flex justify-between font-bold text-base text-gray-900">
            <span>Grand Total</span>
            <span>{{ number_format($grandTotal) }}</span>
        </div>

        @isset($advancePaid)
            <div class="flex justify-between mt-2 text-green-600 font-semibold text-sm">
                <span>Uang Muka</span>
                <span>{{ number_format($advancePaid) }}</span>
            </div>
        @endisset

        @isset($remaining)
            <hr class="my-2 border-gray-100">
            <div class="flex justify-between font-bold text-sm {{ ($remaining ?? 1) <= 0 ? 'text-green-600' : 'text-red-600' }}">
                <span>Sisa Pembayaran</span>
                <span>{{ number_format($remaining) }}</span>
            </div>
        @endisset

    </div>

</div>

@once
<script>
(function() {
    const textarea = document.getElementById('notes-input');
    if (!textarea) return;

    // Ctrl+Enter to save
    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.ctrlKey) {
            e.preventDefault();
            const btn = document.getElementById('btn-save-notes');
            if (btn) btn.click();
        }
    });
})();

async function saveNotes(url) {
    const textarea = document.getElementById('notes-input');
    const btnSave  = document.getElementById('btn-save-notes');
    const status   = document.getElementById('notes-status');
    if (!textarea || !btnSave) return;

    const notes       = textarea.value;
    const origLabel   = btnSave.textContent;
    btnSave.disabled  = true;
    btnSave.textContent = 'Menyimpan...';
    if (status) { status.textContent = ''; status.classList.add('hidden'); }

    try {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : ''
            },
            body: JSON.stringify({ notes })
        });

        let data = {};
        try { data = await response.json(); } catch(_) { data = { success: response.ok }; }

        if (response.ok && data.success !== false) {
            btnSave.textContent = 'Tersimpan ✔';
            btnSave.classList.remove('bg-blue-600', 'hover:bg-blue-700');
            btnSave.classList.add('bg-green-600');
            if (status) {
                status.textContent = 'Disimpan baru saja';
                status.className = 'text-xs text-green-500';
                status.classList.remove('hidden');
            }
            setTimeout(() => {
                btnSave.textContent = origLabel;
                btnSave.classList.add('bg-blue-600', 'hover:bg-blue-700');
                btnSave.classList.remove('bg-green-600');
                btnSave.disabled = false;
                if (status) status.classList.add('hidden');
            }, 2000);
        } else {
            throw new Error(data.message ?? 'Gagal menyimpan');
        }
    } catch(err) {
        btnSave.textContent = 'Error ❌';
        btnSave.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        btnSave.classList.add('bg-red-500');
        if (status) {
            status.textContent = err.message;
            status.className = 'text-xs text-red-500';
            status.classList.remove('hidden');
        }
        setTimeout(() => {
            btnSave.textContent = origLabel;
            btnSave.classList.add('bg-blue-600', 'hover:bg-blue-700');
            btnSave.classList.remove('bg-red-500');
            btnSave.disabled = false;
        }, 2500);
    }
}
</script>
@endonce