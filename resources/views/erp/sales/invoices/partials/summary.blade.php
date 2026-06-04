<div class="grid grid-cols-2 gap-6 mt-6 mb-4">

    {{-- ══ LEFT: Catatan (Always Editable) ══ --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <label class="block font-semibold text-gray-700 text-sm">Catatan</label>
            <span id="inv-notes-status" class="text-xs text-gray-400 hidden"></span>
        </div>

        <textarea
            id="inv-notes-input"
            rows="4"
            class="w-full border border-gray-200 rounded-lg p-3 text-sm text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-blue-300 resize-none transition"
            placeholder="Tulis catatan di sini... (Ctrl+Enter untuk menyimpan)">{{ $invoice->notes ?? '' }}</textarea>

        <div class="flex items-center gap-3 mt-2">
            <button type="button"
                id="inv-btn-save-notes"
                onclick="saveInvNotes('{{ route('sales.invoices.update-notes', $invoice->id) }}')"
                class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg transition active:scale-95 shadow-sm">
                Simpan
            </button>
            <span class="text-[11px] text-gray-400">atau tekan Ctrl+Enter</span>
        </div>
    </div>

    {{-- ══ RIGHT: Summary Keuangan ══ --}}
    <div>
        {{-- Payment Status Badge --}}
        @if($invoice->status !== 'draft')
        <div class="mb-3">
            @if($invoice->remaining_amount <= 0)
                <div class="bg-green-50 text-green-700 p-3 rounded-lg text-center font-bold border border-green-200 text-sm">
                    ✅ LUNAS
                </div>
            @else
                <div class="bg-red-50 text-red-700 p-3 rounded-lg text-center font-bold border border-red-200 text-sm">
                    ❌ BELUM LUNAS — Sisa: Rp {{ number_format($invoice->remaining_amount, 0) }}
                </div>
            @endif
        </div>
        @endif

        <div class="border rounded-lg p-4 bg-white shadow-sm text-sm">
            <div class="flex justify-between mb-2 text-gray-600">
                <span>Subtotal</span>
                <span class="font-medium">{{ number_format($invoice->subtotal) }}</span>
            </div>

            @if($invoice->global_discount_amount > 0)
            <div class="flex justify-between mb-2 text-gray-500">
                <span>Diskon Global</span>
                <span class="text-red-500">- {{ number_format($invoice->global_discount_amount) }}</span>
            </div>
            @endif

            @if($invoice->ppn_amount > 0)
            <div class="flex justify-between mb-2 text-gray-600">
                <span>PPN</span>
                <span>{{ number_format($invoice->ppn_amount) }}</span>
            </div>
            @endif

            @if($invoice->additional_fee > 0)
            <div class="flex justify-between mb-2 text-gray-600">
                <span>Biaya Tambahan</span>
                <span>{{ number_format($invoice->additional_fee) }}</span>
            </div>
            @endif

            @if($invoice->shipping_cost > 0)
            <div class="flex justify-between mb-2 text-gray-600">
                <span>Ongkos Kirim</span>
                <span>{{ number_format($invoice->shipping_cost) }}</span>
            </div>
            @endif

            <hr class="my-3 border-gray-100">
            <div class="flex justify-between font-bold text-base text-gray-900">
                <span>Grand Total</span>
                <span>{{ number_format($invoice->grand_total) }}</span>
            </div>
        </div>
    </div>

</div>

@once
<script>
(function() {
    const textarea = document.getElementById('inv-notes-input');
    if (!textarea) return;

    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.ctrlKey) {
            e.preventDefault();
            const btn = document.getElementById('inv-btn-save-notes');
            if (btn) btn.click();
        }
    });
})();

async function saveInvNotes(url) {
    const textarea = document.getElementById('inv-notes-input');
    const btnSave  = document.getElementById('inv-btn-save-notes');
    const status   = document.getElementById('inv-notes-status');
    if (!textarea || !btnSave) return;

    const notes       = textarea.value;
    const origLabel   = btnSave.textContent;
    btnSave.disabled  = true;
    btnSave.textContent = 'Menyimpan...';

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
            btnSave.classList.replace('bg-blue-600', 'bg-green-600');
            btnSave.classList.remove('hover:bg-blue-700');
            if (status) {
                status.textContent = 'Disimpan baru saja';
                status.className = 'text-xs text-green-500';
                status.classList.remove('hidden');
            }
            setTimeout(() => {
                btnSave.textContent = origLabel;
                btnSave.classList.replace('bg-green-600', 'bg-blue-600');
                btnSave.classList.add('hover:bg-blue-700');
                btnSave.disabled = false;
                if (status) status.classList.add('hidden');
            }, 2000);
        } else {
            throw new Error(data.message ?? 'Gagal menyimpan');
        }
    } catch(err) {
        btnSave.textContent = 'Error ❌';
        btnSave.classList.replace('bg-blue-600', 'bg-red-500');
        if (status) {
            status.textContent = err.message;
            status.className = 'text-xs text-red-500';
            status.classList.remove('hidden');
        }
        setTimeout(() => {
            btnSave.textContent = origLabel;
            btnSave.classList.replace('bg-red-500', 'bg-blue-600');
            btnSave.classList.add('hover:bg-blue-700');
            btnSave.disabled = false;
        }, 2500);
    }
}
</script>
@endonce
