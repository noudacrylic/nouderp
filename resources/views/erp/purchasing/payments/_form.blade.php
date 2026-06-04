@csrf
@php
    $isEdit = isset($payment);
    $preselectedSupplierId = old('supplier_id', $payment->supplier_id ?? request('supplier_id'));
    $preselectedSupplier = $preselectedSupplierId ? $suppliers->firstWhere('id', $preselectedSupplierId) : null;
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- LEFT: SUPPLIER + MODE + ITEMS -->
    <div class="bg-white border rounded-lg p-4 shadow-sm overflow-visible">
        <div class="mb-4">
            <div class="relative">
                <input type="text" id="supplierSearch"
                    class="w-full border rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition"
                    placeholder="Ketik nama / kode supplier..." autocomplete="off"
                    value="{{ $preselectedSupplier ? $preselectedSupplier->name . ' (' . $preselectedSupplier->code . ')' : '' }}"
                    {{ $isEdit ? 'readonly' : '' }}>
                <input type="hidden" name="supplier_id" id="supplierId" value="{{ $preselectedSupplierId }}" required>
                <div id="supplierDropdown"
                    class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl hidden max-h-60 overflow-y-auto"></div>
            </div>
            <div class="text-[10px] text-gray-500 mt-2 pl-1 space-y-0.5">
                <div>
                    Saldo Piutang Supplier: <span id="overpayBalance" class="font-bold text-purple-700">Rp 0</span>
                </div>
                <div class="text-gray-400">
                    Saldo DP (Uang Muka): <span id="dpBalance" class="font-bold text-blue-700">Rp 0</span>
                    <span class="italic">— auto-apply ke invoice</span>
                </div>
            </div>
        </div>

        <div class="mb-3 flex items-center justify-between border-b pb-2">
            <div class="flex gap-2" id="modeToggle">
                <button type="button" data-mode="pelunasan"
                    class="mode-btn px-4 py-1.5 rounded-lg text-xs font-bold transition-all shadow-sm bg-blue-600 text-white">
                    Pelunasan
                </button>
                <button type="button" data-mode="uang_muka"
                    class="mode-btn px-4 py-1.5 rounded-lg text-xs font-bold transition-all bg-gray-100 text-gray-500 hover:bg-gray-200">
                    Uang Muka
                </button>
            </div>
        </div>

        <div class="mb-3 border rounded-xl p-4 text-sm bg-blue-50 shadow-inner">
            <div class="flex justify-between items-center">
                <span class="text-xs font-black text-blue-800 uppercase tracking-widest">Total Tagihan:</span>
                <div class="flex items-center gap-2">
                    <span id="totalTagihan" class="text-lg font-black text-blue-950">Rp 0</span>
                    <button type="button" id="btnUseBalance"
                        title="Kurangi total dengan saldo piutang supplier"
                        class="text-[10px] font-black uppercase tracking-wide bg-white border border-purple-200 text-purple-700 px-2 py-1 rounded-lg shadow-sm hover:bg-purple-700 hover:text-white transition active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed">
                        Pakai Saldo
                    </button>
                </div>
            </div>
            <div id="saldoAppliedInfo" class="mt-1.5 text-[10px] text-green-700 font-bold hidden">
                ✓ Saldo <span id="saldoAppliedAmount"></span> diterapkan · sisa bayar: <span id="saldoAppliedSisa"></span>
                <button type="button" id="btnClearUseBalanceTop" class="ml-2 underline text-purple-900 hover:text-pink-700">batalkan</button>
            </div>
            <div id="multiWarning" class="mt-1.5 text-[10px] text-orange-700 font-bold hidden">
                ⚠ Multi-invoice: pembayaran wajib lunas penuh.
            </div>
        </div>

        <div id="itemsContainer" class="space-y-2 max-h-[450px] overflow-y-auto pr-1 custom-scrollbar">
            <div class="text-center py-12 border-2 border-dashed rounded-xl bg-gray-50 border-gray-100 text-xs text-gray-400 italic font-bold">
                Pilih supplier untuk memulai pembayaran
            </div>
        </div>

        <div id="dpModeNote" class="hidden">
            <div class="mb-3 text-center py-3 border-2 border-dashed rounded-xl bg-purple-50 border-purple-200 text-xs text-purple-700 italic font-bold">
                Mode <b>Uang Muka (DP)</b> — pembayaran berdasar PO. Saldo DP nanti di-apply ke invoice.
            </div>
            <div class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 pl-1">PO Open Supplier (klik untuk auto-isi)</div>
            <div id="poList" class="space-y-2 max-h-[380px] overflow-y-auto pr-1 custom-scrollbar"></div>
        </div>
    </div>

    <!-- RIGHT: PAYMENT SETTINGS -->
    <div class="bg-white border rounded-lg p-5 shadow-sm space-y-5">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Tanggal Bayar</label>
                <input type="date" name="payment_date"
                    class="border rounded-lg px-3 py-2 text-sm w-full focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition"
                    value="{{ old('payment_date', isset($payment) ? $payment->payment_date->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Akun Kas / Bank <span class="text-red-500">*</span></label>
                <select name="bank_account_id" id="bankAccount"
                    class="border-2 border-blue-400 bg-blue-50 rounded-lg px-3 py-2 text-sm w-full font-bold focus:ring-0 outline-none transition" required>
                    <option value="">— pilih akun —</option>
                    @foreach($cashAccounts as $a)
                        <option value="{{ $a->id }}" @selected(old('bank_account_id', $payment->bank_account_id ?? '') == $a->id)>{{ $a->code }} — {{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="p-6 bg-gray-50 border rounded-2xl shadow-inner border-gray-100">
            <label class="block text-xs font-bold text-gray-600 mb-3 uppercase tracking-widest text-center">Jumlah Pembayaran</label>
            <div class="flex items-center bg-white border-2 border-transparent rounded-xl px-4 mb-2 shadow-sm focus-within:border-blue-500 transition group">
                <span class="text-lg font-black text-gray-300 pr-2 group-focus-within:text-blue-500">Rp</span>
                <input type="number" id="paymentAmount" name="amount"
                    class="w-full py-4 text-2xl font-black outline-none bg-transparent" placeholder="0"
                    step="0.01" min="0" value="{{ old('amount', $payment->amount ?? '') }}" required>
            </div>

            <div id="dpInfo" class="text-[10px] text-purple-700 font-bold hidden bg-purple-50 p-2 rounded-lg border border-purple-200 italic text-center mt-2">
                Sisa <span id="dpInfoAmount">Rp 0</span> akan jadi DP supplier.
            </div>

            <div id="overpayInfo" class="text-[10px] text-pink-700 font-bold hidden bg-pink-50 p-2 rounded-lg border border-pink-200 italic text-center mt-2">
                Kelebihan <span id="overpayInfoAmount">Rp 0</span> akan masuk Piutang Supplier (1108).
            </div>

            <input type="hidden" name="overpay" id="overpayInput" value="0">
            <input type="hidden" name="used_balance" id="usedBalanceInput" value="0">

            <div class="flex gap-3 mt-4">
                <button type="button" id="btnFull"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-xs font-black shadow-lg shadow-blue-200 transition active:scale-95 flex-1">
                    BAYAR PENUH
                </button>
                <button type="button" id="btnHalf"
                    class="bg-white border border-gray-200 hover:bg-gray-100 text-gray-700 px-4 py-2 rounded-xl text-xs font-black shadow-sm transition active:scale-95 flex-1">
                    BAYAR 50%
                </button>
            </div>
        </div>

        <div>
            <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Catatan</label>
            <textarea name="notes" rows="3"
                class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition"
                placeholder="Misal: Pembayaran via transfer BCA...">{{ old('notes', $payment->notes ?? '') }}</textarea>
        </div>

        <div class="pt-6 border-t">
            <div class="flex gap-2 mb-3">
                <button type="submit" name="_after_save" value=""
                    class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-3 rounded-xl text-xs font-black transition shadow active:scale-95 flex-1 uppercase">
                    Draft
                </button>
                <button type="submit" name="_after_save" value="post"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-xl text-xs font-black transition shadow-lg shadow-blue-200 active:scale-95 flex-1 uppercase">
                    Simpan
                </button>
                <button type="submit" name="_after_save" value="print"
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-xl text-xs font-black transition shadow-lg shadow-green-200 active:scale-95 flex-1 uppercase">
                    Print
                </button>
            </div>
            <div class="text-center">
                <a href="{{ route('purchasing.payments.index') }}"
                    class="text-xs font-bold text-gray-400 hover:text-gray-600 transition">Batal</a>
            </div>
        </div>
    </div>
</div>

<template id="invoiceItemTpl">
    <label class="invoice-item block border rounded-xl p-3 text-sm transition hover:bg-blue-50 cursor-pointer border-gray-200 hover:border-blue-200">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-3 flex-1">
                <input type="checkbox" class="invoice-cb w-5 h-5 rounded text-blue-600 border-gray-300 focus:ring-blue-500">
                <div class="flex-1">
                    <div class="font-black text-gray-800 tracking-tight invoice-no"></div>
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5 invoice-date"></div>
                </div>
            </div>
            <div class="text-right">
                <div class="text-[9px] text-gray-400 font-bold uppercase">Outstanding</div>
                <div class="font-black text-gray-950 invoice-outstanding"></div>
            </div>
        </div>
        <div class="invoice-amount-row hidden mt-2 pl-8 flex items-center gap-2">
            <span class="text-[10px] text-gray-500 font-bold">Bayar:</span>
            <input type="number" step="0.01" min="0" class="invoice-amount border rounded px-2 py-1 text-sm text-right flex-1" value="0">
            <input type="hidden" class="invoice-id">
        </div>
    </label>
</template>

<style>
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .supplier-item { padding: 8px 12px; cursor: pointer; transition: background 0.15s; border-bottom: 1px solid #f3f4f6; }
    .supplier-item:hover { background-color: #eff6ff; }
    .supplier-item:last-child { border-bottom: none; }
</style>

<script>
(function(){
    const supSearch = document.getElementById('supplierSearch');
    const supId = document.getElementById('supplierId');
    const supDropdown = document.getElementById('supplierDropdown');
    const dpBalEl = document.getElementById('dpBalance');
    const overpayBalEl = document.getElementById('overpayBalance');
    const btnUseBalance = document.getElementById('btnUseBalance');
    const btnClearUseBalanceTop = document.getElementById('btnClearUseBalanceTop');
    const saldoAppliedInfo = document.getElementById('saldoAppliedInfo');
    const saldoAppliedAmount = document.getElementById('saldoAppliedAmount');
    const saldoAppliedSisa = document.getElementById('saldoAppliedSisa');
    const itemsContainer = document.getElementById('itemsContainer');
    const dpModeNote = document.getElementById('dpModeNote');
    const totalTagihanEl = document.getElementById('totalTagihan');
    const multiWarning = document.getElementById('multiWarning');
    const amountInput = document.getElementById('paymentAmount');
    const dpInfo = document.getElementById('dpInfo');
    const dpInfoAmount = document.getElementById('dpInfoAmount');
    const overpayInfo = document.getElementById('overpayInfo');
    const overpayInfoAmount = document.getElementById('overpayInfoAmount');
    const overpayInput = document.getElementById('overpayInput');
    const usedBalanceInput = document.getElementById('usedBalanceInput');
    const itemTpl = document.getElementById('invoiceItemTpl').innerHTML;

    let currentMode = 'pelunasan';
    let currentDpBalance = 0;
    let currentOverpayBalance = 0;
    // Total alokasi sebelum & sesudah pakai saldo (mirror sales pattern)
    let totalTagihanGross = 0;
    let totalSetelahSaldo = 0;
    let openInvoices = [];
    let openPos = [];
    const poListEl = document.getElementById('poList');
    const notesInput = document.querySelector('textarea[name="notes"]');

    function fmt(n){ return new Intl.NumberFormat('id-ID',{maximumFractionDigits:2}).format(n); }
    function rp(n){ return 'Rp ' + fmt(n); }

    // ── SUPPLIER SEARCH ────────────────────────────────────────────────
    let supTimer;
    supSearch.addEventListener('input', () => {
        supId.value = '';
        clearTimeout(supTimer);
        const q = supSearch.value.trim();
        supTimer = setTimeout(() => doSupSearch(q), 250);
    });
    supSearch.addEventListener('focus', () => doSupSearch(supSearch.value.trim()));
    document.addEventListener('click', e => {
        if (!supDropdown.contains(e.target) && e.target !== supSearch) supDropdown.classList.add('hidden');
    });

    async function doSupSearch(q){
        try {
            const res = await fetch('/erp/purchasing/api/suppliers/search?q=' + encodeURIComponent(q));
            renderSupResults(await res.json());
        } catch(e){ console.error(e); }
    }

    function renderSupResults(list){
        supDropdown.innerHTML = '';
        if (!list.length){
            supDropdown.innerHTML = '<div class="p-3 text-xs text-gray-400 italic">Tidak ada supplier.</div>';
        } else {
            list.forEach(s => {
                const div = document.createElement('div');
                div.className = 'supplier-item';
                div.innerHTML = '<div class="font-bold text-sm text-gray-700">' + s.name + '</div>'
                              + '<div class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">' + s.code + ' · term ' + (s.payment_term_days||0) + ' hari</div>';
                div.onclick = () => pickSupplier(s);
                supDropdown.appendChild(div);
            });
        }
        supDropdown.classList.remove('hidden');
    }

    async function pickSupplier(s){
        supId.value = s.id;
        supSearch.value = s.name + ' (' + s.code + ')';
        supDropdown.classList.add('hidden');
        await loadSupplierData(s.id);
    }

    async function loadSupplierData(id){
        if (!id) {
            currentDpBalance = 0;
            currentOverpayBalance = 0;
            openInvoices = [];
            openPos = [];
            updateDpBalance();
            renderItems();
            renderPos();
            return;
        }
        try {
            const [dpRes, invRes, poRes] = await Promise.all([
                fetch('/erp/purchasing/api/suppliers/' + id + '/open-dp'),
                fetch('/erp/purchasing/api/suppliers/' + id + '/open-invoices'),
                fetch('/erp/purchasing/api/suppliers/' + id + '/open-pos'),
            ]);
            const dpData = await dpRes.json();
            currentDpBalance = parseFloat(dpData.dp_balance || 0);
            currentOverpayBalance = parseFloat(dpData.overpay_balance || 0);
            openInvoices = await invRes.json();
            openPos = await poRes.json();
            updateDpBalance();
            renderItems();
            renderPos();
        } catch(e){ console.error(e); }
    }

    function renderPos(){
        if (!poListEl) return;
        if (!supId.value || !openPos.length){
            poListEl.innerHTML = '<div class="text-center py-6 italic text-gray-400 text-xs font-bold">Tidak ada PO open.</div>';
            return;
        }
        poListEl.innerHTML = '';
        openPos.forEach(po => {
            const pct = Math.round((po.invoiced_ratio || 0) * 100);
            const statusBadge = pct === 0
                ? '<span class="bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded text-[8px] font-black uppercase">Belum Inv</span>'
                : '<span class="bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded text-[8px] font-black uppercase">Partial ' + pct + '%</span>';

            const label = document.createElement('label');
            label.className = 'po-item block border rounded-xl p-3 text-sm transition hover:bg-purple-50 cursor-pointer border-gray-200 hover:border-purple-300';
            label.innerHTML =
                '<div class="flex justify-between items-center">' +
                  '<div class="flex items-center gap-3 flex-1">' +
                    '<input type="checkbox" class="po-cb w-5 h-5 rounded text-purple-600 border-gray-300 focus:ring-purple-500">' +
                    '<div class="flex-1">' +
                      '<div class="font-black text-gray-800 tracking-tight flex items-center gap-2">' + po.po_number + ' ' + statusBadge + '</div>' +
                      '<div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">' + po.po_date + '</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="text-right">' +
                    '<div class="text-[9px] text-gray-400 font-bold uppercase">Sisa Nilai</div>' +
                    '<div class="font-black text-gray-950">' + rp(po.remaining_value) + '</div>' +
                  '</div>' +
                '</div>' +
                '<div class="po-amount-row hidden mt-2 pl-8 flex items-center gap-2">' +
                  '<span class="text-[10px] text-gray-500 font-bold">DP:</span>' +
                  '<input type="number" step="0.01" min="0" class="po-amount border rounded px-2 py-1 text-sm text-right flex-1" value="' + po.remaining_value + '">' +
                '</div>';

            const cb = label.querySelector('.po-cb');
            const amtRow = label.querySelector('.po-amount-row');
            const amtIn = label.querySelector('.po-amount');
            label._po = po;

            cb.addEventListener('change', () => {
                if (cb.checked){
                    label.classList.add('bg-purple-50', 'border-purple-300');
                    amtRow.classList.remove('hidden');
                } else {
                    label.classList.remove('bg-purple-50', 'border-purple-300');
                    amtRow.classList.add('hidden');
                    amtIn.value = po.remaining_value;
                }
                amountInput.dataset.auto = '1';
                updateTotal();
                autoFillNotesFromPos();
            });

            amtIn.addEventListener('input', () => {
                amountInput.dataset.auto = '1';
                updateTotal();
            });
            amtIn.addEventListener('click', e => e.stopPropagation());
            amtRow.addEventListener('click', e => e.stopPropagation());

            poListEl.appendChild(label);
        });
    }

    function autoFillNotesFromPos(){
        if (!notesInput) return;
        // Hanya auto-isi kalau notes masih kosong atau pernah di-auto-isi
        if (notesInput.value.trim() && notesInput.dataset.auto !== '1') return;
        const checked = Array.from(document.querySelectorAll('.po-item')).filter(l => l.querySelector('.po-cb').checked);
        if (!checked.length){
            if (notesInput.dataset.auto === '1') notesInput.value = '';
            return;
        }
        const refs = checked.map(l => l._po.po_number).join(', ');
        notesInput.value = 'DP untuk ' + refs;
        notesInput.dataset.auto = '1';
    }

    function updateDpBalance(){
        dpBalEl.textContent = rp(currentDpBalance);
        overpayBalEl.textContent = rp(currentOverpayBalance);
        // Disable tombol "Pakai Saldo" kalau supplier tidak punya piutang lebih bayar
        btnUseBalance.disabled = currentOverpayBalance <= 0.01;
        btnUseBalance.style.opacity = currentOverpayBalance <= 0.01 ? '0.4' : '1';
    }

    function clearSaldoApplied(){
        usedBalanceInput.value = 0;
        saldoAppliedInfo.classList.add('hidden');
    }

    function showSaldoApplied(saldoUsed, sisa){
        saldoAppliedAmount.textContent = rp(saldoUsed);
        saldoAppliedSisa.textContent = rp(sisa);
        saldoAppliedInfo.classList.remove('hidden');
    }

    // ── MODE TOGGLE ─────────────────────────────────────────────────────
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.onclick = function(){
            currentMode = this.dataset.mode;
            document.querySelectorAll('.mode-btn').forEach(b => {
                b.classList.remove('bg-blue-600', 'text-white', 'shadow-sm');
                b.classList.add('bg-gray-100', 'text-gray-500');
            });
            this.classList.remove('bg-gray-100', 'text-gray-500');
            this.classList.add('bg-blue-600', 'text-white', 'shadow-sm');
            renderItems();
        };
    });

    // ── ITEMS RENDER ────────────────────────────────────────────────────
    function renderItems(){
        if (currentMode === 'uang_muka'){
            itemsContainer.innerHTML = '';
            itemsContainer.classList.add('hidden');
            dpModeNote.classList.remove('hidden');
            updateTotal();
            return;
        }
        dpModeNote.classList.add('hidden');
        itemsContainer.classList.remove('hidden');

        if (!supId.value){
            itemsContainer.innerHTML = '<div class="text-center py-12 border-2 border-dashed rounded-xl bg-gray-50 border-gray-100 text-xs text-gray-400 italic font-bold">Pilih supplier untuk memulai pembayaran</div>';
            updateTotal();
            return;
        }
        if (!openInvoices.length){
            itemsContainer.innerHTML = '<div class="text-center py-12 italic text-gray-400 text-xs font-bold">Tidak ada invoice outstanding.</div>';
            updateTotal();
            return;
        }

        itemsContainer.innerHTML = '';
        openInvoices.forEach(inv => {
            const tmp = document.createElement('div');
            tmp.innerHTML = itemTpl.trim();
            const item = tmp.firstElementChild;
            item.querySelector('.invoice-no').textContent = inv.invoice_number;
            item.querySelector('.invoice-date').textContent = inv.invoice_date;
            item.querySelector('.invoice-outstanding').textContent = rp(parseFloat(inv.outstanding_amount));
            const cb = item.querySelector('.invoice-cb');
            const amtRow = item.querySelector('.invoice-amount-row');
            const amtIn = item.querySelector('.invoice-amount');
            const idIn = item.querySelector('.invoice-id');
            idIn.value = inv.id;
            amtIn.value = parseFloat(inv.outstanding_amount);
            amtIn.dataset.outstanding = parseFloat(inv.outstanding_amount);

            cb.addEventListener('change', () => {
                if (cb.checked){
                    item.classList.add('bg-blue-50', 'border-blue-300');
                    amtRow.classList.remove('hidden');
                } else {
                    item.classList.remove('bg-blue-50', 'border-blue-300');
                    amtRow.classList.add('hidden');
                    amtIn.value = parseFloat(inv.outstanding_amount);
                }
                amountInput.dataset.auto = '1';
                updateTotal();
            });

            amtIn.addEventListener('input', updateTotal);
            amtIn.addEventListener('click', e => e.stopPropagation());
            amtRow.addEventListener('click', e => e.stopPropagation());

            itemsContainer.appendChild(item);
        });

        updateTotal();
    }

    // ── TOTAL CALCULATION ───────────────────────────────────────────────
    function getCheckedRows(){
        if (currentMode === 'uang_muka'){
            return Array.from(document.querySelectorAll('.po-item'))
                .filter(l => l.querySelector('.po-cb').checked)
                .map(l => ({
                    label: l,
                    amount: parseFloat(l.querySelector('.po-amount').value || 0),
                }));
        }
        return Array.from(document.querySelectorAll('.invoice-item'))
            .filter(l => l.querySelector('.invoice-cb').checked)
            .map(l => ({
                label: l,
                amount: parseFloat(l.querySelector('.invoice-amount').value || 0),
            }));
    }

    function updateTotal(){
        const rows = getCheckedRows();
        const total = rows.reduce((s, r) => s + r.amount, 0);

        // Setiap kali alokasi berubah, reset state "pakai saldo" — user wajib klik ulang.
        clearSaldoApplied();
        totalTagihanGross = total;
        totalSetelahSaldo = total;

        totalTagihanEl.textContent = rp(total);
        // Multi warning hanya untuk mode pelunasan (multi-invoice wajib lunas penuh)
        multiWarning.classList.toggle('hidden', currentMode !== 'pelunasan' || rows.length <= 1);

        if (amountInput.dataset.auto !== '0' || !amountInput.value){
            amountInput.value = total > 0 ? total : '';
            amountInput.dataset.auto = '1';
        }
        updateBreakdown();
    }

    /**
     * Hitung breakdown:
     *   amt        = nominal cash (input "Jumlah Pembayaran")
     *   used       = saldo piutang dipakai (hidden input)
     *   totalDana  = amt + used
     *   allocSum   = sum dari checked rows (alokasi invoice / target DP per PO)
     *   excess     = totalDana - allocSum (≥ 0)
     *
     *   Mode UANG MUKA: allocSum = target DP (1107). excess = overpay (1108).
     *   Mode PELUNASAN: allocSum = pelunasan invoice (Dr AP). excess = DP (1107) [tetap].
     */
    function updateBreakdown(){
        const amt = parseFloat(amountInput.value || 0);
        const used = parseFloat(usedBalanceInput.value || 0);
        const totalDana = amt + used;
        const allocSum = getCheckedRows().reduce((s, r) => s + r.amount, 0);
        const excess = Math.max(0, +(totalDana - allocSum).toFixed(2));

        let dpAmt = 0, overpayAmt = 0;
        if (currentMode === 'uang_muka'){
            // PO amounts adalah target DP. excess → overpay (piutang).
            overpayAmt = excess;
        } else {
            // Pelunasan: excess → DP (perilaku lama).
            dpAmt = excess;
        }
        overpayInput.value = overpayAmt;

        // Card DP (sisa)
        if (dpAmt > 0 && amt > 0){
            dpInfoAmount.textContent = rp(dpAmt);
            dpInfo.classList.remove('hidden');
        } else {
            dpInfo.classList.add('hidden');
        }

        // Card overpay (piutang)
        if (overpayAmt > 0){
            overpayInfoAmount.textContent = rp(overpayAmt);
            overpayInfo.classList.remove('hidden');
        } else {
            overpayInfo.classList.add('hidden');
        }

    }

    amountInput.addEventListener('input', () => {
        amountInput.dataset.auto = '0';
        updateBreakdown();
    });

    // ── PAKAI SALDO — kurangi total tagihan & isi amount sesuai sisa (mirror sales) ──
    btnUseBalance.addEventListener('click', () => {
        const total = totalTagihanGross;
        const saldo = currentOverpayBalance;

        if (total <= 0){ alert('Pilih tagihan / PO terlebih dahulu.'); return; }
        if (saldo <= 0) return;

        const saldoUsed = Math.min(saldo, total);
        const sisa = Math.max(0, +(total - saldoUsed).toFixed(2));

        totalSetelahSaldo = sisa;
        totalTagihanEl.textContent = rp(sisa);

        amountInput.value = sisa;
        amountInput.dataset.auto = '0';

        usedBalanceInput.value = saldoUsed;
        showSaldoApplied(saldoUsed, sisa);
        updateBreakdown();
    });

    btnClearUseBalanceTop.addEventListener('click', () => {
        clearSaldoApplied();
        totalSetelahSaldo = totalTagihanGross;
        totalTagihanEl.textContent = rp(totalTagihanGross);
        amountInput.value = totalTagihanGross > 0 ? totalTagihanGross : '';
        amountInput.dataset.auto = '1';
        updateBreakdown();
    });

    // ── QUICK FILL BUTTONS — pakai totalSetelahSaldo (sisa setelah pakai saldo) ──
    document.getElementById('btnFull').onclick = () => {
        const rows = getCheckedRows();
        const label = currentMode === 'uang_muka' ? 'PO' : 'invoice';
        if (!rows.length){ alert('Pilih ' + label + ' dulu.'); return; }
        amountInput.value = totalSetelahSaldo;
        amountInput.dataset.auto = '0';
        updateBreakdown();
    };

    document.getElementById('btnHalf').onclick = () => {
        const rows = getCheckedRows();
        const label = currentMode === 'uang_muka' ? 'PO' : 'invoice';
        if (rows.length !== 1){ alert('Bayar 50% hanya untuk satu ' + label + '.'); return; }
        if (totalSetelahSaldo <= 0){ alert('Total sisa = 0.'); return; }
        amountInput.value = Math.ceil(totalSetelahSaldo / 2);
        amountInput.dataset.auto = '0';
        updateBreakdown();
    };

    // ── SUBMIT — inject allocations as hidden inputs ────────────────────
    document.querySelector('form').addEventListener('submit', function(e){
        // Hapus hidden allocations lama (kalau resubmit)
        this.querySelectorAll('input[data-alloc]').forEach(el => el.remove());

        if (currentMode === 'pelunasan'){
            const amt = parseFloat(amountInput.value || 0);
            const used = parseFloat(usedBalanceInput.value || 0);
            const totalDana = amt + used;
            let allocSum = 0;
            const allocs = [];
            document.querySelectorAll('.invoice-item').forEach(item => {
                const cb = item.querySelector('.invoice-cb');
                if (cb && cb.checked){
                    const id = item.querySelector('.invoice-id').value;
                    const a = parseFloat(item.querySelector('.invoice-amount').value || 0);
                    if (a > 0){
                        allocs.push({id, amount: a});
                        allocSum += a;
                    }
                }
            });
            if (allocs.length > 1 && Math.abs(allocSum - totalDana) > 0.01){
                e.preventDefault();
                alert('Multi-invoice harus bayar penuh: total alokasi (' + fmt(allocSum) + ') ≠ dana (cash + saldo) (' + fmt(totalDana) + ').');
                return;
            }
            if (allocSum > totalDana + 0.01){
                e.preventDefault();
                alert('Total alokasi melebihi dana yang tersedia (cash + saldo).');
                return;
            }
            allocs.forEach((a, i) => {
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'allocations[' + i + '][purchase_invoice_id]';
                inputId.value = a.id;
                inputId.dataset.alloc = '1';
                this.appendChild(inputId);

                const inputAmt = document.createElement('input');
                inputAmt.type = 'hidden';
                inputAmt.name = 'allocations[' + i + '][amount_applied]';
                inputAmt.value = a.amount;
                inputAmt.dataset.alloc = '1';
                this.appendChild(inputAmt);
            });
        }
        // Uang muka mode: no allocations submitted → backend stores
        //   sum(po_amounts) sebagai DP (1107) via remaining_amount, dan
        //   selisih (amount + used_balance - sum_po) sebagai overpay (1108).
    });

    // ── INIT ────────────────────────────────────────────────────────────
    @if($isEdit && $payment->allocations->count())
        // Saat edit Pelunasan: load supplier data dulu, baru mark checkbox + isi nominal tersimpan
        (async () => {
            await loadSupplierData(supId.value);
            const saved = @json($payment->allocations->map(fn($a) => ['id' => $a->purchase_invoice_id, 'amount' => (float)$a->amount_applied]));
            saved.forEach(s => {
                document.querySelectorAll('.invoice-item').forEach(item => {
                    if (item.querySelector('.invoice-id').value == s.id){
                        const cb = item.querySelector('.invoice-cb');
                        cb.checked = true;
                        cb.dispatchEvent(new Event('change'));
                        item.querySelector('.invoice-amount').value = s.amount;
                    }
                });
            });
            amountInput.dataset.auto = '0';
            amountInput.value = {{ $payment->amount }};
            updateTotal();
            // Restore state pakai-saldo (updateTotal mereset).
            const savedUsed = {{ (float) ($payment->used_balance ?? 0) }};
            if (savedUsed > 0){
                usedBalanceInput.value = savedUsed;
                totalSetelahSaldo = Math.max(0, +(totalTagihanGross - savedUsed).toFixed(2));
                totalTagihanEl.textContent = rp(totalSetelahSaldo);
                showSaldoApplied(savedUsed, totalSetelahSaldo);
            }
            overpayInput.value = {{ (float) ($payment->overpay ?? 0) }};
            updateBreakdown();
        })();
    @elseif($isEdit)
        // Saat edit Uang Muka (tanpa allocations): aktifkan tab Uang Muka, load PO, auto-check PO dari catatan
        document.querySelector('.mode-btn[data-mode="uang_muka"]').click();
        if (supId.value){
            (async () => {
                await loadSupplierData(supId.value);
                // Parsing PO number dari catatan (format auto-fill: "DP untuk PO/.../..., PO/.../...")
                const notesVal = notesInput ? notesInput.value : '';
                const poNumbers = (notesVal.match(/PO\/[^,\s]+/g) || []).map(s => s.trim());
                const matched = [];
                poNumbers.forEach(poNum => {
                    document.querySelectorAll('.po-item').forEach(item => {
                        if (item._po && item._po.po_number === poNum) matched.push(item);
                    });
                });
                matched.forEach(item => {
                    const cb = item.querySelector('.po-cb');
                    if (cb && !cb.checked){
                        cb.checked = true;
                        cb.dispatchEvent(new Event('change'));
                    }
                });
                // Single-PO: samakan nominal row dengan amount tersimpan (handle DP parsial)
                // Catatan: kalau ada overpay, po-amount = remaining_amount, bukan amount.
                const remainingDp = {{ (float) $payment->remaining_amount }};
                if (matched.length === 1){
                    matched[0].querySelector('.po-amount').value = remainingDp > 0 ? remainingDp : {{ (float) $payment->amount }};
                }
                amountInput.value = {{ $payment->amount }};
                amountInput.dataset.auto = '0';
                updateTotal();
                // Restore state pakai-saldo (updateTotal mereset).
                const savedUsed = {{ (float) ($payment->used_balance ?? 0) }};
                if (savedUsed > 0){
                    usedBalanceInput.value = savedUsed;
                    totalSetelahSaldo = Math.max(0, +(totalTagihanGross - savedUsed).toFixed(2));
                    totalTagihanEl.textContent = rp(totalSetelahSaldo);
                    showSaldoApplied(savedUsed, totalSetelahSaldo);
                }
                overpayInput.value = {{ (float) ($payment->overpay ?? 0) }};
                updateBreakdown();
            })();
        }
    @else
        @php $prefillPoId = (int) request('po_id', 0); @endphp
        if (supId.value){
            @if($prefillPoId > 0)
                // Datang dari tombol "DP" di PO list: switch ke mode Uang Muka & auto-check PO tsb
                (async () => {
                    document.querySelector('.mode-btn[data-mode="uang_muka"]').click();
                    await loadSupplierData(supId.value);
                    const target = Array.from(document.querySelectorAll('.po-item'))
                        .find(item => item._po && item._po.id === {{ $prefillPoId }});
                    if (target){
                        const cb = target.querySelector('.po-cb');
                        if (cb && !cb.checked){
                            cb.checked = true;
                            cb.dispatchEvent(new Event('change'));
                        }
                        target.scrollIntoView({behavior:'smooth', block:'center'});
                    }
                })();
            @else
                loadSupplierData(supId.value);
            @endif
        }
    @endif
})();
</script>
