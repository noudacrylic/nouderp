@php
    $isEdit = isset($cd);
    $defaults = $isEdit ? [
        'date' => optional($cd->date)->format('Y-m-d'),
        'type' => $cd->type,
        'cash_account_id' => $cd->cash_account_id,
        'customer_id' => $cd->customer_id,
        'notes' => $cd->notes,
    ] : [
        'date' => old('date', now()->toDateString()),
        'type' => $type,
        'cash_account_id' => old('cash_account_id'),
        'customer_id' => old('customer_id'),
        'notes' => old('notes'),
    ];

    $existingLines = $isEdit
        ? $cd->lines->map(fn($l) => [
            'account_id' => $l->account_id,
            'amount' => (float) $l->amount,
            'description' => $l->description,
            'sales_invoice_id' => $l->sales_invoice_id,
            'customer_overpayment_id' => $l->customer_overpayment_id,
        ])->all()
        : (old('lines') ?: []);

    $typeSubtitles = [
        'general'         => 'Beban / akun pengeluaran → Kas/Bank',
        'freight'         => 'Titipan Ongkir (1203) → Kas/Bank, ambil otomatis dari invoice',
        'customer_refund' => 'Overpay Customer (2106) → Kas/Bank',
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
            <label class="block text-xs text-gray-500 mb-1">Tipe Pengeluaran <span class="text-red-500">*</span></label>
            <select name="type" id="cdType" class="border rounded px-2 h-9 w-full" required>
                <option value="general"         @selected($defaults['type']==='general')>Umum</option>
                <option value="freight"         @selected($defaults['type']==='freight')>Bayar Ongkir</option>
                <option value="customer_refund" @selected($defaults['type']==='customer_refund')>Refund Customer</option>
            </select>
            <div id="typeSubtitle" class="text-[11px] text-gray-400 mt-1"></div>
        </div>
        <div class="col-span-3">
            <label class="block text-xs text-gray-500 mb-1">Tanggal <span class="text-red-500">*</span></label>
            <input type="date" name="date" value="{{ $defaults['date'] }}" class="border rounded px-2 h-9 w-full" required>
        </div>
        <div class="col-span-5">
            <label class="block text-xs font-semibold text-emerald-700 mb-1">SUMBER KAS / BANK <span class="text-red-500">*</span></label>
            <select name="cash_account_id"
                    class="border border-emerald-500 bg-emerald-50 rounded px-2 h-9 w-full font-semibold text-emerald-900"
                    required>
                <option value="">— pilih sumber kas/bank —</option>
                @foreach($cashAccounts as $acc)
                    <option value="{{ $acc->id }}" @selected($defaults['cash_account_id']==$acc->id)>
                        {{ $acc->code }} — {{ $acc->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ========== REFUND CUSTOMER PICKER (1 customer per CD, lihat memory CD overhaul design A) ========== --}}
    @php
        $selectedCustomer = $isEdit && $cd->customer_id
            ? $customers->firstWhere('id', $cd->customer_id)
            : null;
        $selectedCustomerLabel = $selectedCustomer
            ? $selectedCustomer->name . ' [Rp ' . number_format((float) ($selectedCustomer->overpay_balance ?? 0), 0, ',', '.') . ' overpay]'
            : '';
    @endphp
    <div id="customerField" class="hidden">
        <div class="bg-purple-50 border border-purple-200 rounded p-3 space-y-2">
            <div class="grid grid-cols-12 gap-3 items-end">
                <div class="col-span-7">
                    <label class="block text-xs font-semibold text-purple-700 mb-1">Customer (penerima refund) <span class="text-red-500">*</span></label>
                    <input type="text" list="customerOverpayList" id="customerSearch"
                           value="{{ $selectedCustomerLabel }}"
                           class="border rounded px-2 h-9 w-full"
                           placeholder="Ketik nama customer…">
                    <input type="hidden" name="customer_id" id="customerId" value="{{ $defaults['customer_id'] }}">
                </div>
                <div class="col-span-5">
                    <div class="text-xs text-purple-700">Saldo Overpay Tersedia</div>
                    <div class="flex items-center gap-2">
                        <div id="overpayBalance" class="text-base font-bold text-purple-900">—</div>
                        <button type="button" id="btnFillFullRefund"
                                class="hidden text-xs bg-purple-600 hover:bg-purple-700 text-white px-2 py-1 rounded">
                            Kembalikan Semua
                        </button>
                    </div>
                </div>
            </div>
            <div class="text-[11px] text-purple-700">
                Hanya customer dengan saldo overpay &gt; 0 yang muncul. Total refund tidak boleh melebihi saldo.
            </div>
        </div>

        <datalist id="customerOverpayList">
            @foreach($customers as $cust)
                <option data-id="{{ $cust->id }}"
                        value="{{ $cust->name }} [Rp {{ number_format((float) ($cust->overpay_balance ?? 0), 0, ',', '.') }} overpay]"></option>
            @endforeach
        </datalist>
    </div>

    {{-- ============ GENERIC TABLE (general & customer_refund) ============ --}}
    <div id="genericSection">
        <div class="flex items-center justify-between mb-2">
            <h3 class="text-sm font-semibold">Detail</h3>
            <button type="button" id="btnAddLine" class="bg-green-600 text-white px-2 py-1 rounded text-xs">+ Baris</button>
        </div>
        <table class="w-full text-sm" id="linesTable">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-2 py-2 text-left" style="min-width:220px">Akun (Debit)</th>
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

    {{-- ============ FREIGHT TABLE ============ --}}
    <div id="freightSection" class="hidden">
        <div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
            <h3 class="text-sm font-semibold">Titipan Ongkir per Invoice</h3>
            <div class="flex items-center gap-2 flex-wrap">
                <input type="text" id="freightSearch" class="border rounded px-2 h-8 text-sm w-72"
                       placeholder="Cari customer / no invoice / no SJ / resi…">
                <span id="freightStatus" class="text-xs text-gray-500"></span>
                <a href="{{ route('finance.cash-bank.disbursements.freight-import-template') }}"
                   class="text-xs text-blue-700 hover:underline whitespace-nowrap"
                   title="Download template Excel">⬇ Template</a>
                <label class="cursor-pointer bg-amber-600 hover:bg-amber-700 text-white px-2 h-8 inline-flex items-center rounded text-xs font-semibold"
                       title="Format: 2 kolom — A: No Inv/DO/Resi, B: Bayar Aktual">
                    📤 Upload Excel
                    <input type="file" id="freightImportFile" accept=".xlsx,.xls,.csv" class="hidden">
                </label>
            </div>
        </div>
        <div id="freightImportReport" class="hidden mb-2 text-xs rounded border px-3 py-2"></div>
        <div class="overflow-x-auto border rounded">
            <table class="w-full text-sm" id="freightTable">
                <thead class="bg-gray-50 border-b text-xs uppercase text-gray-600">
                    <tr>
                        <th class="px-2 py-2 text-center w-10">Pilih</th>
                        <th class="px-2 py-2 text-left">Customer</th>
                        <th class="px-2 py-2 text-left">No Invoice</th>
                        <th class="px-2 py-2 text-left">No SJ / Resi</th>
                        <th class="px-2 py-2 text-right" style="width:130px">Titipan</th>
                        <th class="px-2 py-2 text-right" style="width:160px">Bayar Aktual</th>
                        <th class="px-2 py-2 text-right" style="width:130px">Selisih</th>
                    </tr>
                </thead>
                <tbody id="freightBody">
                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400" id="freightEmpty">Memuat invoice…</td></tr>
                </tbody>
                <tfoot class="border-t font-semibold bg-gray-50">
                    <tr>
                        <td colspan="4" class="px-2 py-2 text-right">Total</td>
                        <td class="px-2 py-2 text-right" id="freightTotalTitipan">0</td>
                        <td class="px-2 py-2 text-right" id="freightTotalBayar">0</td>
                        <td class="px-2 py-2 text-right" id="freightTotalSelisih">0</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="text-xs text-gray-500 mt-1">
            Selisih lebih (titipan &gt; aktual) → pendapatan. Selisih kurang → beban.
            Konfigurasi akun di <a href="{{ route('settings.freight.edit') }}" class="text-blue-600 underline">Settings &gt; Pengaturan Ongkir</a>.
        </div>
    </div>

    <datalist id="expenseAccountList">
        @foreach($expenseAccounts as $acc)
            <option data-id="{{ $acc->id }}" value="{{ $acc->code }} — {{ $acc->name }}"></option>
        @endforeach
    </datalist>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
        <textarea name="notes" rows="2" class="border rounded px-2 py-1.5 w-full">{{ $defaults['notes'] }}</textarea>
    </div>

    <div class="flex justify-between border-t pt-3">
        <a href="{{ route(\App\Modules\Finance\Models\CashDisbursement::listRouteForType($type ?? 'general')) }}" class="text-gray-500 text-sm">← Kembali</a>
        <div class="flex gap-2">
            <button type="submit" name="_after_save" value="" class="bg-gray-600 text-white px-4 py-2 rounded text-sm">Simpan Draft</button>
            <button type="submit" name="_after_save" value="post" class="bg-green-600 text-white px-4 py-2 rounded text-sm"
                    onclick="return confirm('Simpan & langsung POST?')">Simpan & Post</button>
        </div>
    </div>
</div>

<script>
const expenseAccounts = {!! json_encode($expenseAccounts->map(fn($a)=>['id'=>$a->id,'code'=>$a->code,'name'=>$a->name,'label'=>$a->code.' — '.$a->name])->values()) !!};
const titipanAccount = {!! json_encode($titipanAccount ? ['id'=>$titipanAccount->id,'label'=>$titipanAccount->code.' — '.$titipanAccount->name] : null) !!};
const overpayAccount = {!! json_encode($overpayAccount ? ['id'=>$overpayAccount->id,'label'=>$overpayAccount->code.' — '.$overpayAccount->name] : null) !!};
const initialLines = {!! json_encode($existingLines) !!};
const typeSubtitles = {!! json_encode($typeSubtitles) !!};
const cdId = {!! json_encode($isEdit ? $cd->id : null) !!};

const typeEl = document.getElementById('cdType');
const linesBody = document.getElementById('linesBody');
const grandTotalEl = document.getElementById('grandTotal');
const customerField = document.getElementById('customerField');
const customerSearch = document.getElementById('customerSearch');
const customerIdEl = document.getElementById('customerId');
const overpayInfo = document.getElementById('overpayBalance');

// Customer label → id lookup (untuk live search datalist)
const customers = {!! json_encode($customers->map(fn($c) => [
    'id' => $c->id,
    'name' => $c->name,
    'overpay' => (float) ($c->overpay_balance ?? 0),
    'label' => $c->name . ' [Rp ' . number_format((float) ($c->overpay_balance ?? 0), 0, ',', '.') . ' overpay]',
])->values()) !!};
const customerByLabel = Object.fromEntries(customers.map(c => [c.label, c]));
const subtitleEl = document.getElementById('typeSubtitle');
const genericSection = document.getElementById('genericSection');
const freightSection = document.getElementById('freightSection');
const freightBody = document.getElementById('freightBody');
const freightSearch = document.getElementById('freightSearch');
const freightStatus = document.getElementById('freightStatus');
const freightEmpty = document.getElementById('freightEmpty');

const expenseById = Object.fromEntries(expenseAccounts.map(a => [String(a.id), a]));
const expenseByLabel = Object.fromEntries(expenseAccounts.map(a => [a.label, a]));

function fmt(n){ return Number(n||0).toLocaleString('id-ID'); }
function esc(s){ return String(s ?? '').replace(/"/g,'&quot;'); }

// ============================================================
// GENERIC TABLE (general & customer_refund)
// ============================================================

function renderGeneralRow(line){
    const accId = String(line.account_id || '');
    const accLabel = expenseById[accId] ? expenseById[accId].label : '';
    return `
        <td class="px-2 py-1">
            <input type="text" list="expenseAccountList" value="${esc(accLabel)}"
                   class="border rounded px-2 py-1 w-full account-search" placeholder="Ketik kode/nama akun…">
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
    if (t === 'general')                   tr.innerHTML = renderGeneralRow(line);
    else if (t === 'customer_refund')      tr.innerHTML = renderLockedRow(line, overpayAccount);
    else return;  // freight uses its own section

    linesBody.appendChild(tr);
    bindGenericRow(tr);
    fixGenericNames();
    recalcGeneric();
}

function bindGenericRow(tr){
    tr.querySelector('.btn-remove')?.addEventListener('click', () => { tr.remove(); fixGenericNames(); recalcGeneric(); });
    tr.querySelector('.amount-input')?.addEventListener('input', recalcGeneric);
    const search = tr.querySelector('.account-search');
    const idInput = tr.querySelector('.account-id');
    if (search && idInput) {
        const sync = () => {
            const acc = expenseByLabel[search.value];
            idInput.value = acc ? acc.id : '';
        };
        search.addEventListener('input', sync);
        search.addEventListener('change', sync);
    }
}

function fixGenericNames(){
    Array.from(linesBody.querySelectorAll('tr')).forEach((tr, idx) => {
        tr.querySelectorAll('[name^="lines["]').forEach(el => {
            const m = el.name.match(/\]\[(\w+)\]$/);
            if (m) el.name = `lines[${idx}][${m[1]}]`;
        });
    });
}

function recalcGeneric(){
    let sum = 0;
    linesBody.querySelectorAll('.amount-input').forEach(i => sum += window.cleanNumber(i.value)||0);
    grandTotalEl.textContent = fmt(sum);
}

// ============================================================
// FREIGHT TABLE (auto-load invoice)
// ============================================================

let freightLoaded = false;
const initialFreightByInvoice = {};
(initialLines || []).forEach(l => {
    if (l.sales_invoice_id) initialFreightByInvoice[String(l.sales_invoice_id)] = parseFloat(l.amount) || 0;
});

async function loadFreightInvoices(q = ''){
    freightStatus.textContent = 'memuat…';
    let url = `{{ url('/erp/finance/cash-bank/disbursements/freight-invoices') }}`;
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (cdId) params.set('exclude_cd_id', cdId);
    if (params.toString()) url += '?' + params.toString();

    try {
        const res = await fetch(url);
        const data = await res.json();
        renderFreightRows(data);
        freightStatus.textContent = `${data.length} invoice`;
        freightLoaded = true;
    } catch(e){
        freightStatus.textContent = 'gagal load: ' + e.message;
    }
}

function renderFreightRows(rows){
    freightBody.innerHTML = '';
    if (!rows.length) {
        freightBody.innerHTML = `<tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Tidak ada invoice yang belum dibayar ongkirnya.</td></tr>`;
        recalcFreight();
        return;
    }
    const titipanId = titipanAccount ? titipanAccount.id : '';
    rows.forEach(inv => {
        const preselectedAmount = initialFreightByInvoice[String(inv.id)];
        const isChecked = preselectedAmount !== undefined;
        const amountVal = isChecked ? preselectedAmount : '';
        const tr = document.createElement('tr');
        tr.className = 'border-b';
        tr.dataset.invoiceId = inv.id;
        tr.dataset.titipan = inv.shipping_cost;
        tr.innerHTML = `
            <td class="px-2 py-1 text-center">
                <input type="checkbox" class="freight-check" ${isChecked ? 'checked' : ''}>
            </td>
            <td class="px-2 py-1">${esc(inv.customer)}</td>
            <td class="px-2 py-1">${esc(inv.invoice_number)}</td>
            <td class="px-2 py-1 text-xs">
                ${inv.delivery_number ? esc(inv.delivery_number) : '<span class="text-gray-400">— no SJ —</span>'}<br>
                ${inv.tracking_number ? `<span class="text-gray-600">${esc(inv.courier_name||'')} ${esc(inv.tracking_number)}</span>` : '<span class="text-gray-400">— no resi —</span>'}
            </td>
            <td class="px-2 py-1 text-right titipan-cell">${fmt(inv.shipping_cost)}</td>
            <td class="px-2 py-1 text-right">
                <input type="text" inputmode="numeric" value="${amountVal}"
                       class="border rounded px-2 py-1 w-full text-right freight-amount rupiah-input" placeholder="${fmt(inv.shipping_cost)}">
                <input type="hidden" class="freight-account" disabled value="${esc(titipanId)}">
                <input type="hidden" class="freight-invoice" disabled value="${inv.id}">
                <input type="hidden" class="freight-desc" disabled value="Bayar ongkir ${esc(inv.invoice_number)}">
            </td>
            <td class="px-2 py-1 text-right selisih-cell text-gray-500">0</td>
        `;
        freightBody.appendChild(tr);
        bindFreightRow(tr);
        updateFreightRowState(tr);
    });
    recalcFreight();
}

function bindFreightRow(tr){
    const check = tr.querySelector('.freight-check');
    const amt = tr.querySelector('.freight-amount');
    check.addEventListener('change', () => {
        if (check.checked && !amt.value) amt.value = tr.dataset.titipan; // default = titipan
        updateFreightRowState(tr);
        recalcFreight();
    });
    amt.addEventListener('input', () => {
        if (window.cleanNumber(amt.value) > 0 && !check.checked) check.checked = true;
        updateFreightRowState(tr);
        recalcFreight();
    });
}

function updateFreightRowState(tr){
    const checked = tr.querySelector('.freight-check').checked;
    const amt = window.cleanNumber(tr.querySelector('.freight-amount').value) || 0;
    const titipan = parseFloat(tr.dataset.titipan) || 0;
    const selisih = titipan - amt;

    // enable/disable hidden inputs for form submission
    tr.querySelectorAll('.freight-account, .freight-invoice, .freight-desc').forEach(el => el.disabled = !checked);
    tr.querySelector('.freight-amount').name = checked ? 'lines[][amount]' : '';
    if (checked) {
        tr.querySelector('.freight-account').name = 'lines[][account_id]';
        tr.querySelector('.freight-invoice').name = 'lines[][sales_invoice_id]';
        tr.querySelector('.freight-desc').name = 'lines[][description]';
    } else {
        tr.querySelector('.freight-account').name = '';
        tr.querySelector('.freight-invoice').name = '';
        tr.querySelector('.freight-desc').name = '';
    }
    tr.classList.toggle('bg-emerald-50', checked);

    // selisih label
    const cell = tr.querySelector('.selisih-cell');
    if (!checked || amt === 0) {
        cell.textContent = '0';
        cell.className = 'px-2 py-1 text-right selisih-cell text-gray-400';
    } else if (selisih > 0) {
        cell.textContent = '+' + fmt(selisih);
        cell.className = 'px-2 py-1 text-right selisih-cell text-green-600 font-semibold';
    } else if (selisih < 0) {
        cell.textContent = fmt(selisih);
        cell.className = 'px-2 py-1 text-right selisih-cell text-red-600 font-semibold';
    } else {
        cell.textContent = '0';
        cell.className = 'px-2 py-1 text-right selisih-cell text-gray-500';
    }
}

function recalcFreight(){
    let titipan = 0, bayar = 0;
    freightBody.querySelectorAll('tr').forEach(tr => {
        const check = tr.querySelector('.freight-check');
        if (!check || !check.checked) return;
        titipan += parseFloat(tr.dataset.titipan) || 0;
        bayar += window.cleanNumber(tr.querySelector('.freight-amount').value) || 0;
    });
    const selisih = titipan - bayar;
    document.getElementById('freightTotalTitipan').textContent = fmt(titipan);
    document.getElementById('freightTotalBayar').textContent = fmt(bayar);
    const sel = document.getElementById('freightTotalSelisih');
    sel.textContent = selisih === 0 ? '0' : (selisih > 0 ? '+' : '') + fmt(selisih);
    sel.className = 'px-2 py-1 text-right ' + (selisih > 0 ? 'text-green-700' : selisih < 0 ? 'text-red-700' : '');
    fixFreightNames();
}

function fixFreightNames(){
    // Reindex submitted freight inputs lines[<idx>][field]
    let idx = 0;
    freightBody.querySelectorAll('tr').forEach(tr => {
        const check = tr.querySelector('.freight-check');
        if (!check || !check.checked) return;
        tr.querySelector('.freight-account').name  = `lines[${idx}][account_id]`;
        tr.querySelector('.freight-amount').name   = `lines[${idx}][amount]`;
        tr.querySelector('.freight-invoice').name  = `lines[${idx}][sales_invoice_id]`;
        tr.querySelector('.freight-desc').name     = `lines[${idx}][description]`;
        idx++;
    });
}

let freightSearchTimer = null;
freightSearch.addEventListener('input', () => {
    clearTimeout(freightSearchTimer);
    freightSearchTimer = setTimeout(() => loadFreightInvoices(freightSearch.value.trim()), 300);
});

// ===== Excel import =====
const freightImportFile = document.getElementById('freightImportFile');
const freightImportReport = document.getElementById('freightImportReport');

freightImportFile.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('file', file);
    if (cdId) fd.append('exclude_cd_id', cdId);

    freightImportReport.className = 'mb-2 text-xs rounded border px-3 py-2 bg-blue-50 border-blue-200 text-blue-700';
    freightImportReport.textContent = 'Memproses ' + file.name + '…';
    freightImportReport.classList.remove('hidden');

    try {
        const res = await fetch(`{{ url('/erp/finance/cash-bank/disbursements/freight-import') }}`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: fd,
        });
        const j = await res.json();
        if (!res.ok) {
            freightImportReport.className = 'mb-2 text-xs rounded border px-3 py-2 bg-red-50 border-red-200 text-red-700';
            freightImportReport.textContent = 'Error: ' + (j.error || ('HTTP ' + res.status));
            return;
        }

        // Reload list dulu (untuk pick up tracking_number yang baru di-update)
        await loadFreightInvoices('');

        let applied = 0;
        (j.matches || []).forEach(m => {
            const tr = freightBody.querySelector(`tr[data-invoice-id="${m.invoice_id}"]`);
            if (!tr) return;
            const check = tr.querySelector('.freight-check');
            const amt   = tr.querySelector('.freight-amount');
            check.checked = true;
            amt.value = m.amount;
            updateFreightRowState(tr);
            applied++;
        });
        recalcFreight();

        const skipped = j.skipped || [];
        const cls = skipped.length === 0
            ? 'bg-green-50 border-green-200 text-green-700'
            : 'bg-amber-50 border-amber-200 text-amber-800';
        freightImportReport.className = 'mb-2 text-xs rounded border px-3 py-2 ' + cls;

        let html = `<b>✓ ${applied} invoice di-import & ter-centang.</b>`;
        if (skipped.length) {
            html += `<div class="mt-1"><b>${skipped.length} baris di-skip:</b><ul class="list-disc ml-5">`;
            skipped.slice(0, 10).forEach(s => {
                html += `<li>Baris ${s.row}${s.identifier ? ' ('+s.identifier+')' : ''}: ${s.reason}</li>`;
            });
            if (skipped.length > 10) html += `<li>… dan ${skipped.length - 10} baris lagi.</li>`;
            html += '</ul></div>';
        }
        freightImportReport.innerHTML = html;
    } catch (err) {
        freightImportReport.className = 'mb-2 text-xs rounded border px-3 py-2 bg-red-50 border-red-200 text-red-700';
        freightImportReport.textContent = 'Error: ' + err.message;
    } finally {
        freightImportFile.value = '';  // reset agar bisa upload file yang sama lagi
    }
});

// ============================================================
// Layout toggling & init
// ============================================================

function refreshLayoutForType(){
    const t = typeEl.value;
    subtitleEl.textContent = typeSubtitles[t] || '';

    customerField.classList.toggle('hidden', t !== 'customer_refund');
    customerSearch.required = (t === 'customer_refund');
    if (t !== 'customer_refund') overpayInfo.textContent = '—';
    else fetchOverpayBalance();

    genericSection.classList.toggle('hidden', t === 'freight');
    freightSection.classList.toggle('hidden', t !== 'freight');

    if (t === 'freight' && !freightLoaded) loadFreightInvoices('');
}

function rebuildGenericLines(){
    const preserved = Array.from(linesBody.querySelectorAll('tr')).map(tr => ({
        amount: tr.querySelector('.amount-input')?.value || '',
        description: tr.querySelector('input[name$="[description]"]')?.value || '',
    }));
    linesBody.innerHTML = '';
    if (preserved.length === 0) addLineRow({});
    else preserved.forEach(p => addLineRow(p));
}

const btnFillFullRefund = document.getElementById('btnFillFullRefund');
let currentOverpayBalance = 0;

async function fetchOverpayBalance(){
    if (!customerIdEl.value) {
        overpayInfo.textContent = '—';
        currentOverpayBalance = 0;
        btnFillFullRefund.classList.add('hidden');
        return;
    }
    try {
        const res = await fetch(`{{ url('/erp/finance/cash-bank/disbursements/customer-overpay-balance') }}/${customerIdEl.value}`);
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

function fillFullRefund(){
    if (currentOverpayBalance <= 0) return;
    // Reset semua line & buat 1 baris dengan amount = full saldo (akun 2106 di-lock otomatis di renderLockedRow)
    linesBody.innerHTML = '';
    addLineRow({ amount: currentOverpayBalance, description: 'Refund saldo overpay penuh' });
}

function syncCustomer(){
    const match = customerByLabel[customerSearch.value];
    if (match) {
        customerIdEl.value = match.id;
        fetchOverpayBalance();
    } else {
        // Tidak ada match → kosongkan id (sementara). Validation backend tetap akan trigger kalau type=customer_refund.
        customerIdEl.value = '';
        overpayInfo.textContent = '—';
    }
}

document.getElementById('btnAddLine').addEventListener('click', () => addLineRow({}));
btnFillFullRefund.addEventListener('click', fillFullRefund);
typeEl.addEventListener('change', () => {
    refreshLayoutForType();
    if (typeEl.value !== 'freight') rebuildGenericLines();
});
customerSearch.addEventListener('input', syncCustomer);
customerSearch.addEventListener('change', syncCustomer);
customerSearch.addEventListener('blur', () => {
    // Bila user ketik bebas yang tidak match → kosongkan label & id supaya tidak ambigu
    if (!customerByLabel[customerSearch.value]) {
        customerSearch.value = '';
        customerIdEl.value = '';
        overpayInfo.textContent = '—';
    }
});

// Init
refreshLayoutForType();
if (typeEl.value !== 'freight') {
    if (initialLines && initialLines.length) initialLines.forEach(addLineRow);
    else addLineRow({});
}
</script>
