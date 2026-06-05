@php
    $isEdit = isset($cr);
    $defaults = $isEdit ? [
        'date' => optional($cr->date)->format('Y-m-d'),
        'type' => $cr->type,
        'cash_account_id' => $cr->cash_account_id,
        'supplier_id' => $cr->supplier_id,
        'payer' => $cr->payer,
        'reference' => $cr->reference,
        'notes' => $cr->notes,
    ] : [
        'date' => old('date', now()->toDateString()),
        'type' => $type,
        'cash_account_id' => old('cash_account_id'),
        'supplier_id' => old('supplier_id'),
        'payer' => old('payer'),
        'reference' => old('reference'),
        'notes' => old('notes'),
    ];

    $existingLines = $isEdit
        ? $cr->lines->map(fn($l) => [
            'account_id' => $l->account_id,
            'amount' => (float) $l->amount,
            'description' => $l->description,
            'supplier_overpayment_id' => $l->supplier_overpayment_id,
        ])->all()
        : (old('lines') ?: []);

    $typeSubtitles = [
        'general'         => 'Kas/Bank ← Akun Pendapatan',
        'supplier_refund' => 'Kas/Bank ← Piutang Lebih Bayar Pemasok (1108)',
    ];
@endphp

<div class="bg-white rounded shadow p-4 space-y-4">
    @if(session('error') || $errors->any())
        <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm">
            {{ session('error') }}
            @foreach ($errors->all() as $err)
                <div>• {{ $err }}</div>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-12 gap-3 text-sm">
        <div class="col-span-4">
            <label class="block text-xs text-gray-500 mb-1">Tipe Pemasukan <span class="text-red-500">*</span></label>
            <select name="type" id="crType" class="border rounded px-2 h-9 w-full" required>
                <option value="general"         @selected($defaults['type']==='general')>Umum</option>
                <option value="supplier_refund" @selected($defaults['type']==='supplier_refund')>Refund Pemasok</option>
            </select>
            <div id="typeSubtitle" class="text-[11px] text-gray-400 mt-1"></div>
        </div>
        <div class="col-span-3">
            <label class="block text-xs text-gray-500 mb-1">Tanggal <span class="text-red-500">*</span></label>
            <input type="date" name="date" value="{{ $defaults['date'] }}" class="border rounded px-2 h-9 w-full" required>
        </div>
        <div class="col-span-5">
            <label class="block text-xs font-semibold text-emerald-700 mb-1">TUJUAN KAS / BANK <span class="text-red-500">*</span></label>
            <select name="cash_account_id"
                    class="border border-emerald-500 bg-emerald-50 rounded px-2 h-9 w-full font-semibold text-emerald-900"
                    required>
                <option value="">— pilih tujuan kas/bank —</option>
                @foreach($cashAccounts as $acc)
                    <option value="{{ $acc->id }}" @selected($defaults['cash_account_id']==$acc->id)>
                        {{ $acc->code }} — {{ $acc->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ========== SUPPLIER PICKER (untuk supplier_refund: 1 refund = 1 supplier) ========== --}}
    @php
        $selectedSupplier = $isEdit && $cr->supplier_id
            ? $suppliers->firstWhere('id', $cr->supplier_id)
            : null;
        $selectedSupplierLabel = $selectedSupplier
            ? $selectedSupplier->name . ' [Rp ' . number_format((float) ($selectedSupplier->overpay_balance ?? 0), 0, ',', '.') . ' piutang]'
            : '';
    @endphp
    <div id="supplierField" class="hidden">
        <div class="bg-purple-50 border border-purple-200 rounded p-3 space-y-2">
            <div class="grid grid-cols-12 gap-3 items-end">
                <div class="col-span-7">
                    <label class="block text-xs font-semibold text-purple-700 mb-1">Pemasok (pemberi refund) <span class="text-red-500">*</span></label>
                    <input type="text" list="supplierOverpayList" id="supplierSearch"
                           value="{{ $selectedSupplierLabel }}"
                           class="border rounded px-2 h-9 w-full"
                           placeholder="Ketik nama pemasok…">
                    <input type="hidden" name="supplier_id" id="supplierId" value="{{ $defaults['supplier_id'] }}">
                </div>
                <div class="col-span-5">
                    <div class="text-xs text-purple-700">Saldo Piutang ke Pemasok</div>
                    <div class="flex items-center gap-2">
                        <div id="overpayBalance" class="text-base font-bold text-purple-900">—</div>
                        <button type="button" id="btnFillFullRefund"
                                class="hidden text-xs bg-purple-600 hover:bg-purple-700 text-white px-2 py-1 rounded">
                            Terima Semua
                        </button>
                    </div>
                </div>
            </div>
            <div class="text-[11px] text-purple-700">
                Hanya pemasok dengan saldo piutang &gt; 0 yang muncul. Total refund tidak boleh melebihi saldo.
            </div>
        </div>

        <datalist id="supplierOverpayList">
            @foreach($suppliers as $sup)
                <option data-id="{{ $sup->id }}"
                        value="{{ $sup->name }} [Rp {{ number_format((float) ($sup->overpay_balance ?? 0), 0, ',', '.') }} piutang]"></option>
            @endforeach
        </datalist>
    </div>

    {{-- ============ DETAIL TABLE ============ --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <h3 class="text-sm font-semibold">Rincian</h3>
            <button type="button" id="btnAddLine" class="bg-green-600 text-white px-2 py-1 rounded text-xs">+ Baris</button>
        </div>
        <table class="w-full text-sm" id="linesTable">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-2 py-2 text-left" style="min-width:220px">Akun (Kredit)</th>
                    <th class="px-2 py-2 text-left">Keterangan</th>
                    <th class="px-2 py-2 text-right" style="width:160px">Nominal</th>
                    <th style="width:30px"></th>
                </tr>
            </thead>
            <tbody id="linesBody"></tbody>
            <tfoot class="border-t font-semibold">
                <tr>
                    <td colspan="2" class="px-2 py-2 text-right">Total</td>
                    <td class="px-2 py-2 text-right" id="grandTotal">0</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <datalist id="revenueAccountList">
        @foreach($revenueAccounts as $acc)
            <option data-id="{{ $acc->id }}" value="{{ $acc->code }} — {{ $acc->name }}"></option>
        @endforeach
    </datalist>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
        <textarea name="notes" rows="2" class="border rounded px-2 py-1.5 w-full">{{ $defaults['notes'] }}</textarea>
    </div>

    <div class="flex justify-between border-t pt-3">
        <a href="{{ route(\App\Modules\Finance\Models\CashReceipt::listRouteForType($type ?? 'general')) }}" class="text-gray-500 text-sm">← Kembali</a>
        <div class="flex gap-2">
            <button type="submit" name="_after_save" value="" class="bg-gray-600 text-white px-4 py-2 rounded text-sm">Simpan Draf</button>
            <button type="submit" name="_after_save" value="post" class="bg-green-600 text-white px-4 py-2 rounded text-sm"
                    onclick="return confirm('Simpan & langsung POST?')">Simpan & Post</button>
        </div>
    </div>
</div>

<script>
const revenueAccounts = {!! json_encode($revenueAccounts->map(fn($a) => ['id' => $a->id, 'label' => $a->code . ' — ' . $a->name])->values()) !!};
const supplierOverpayAccount = {!! json_encode($supplierOverpayAccount ? ['id' => $supplierOverpayAccount->id, 'label' => $supplierOverpayAccount->code . ' — ' . $supplierOverpayAccount->name] : null) !!};
const initialLines = {!! json_encode($existingLines) !!};
const typeSubtitles = {!! json_encode($typeSubtitles) !!};

const suppliers = {!! json_encode($suppliers->map(fn($s) => [
    'id' => $s->id,
    'name' => $s->name,
    'overpay' => (float) ($s->overpay_balance ?? 0),
    'label' => $s->name . ' [Rp ' . number_format((float) ($s->overpay_balance ?? 0), 0, ',', '.') . ' piutang]',
])->values()) !!};
const supplierByLabel = Object.fromEntries(suppliers.map(s => [s.label, s]));

const typeEl = document.getElementById('crType');
const linesBody = document.getElementById('linesBody');
const grandTotalEl = document.getElementById('grandTotal');
const supplierField = document.getElementById('supplierField');
const supplierSearch = document.getElementById('supplierSearch');
const supplierIdEl = document.getElementById('supplierId');
const overpayInfo = document.getElementById('overpayBalance');
const subtitleEl = document.getElementById('typeSubtitle');
const btnFillFullRefund = document.getElementById('btnFillFullRefund');

const revenueById = Object.fromEntries(revenueAccounts.map(a => [String(a.id), a]));
const revenueByLabel = Object.fromEntries(revenueAccounts.map(a => [a.label, a]));

let currentOverpayBalance = 0;

function fmt(n){ return Number(n||0).toLocaleString('id-ID'); }
function esc(s){ return String(s ?? '').replace(/"/g,'&quot;'); }

// ============================================================
// LINE ROWS
// ============================================================

function renderGeneralRow(line){
    const accId = String(line.account_id || '');
    const accLabel = revenueById[accId] ? revenueById[accId].label : '';
    return `
        <td class="px-2 py-1">
            <input type="text" list="revenueAccountList" value="${esc(accLabel)}"
                   class="border rounded px-2 py-1 w-full account-search" placeholder="Ketik kode/nama akun pendapatan…">
            <input type="hidden" name="lines[][account_id]" value="${esc(accId)}" class="account-id">
        </td>
        <td class="px-2 py-1">
            <input type="text" name="lines[][description]" value="${esc(line.description)}" class="border rounded px-2 py-1 w-full">
        </td>
        <td class="px-2 py-1 text-right">
            <input type="text" inputmode="numeric" name="lines[][amount]" value="${line.amount||''}"
                   class="border rounded px-2 py-1 w-full text-right amount-input rupiah-input" required>
        </td>
        <td class="px-2 py-1 text-center">
            <button type="button" class="text-red-600 hover:text-red-800 btn-remove">×</button>
        </td>
    `;
}

function renderLockedRow(line, lockedAcc){
    const lockedId = lockedAcc ? lockedAcc.id : '';
    const lockedLabel = lockedAcc ? lockedAcc.label : '(akun tidak tersedia)';
    return `
        <td class="px-2 py-1">
            <div class="border rounded px-2 py-1 bg-gray-100 text-gray-700">${esc(lockedLabel)}</div>
            <input type="hidden" name="lines[][account_id]" value="${esc(lockedId)}" class="account-id">
        </td>
        <td class="px-2 py-1">
            <input type="text" name="lines[][description]" value="${esc(line.description)}" class="border rounded px-2 py-1 w-full">
        </td>
        <td class="px-2 py-1 text-right">
            <input type="text" inputmode="numeric" name="lines[][amount]" value="${line.amount||''}"
                   class="border rounded px-2 py-1 w-full text-right amount-input rupiah-input" required>
        </td>
        <td class="px-2 py-1 text-center">
            <button type="button" class="text-red-600 hover:text-red-800 btn-remove">×</button>
        </td>
    `;
}

function addLineRow(line = {}){
    const tr = document.createElement('tr');
    tr.className = 'border-b';
    const t = typeEl.value;
    if (t === 'general')                tr.innerHTML = renderGeneralRow(line);
    else if (t === 'supplier_refund')   tr.innerHTML = renderLockedRow(line, supplierOverpayAccount);
    else return;

    linesBody.appendChild(tr);
    bindRow(tr);
    fixLineNames();
    recalc();
}

function bindRow(tr){
    tr.querySelector('.btn-remove')?.addEventListener('click', () => { tr.remove(); fixLineNames(); recalc(); });
    tr.querySelector('.amount-input')?.addEventListener('input', recalc);
    const search = tr.querySelector('.account-search');
    const idInput = tr.querySelector('.account-id');
    if (search && idInput) {
        const sync = () => {
            const acc = revenueByLabel[search.value];
            idInput.value = acc ? acc.id : '';
        };
        search.addEventListener('input', sync);
        search.addEventListener('change', sync);
    }
}

function fixLineNames(){
    Array.from(linesBody.querySelectorAll('tr')).forEach((tr, idx) => {
        tr.querySelectorAll('[name^="lines["]').forEach(el => {
            const m = el.name.match(/\]\[(\w+)\]$/);
            if (m) el.name = `lines[${idx}][${m[1]}]`;
        });
    });
}

function recalc(){
    let sum = 0;
    linesBody.querySelectorAll('.amount-input').forEach(i => sum += window.cleanNumber(i.value)||0);
    grandTotalEl.textContent = fmt(sum);
}

// ============================================================
// Supplier picker (untuk supplier_refund)
// ============================================================

async function fetchOverpayBalance(){
    if (!supplierIdEl.value) {
        overpayInfo.textContent = '—';
        currentOverpayBalance = 0;
        btnFillFullRefund.classList.add('hidden');
        return;
    }
    try {
        const res = await fetch(`{{ url('/erp/finance/cash-bank/receipts/supplier-overpay-balance') }}/${supplierIdEl.value}`);
        const j = await res.json();
        currentOverpayBalance = parseFloat(j.balance) || 0;
        overpayInfo.textContent = 'Rp ' + fmt(currentOverpayBalance);
        btnFillFullRefund.classList.toggle('hidden', currentOverpayBalance <= 0);
    } catch(e){
        overpayInfo.textContent = '—';
        currentOverpayBalance = 0;
        btnFillFullRefund.classList.add('hidden');
    }
}

function syncSupplier(){
    const match = supplierByLabel[supplierSearch.value];
    if (match) {
        supplierIdEl.value = match.id;
        fetchOverpayBalance();
    } else {
        supplierIdEl.value = '';
        overpayInfo.textContent = '—';
        currentOverpayBalance = 0;
        btnFillFullRefund.classList.add('hidden');
    }
}

function fillFullRefund(){
    if (currentOverpayBalance <= 0) return;
    linesBody.innerHTML = '';
    addLineRow({ amount: currentOverpayBalance, description: 'Terima refund penuh dari pemasok' });
}

// ============================================================
// Layout toggling & init
// ============================================================

function refreshLayoutForType(){
    const t = typeEl.value;
    subtitleEl.textContent = typeSubtitles[t] || '';

    supplierField.classList.toggle('hidden', t !== 'supplier_refund');
    supplierSearch.required = (t === 'supplier_refund');

    if (t !== 'supplier_refund') {
        overpayInfo.textContent = '—';
        currentOverpayBalance = 0;
        btnFillFullRefund.classList.add('hidden');
    } else {
        fetchOverpayBalance();
    }
}

function rebuildLines(){
    const preserved = Array.from(linesBody.querySelectorAll('tr')).map(tr => ({
        amount: tr.querySelector('.amount-input')?.value || '',
        description: tr.querySelector('input[name$="[description]"]')?.value || '',
    }));
    linesBody.innerHTML = '';
    if (preserved.length === 0) addLineRow({});
    else preserved.forEach(p => addLineRow(p));
}

document.getElementById('btnAddLine').addEventListener('click', () => addLineRow({}));
btnFillFullRefund.addEventListener('click', fillFullRefund);

typeEl.addEventListener('change', () => {
    refreshLayoutForType();
    rebuildLines();
});

supplierSearch.addEventListener('input', syncSupplier);
supplierSearch.addEventListener('change', syncSupplier);
supplierSearch.addEventListener('blur', () => {
    if (!supplierByLabel[supplierSearch.value]) {
        supplierSearch.value = '';
        supplierIdEl.value = '';
        overpayInfo.textContent = '—';
        currentOverpayBalance = 0;
        btnFillFullRefund.classList.add('hidden');
    }
});

// Init
refreshLayoutForType();
if (initialLines && initialLines.length) initialLines.forEach(addLineRow);
else addLineRow({});
</script>
