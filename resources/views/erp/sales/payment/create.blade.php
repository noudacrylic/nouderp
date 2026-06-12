@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">Pembayaran Pelanggan Baru</h1>
        <p class="text-xs text-gray-500">Terima pembayaran pelanggan untuk Pelunasan (Faktur/Billing) atau Uang Muka (SO/Billing).</p>
    </div>

    <form action="{{ route('sales.payment.store') }}" method="POST" id="payment_form">
        @csrf
        <input type="hidden" name="payment_mode" id="payment_mode" value="pelunasan">
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            
            <!-- LEFT: CUSTOMER & LIST -->
            <div class="bg-white border rounded-lg p-4 shadow-sm overflow-visible">
                <div class="mb-4">
                    <div class="relative autocomplete-container">
                        <input type="text" id="customer_search" class="w-full border rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition" placeholder="Ketik nama pelanggan..." autocomplete="off">
                        <input type="hidden" name="customer_id" id="customer_id" required>
                        <div id="customer_dropdown" class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl hidden max-h-60 overflow-y-auto"></div>
                    </div>
                    <div class="text-[10px] text-gray-500 mt-2 pl-1 flex justify-between">
                        <span>Saldo Tersedia: <span id="display_customer_balance" class="font-bold text-green-600">Rp 0</span></span>
                    </div>
                </div>

                <div class="mb-3 flex items-center justify-between border-b pb-2">
                    <div class="flex gap-2" id="mode_toggle">
                        <button type="button" data-mode="pelunasan" class="mode-btn px-4 py-1.5 rounded-lg text-xs font-bold transition-all shadow-sm bg-blue-600 text-white">
                            Pelunasan
                        </button>
                        <button type="button" data-mode="uang_muka" class="mode-btn px-4 py-1.5 rounded-lg text-xs font-bold transition-all bg-gray-100 text-gray-500 hover:bg-gray-200">
                            Uang Muka
                        </button>
                    </div>
                </div>

                {{-- TOTAL ROW -- label + amount + "Pakai Saldo" inline --}}
                <div class="mb-3 border rounded-xl p-4 text-sm bg-blue-50 shadow-inner">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-black text-blue-800 uppercase tracking-widest">Total:</span>
                        <div class="flex items-center gap-2">
                            <span id="total_tagihan" class="text-lg font-black text-blue-950">Rp 0</span>
                            <button type="button"
                                id="btn_use_balance"
                                title="Kurangi total dengan saldo pelanggan"
                                class="text-[10px] font-black uppercase tracking-wide bg-white border border-blue-200 text-blue-600 px-2 py-1 rounded-lg shadow-sm hover:bg-blue-600 hover:text-white transition active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed">
                                Pakai Saldo
                            </button>
                        </div>
                    </div>
                    {{-- Balance-applied indicator --}}
                    <div id="saldo_applied_info" class="mt-1.5 text-[10px] text-green-700 font-bold hidden">
                        ✓ Saldo <span id="saldo_applied_amount"></span> diterapkan · sisa bayar: <span id="saldo_applied_sisa"></span>
                    </div>
                </div>

                <div id="items_list_container" class="space-y-2 max-h-[450px] overflow-y-auto pr-1 custom-scrollbar">
                    <div class="text-center py-12 border-2 border-dashed rounded-xl bg-gray-50 border-gray-100 text-xs text-gray-400 italic font-bold">
                        Pilih pelanggan untuk memulai pembayaran
                    </div>
                </div>
            </div>

            <!-- RIGHT: PAYMENT SETTINGS -->
            <div class="bg-white border rounded-lg p-5 shadow-sm space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Tanggal Bayar</label>
                        <input type="date" name="date" class="border rounded-lg px-3 py-2 text-sm w-full focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Setor Ke (Kas/Bank)</label>
                        <select name="cash_account_id" class="border-2 border-blue-400 bg-blue-50 rounded-lg px-2 py-2 text-sm w-full font-bold focus:ring-0 outline-none transition" required>
                            <option value="">-- PILIH AKUN KAS --</option>
                            @foreach($cashAccounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="p-6 bg-gray-50 border rounded-2xl shadow-inner border-gray-100">
                    <label class="block text-xs font-bold text-gray-600 mb-3 uppercase tracking-widest text-center">Jumlah Pembayaran</label>
                    <div class="flex items-center bg-white border-2 border-transparent rounded-xl px-4 mb-2 shadow-sm focus-within:border-blue-500 transition group">
                        <span class="text-lg font-black text-gray-300 pr-2 group-focus-within:text-blue-500">Rp</span>
                        <input type="text" inputmode="numeric" id="amount" name="amount" class="rupiah-input w-full py-4 text-2xl font-black outline-none bg-transparent" placeholder="0" required>
                    </div>

                    <div id="payment_warning" class="text-[10px] text-orange-600 mt-2 font-bold hidden bg-orange-50 p-2 rounded-lg border border-orange-100 italic text-center">
                        ⚠ Warning: Pembayaran multi-dokumen wajib lunas penuh.
                    </div>
                    {{-- Quick-fill buttons: Bayar Penuh + Bayar 50% --}}
                    <div class="flex gap-3 mt-4">
                        <button type="button" id="btn_full"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-xs font-black shadow-lg shadow-blue-200 transition active:scale-95 flex-1">
                            BAYAR PENUH
                        </button>
                        <button type="button" id="btn_half"
                            class="bg-white border border-gray-200 hover:bg-gray-100 text-gray-700 px-4 py-2 rounded-xl text-xs font-black shadow-sm transition active:scale-95 flex-1">
                            BAYAR 50%
                        </button>
                    </div>

                    {{-- ── ADMIN FEE SECTION (SINGLE BLOCK) ── --}}
                    <div class="mt-6 pt-6 border-t border-dashed border-gray-200">
                        <div class="flex justify-between items-center mb-1">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Biaya Admin (Opsional)</label>
                        </div>
                        
                        <div id="fee_info_warning" class="hidden mb-3 p-2 bg-orange-50 border border-orange-100 rounded-lg">
                            <p class="text-[10px] text-orange-600 font-bold mb-0">⚠️ <span id="fee_info_label"></span></p>
                        </div>

                        <div class="flex gap-2 items-end">
                            <div class="flex-1">
                                <label class="block text-[8px] font-bold text-gray-400 mb-1 uppercase tracking-widest pl-1">Nominal Fee</label>
                                <div class="flex items-center bg-white border rounded-lg px-3 py-1 shadow-sm focus-within:ring-2 focus-within:ring-blue-100 transition" id="admin_fee_container">
                                    <span class="text-xs font-bold text-gray-400 pr-1">Rp</span>
                                    <input type="text" inputmode="numeric" id="admin_fee" name="admin_fee" class="rupiah-input w-full py-1 text-sm font-bold outline-none bg-transparent text-red-600" value="0">
                                </div>
                            </div>
                            <button type="button" id="btn_calc_fee" class="bg-gray-800 text-white text-[10px] font-black px-4 py-2 rounded-lg hover:bg-black transition active:scale-95 h-[34px] flex items-center shadow-lg shadow-gray-200">
                                HITUNG
                            </button>
                        </div>

                        <div class="mt-3 py-2 px-3 bg-gray-50 rounded-lg flex justify-between items-center border border-gray-100">
                            <span class="text-[10px] text-gray-500 font-medium font-bold uppercase tracking-widest">Kas Masuk (Net):</span>
                            <span class="text-xs font-black text-gray-800">Rp <span id="net_amount_display">0</span></span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Keterangan / Memo</label>
                    <textarea name="notes" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition" rows="3" placeholder="Contoh: Pembayaran pelunasan via Bank Transfer..."></textarea>
                </div>

                <div class="pt-6 border-t flex gap-4">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-xl text-sm font-black transition shadow-lg shadow-green-200 active:scale-95 flex-1 uppercase">Simpan &amp; Posting</button>
                    <a href="{{ route('sales.payment.index') }}" class="px-6 py-3 text-sm font-bold text-gray-400 hover:text-gray-600 transition">Batal</a>
                </div>
            </div>

        </div>
    </form>
</div>

<style>
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

    .autocomplete-item {
        padding: 8px 12px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .autocomplete-item:hover {
        background-color: #f3f4f6;
    }
    .autocomplete-item .cust-code {
        font-size: 10px;
        color: #9ca3af;
        margin-left: 4px;
    }
</style>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    let currentMode    = 'pelunasan';
    let rawData        = { invoices: [], billings: [], sales_orders: [] };

    // ── Globals (referenced by multiple functions) ─────────────────────────
    window.customerBalance  = 0;   // saldo overpayment customer
    window.totalTagihan     = 0;   // total before saldo
    window.totalSetelahSaldo = 0;  // total after "pakai saldo" click

    // ── Autocomplete Search Logic ──────────────────────────────────────────
    const searchInput = document.getElementById('customer_search');
    const hiddenIdInput = document.getElementById('customer_id');
    const dropdown = document.getElementById('customer_dropdown');
    let searchTimeout;

    searchInput.addEventListener('focus', function() {
        if (!this.value) fetchTopCustomers();
    });

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value;
        
        if (!query) {
            hiddenIdInput.value = '';
            resetAll();
            fetchTopCustomers();
            return;
        }

        searchTimeout = setTimeout(() => fetchCustomers(query), 300);
    });

    function fetchTopCustomers() {
        fetch('/erp/sales/ajax/search-customers?q=')
            .then(res => res.json())
            .then(data => renderDropdown(data));
    }

    function fetchCustomers(query) {
        fetch(`/erp/sales/ajax/search-customers?q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => renderDropdown(data));
    }

    function renderDropdown(data) {
        dropdown.innerHTML = '';
        if (data.length === 0) {
            dropdown.innerHTML = '<div class="p-3 text-xs text-gray-400 italic">Pelanggan tidak ditemukan</div>';
        } else {
            data.forEach(cust => {
                const div = document.createElement('div');
                div.className = 'autocomplete-item text-sm border-b border-gray-50 last:border-0 hover:bg-blue-50';
                div.innerHTML = `
                    <div class="font-bold text-gray-700">${cust.name}</div>
                    <div class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">${cust.code ?? ''}</div>
                `;
                div.onclick = function() {
                    searchInput.value = cust.name;
                    hiddenIdInput.value = cust.id;
                    dropdown.classList.add('hidden');
                    loadCustomerData(cust.id);
                };
                dropdown.appendChild(div);
            });
        }
        dropdown.classList.remove('hidden');
    }

    // Close dropdown on click outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });

    // ── Reset ───────────────────────────────────────────────────────────────
    function resetAll() {
        window.customerBalance   = 0;
        window.totalTagihan      = 0;
        window.totalSetelahSaldo = 0;
        rawData = { invoices: [], billings: [], sales_orders: [] };
        updateUI();
        renderItems();
        clearSaldoInfo();
    }

    // ── Load remote data ────────────────────────────────────────────────────
    function loadCustomerData(id) {
        fetch(`/erp/sales/ajax/customer-summary/${id}`)
            .then(res => res.json())
            .then(data => {
                window.customerBalance = parseFloat(data.balance || 0);
                updateUI();
            });

        fetch(`/erp/sales/ajax/customer-open-items/${id}`)
            .then(res => res.json())
            .then(data => {
                rawData = data;
                renderItems();
            });
    }

    // ── Update balance display + button state ───────────────────────────────
    function updateUI() {
        document.getElementById('display_customer_balance').innerText =
            'Rp ' + window.customerBalance.toLocaleString();
        const balBtn = document.getElementById('btn_use_balance');
        balBtn.disabled = window.customerBalance <= 0;
        balBtn.style.opacity = window.customerBalance <= 0 ? '0.4' : '1';
    }

    function clearSaldoInfo() {
        document.getElementById('saldo_applied_info').classList.add('hidden');
    }

    function showSaldoInfo(saldoUsed, sisa) {
        if (saldoUsed <= 0) { clearSaldoInfo(); return; }
        document.getElementById('saldo_applied_amount').innerText = 'Rp ' + saldoUsed.toLocaleString();
        document.getElementById('saldo_applied_sisa').innerText   = 'Rp ' + sisa.toLocaleString();
        document.getElementById('saldo_applied_info').classList.remove('hidden');
    }

    // ── MODE TOGGLE ─────────────────────────────────────────────────────────
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.onclick = function() {
            currentMode = this.dataset.mode;
            document.getElementById('payment_mode').value = currentMode;
            document.querySelectorAll('.mode-btn').forEach(b => {
                b.classList.remove('bg-blue-600', 'text-white', 'shadow-sm');
                b.classList.add('bg-gray-100', 'text-gray-500');
            });
            this.classList.remove('bg-gray-100', 'text-gray-500');
            this.classList.add('bg-blue-600', 'text-white', 'shadow-sm');
            clearSaldoInfo();
            renderItems();
        };
    });

    // ── Render items list ───────────────────────────────────────────────────
    function renderItems() {
        const container = document.getElementById('items_list_container');
        let items = [];
        let itemTypeLabel = "";

        if (currentMode === 'pelunasan') {
            const invoices = (rawData.invoices || []).map(i => ({...i, type: 'invoice'}));
            const billings = (rawData.billings || []).filter(b => b.billing_type === 'invoice').map(b => ({...b, type: 'billing'}));
            items = [...invoices, ...billings];
            itemTypeLabel = "tagihan pelunasan";
        } else {
            const sos = (rawData.sales_orders || []).map(s => ({...s, type: 'sales_order'}));
            const billings = (rawData.billings || []).filter(b => b.billing_type === 'sales_order').map(b => ({...b, type: 'billing'}));
            items = [...sos, ...billings];
            itemTypeLabel = "tagihan uang muka";
        }

        if (items.length === 0) {
            container.innerHTML = `<div class="text-center py-12 italic text-gray-400 text-xs font-bold">Tidak ada ${itemTypeLabel} tersedia</div>`;
            updateTotal();
            return;
        }

        const selectableCount = items.filter(i => !(i.is_locked || false)).length;

        let html = '';

        if (selectableCount > 1) {
            html += `
                <label class="block border rounded-xl p-3 text-xs flex justify-between items-center bg-gray-50 border-dashed border-gray-300 hover:bg-blue-50 cursor-pointer transition sticky top-0 z-10">
                    <div class="flex items-center gap-4">
                        <input type="checkbox" id="select_all_items" class="w-5 h-5 rounded-lg text-blue-600 border-gray-300 focus:ring-blue-500">
                        <span class="font-black uppercase tracking-widest text-gray-600">Pilih Semua (${selectableCount})</span>
                    </div>
                    <span class="text-[10px] font-bold text-gray-400 italic">Klik untuk toggle</span>
                </label>
            `;
        }

        items.forEach(item => {
            const amount     = parseFloat(item.remaining || 0);
            const ref        = item.invoice_number || item.billing_number || item.order_number;
            const isLocked   = item.is_locked || false;
            const badge      = item.type === 'billing'
                ? '<span class="bg-purple-100 text-purple-600 px-1.5 py-0.5 rounded text-[8px] font-black uppercase border border-purple-200 ml-1">Kolektif</span>'
                : '';

            html += `
                <label class="block border rounded-xl p-4 text-sm flex justify-between items-center transition 
                    ${isLocked ? 'bg-gray-50 opacity-60 cursor-not-allowed border-gray-100' : 'hover:bg-blue-50 cursor-pointer border-gray-200 hover:border-blue-200'}">
                    <div class="flex items-center gap-4">
                        <input type="checkbox" name="item_ids[]" value="${item.id}" data-amount="${amount}" data-type="${item.type}"
                            ${isLocked ? 'disabled' : ''}
                            class="item-checkbox w-5 h-5 rounded-lg text-blue-600 border-gray-300 focus:ring-blue-500">
                        <div>
                            <div class="flex items-center gap-1">
                                <span class="font-black text-gray-800 tracking-tight">${ref}</span>
                                ${badge}
                            </div>
                            <div class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">
                                ${isLocked ? `<span class="text-red-500">${item.lock_reason}</span>` : (item.date || item.invoice_date || item.order_date || 'N/A')}
                            </div>
                        </div>
                    </div>
                    <span class="font-black text-gray-950">Rp ${amount.toLocaleString()}</span>
                </label>
            `;
        });
        container.innerHTML = html;

        document.querySelectorAll('input[name="item_ids[]"]').forEach(cb => {
            cb.addEventListener('change', function() {
                if (!this.disabled) {
                    this.closest('label').classList.toggle('bg-blue-50', this.checked);
                    this.closest('label').classList.toggle('border-blue-300', this.checked);
                }
                // Reset saldo setiap kali pilihan berubah
                clearSaldoInfo();
                syncSelectAllState();
                updateTotal();
            });
        });

        const selectAll = document.getElementById('select_all_items');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                document.querySelectorAll('input[name="item_ids[]"]').forEach(cb => {
                    if (cb.disabled) return;
                    cb.checked = this.checked;
                    cb.closest('label').classList.toggle('bg-blue-50', this.checked);
                    cb.closest('label').classList.toggle('border-blue-300', this.checked);
                });
                clearSaldoInfo();
                updateTotal();
            });
        }

        updateTotal();
    }

    function syncSelectAllState() {
        const selectAll = document.getElementById('select_all_items');
        if (!selectAll) return;
        const boxes = document.querySelectorAll('input[name="item_ids[]"]:not([disabled])');
        const checked = document.querySelectorAll('input[name="item_ids[]"]:not([disabled]):checked');
        selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }

    // Format angka → tampilan Rupiah Indonesia, MEMPERTAHANKAN desimal (sen) bila ada.
    // formatThousands global bersifat integer-only (membuang titik desimal) sehingga
    // total bersen seperti 5.041.418,18 jadi 504.141.818 (100×). Helper ini memperbaikinya.
    function fmtAmount(n) {
        n = Number(n) || 0;
        const hasFrac = Math.abs(n - Math.round(n)) > 1e-9;
        return n.toLocaleString('id-ID', { minimumFractionDigits: hasFrac ? 2 : 0, maximumFractionDigits: 2 });
    }

    // ── updateTotal — realtime, always resets saldo state ──────────────────
    // ⚠  Setiap kali checkbox berubah, saldo reset (user harus klik lagi)
    function updateTotal() {
        let total = 0;
        document.querySelectorAll('input[name="item_ids[]"]:checked')
            .forEach(cb => { total += parseFloat(cb.dataset.amount || 0); });

        window.totalTagihan      = total;
        window.totalSetelahSaldo = total;   // reset to gross total

        document.getElementById('total_tagihan').innerText = 'Rp ' + total.toLocaleString();

        // Auto-fill amount (unless user has manually overridden)
        const amountInput = document.getElementById('amount');
        const checked     = document.querySelectorAll('input[name="item_ids[]"]:checked');

        const warning = document.getElementById('payment_warning');
        if (checked.length > 1) {
            warning.classList.remove('hidden');
            // Force amount to full for multi-doc
            amountInput.value      = fmtAmount(total);
            amountInput.dataset.auto = '1';
        } else {
            warning.classList.add('hidden');
            if (checked.length === 1 && (window.cleanNumber(amountInput.value) === 0 || amountInput.dataset.auto === '1')) {
                amountInput.value      = fmtAmount(total);
                amountInput.dataset.auto = '1';
            }
        }
    }

    // ── Manual amount edit resets auto flag ────────────────────────────────
    // ── Admin Fee logic ──
    const amountInput = document.getElementById('amount');
    const adminFeeInput = document.getElementById('admin_fee');
    const cashAccountSelect = document.querySelector('select[name="cash_account_id"]');
    const feeAccountSelect = document.getElementById('fee_account_id');
    const netDisplay = document.getElementById('net_amount_display');
    const btnCalcFee = document.getElementById('btn_calc_fee');
    const feeWarning = document.getElementById('fee_info_warning');
    const feeLabel = document.getElementById('fee_info_label');
    const feeContainer = document.getElementById('admin_fee_container');

    function updateNet() {
        const amt = window.cleanNumber(amountInput.value);
        const fee = window.cleanNumber(adminFeeInput.value);
        const net = amt - fee;
        netDisplay.innerText = net.toLocaleString();

        // Highlight if fee > 0
        if (fee > 0) {
            feeContainer.classList.add('bg-yellow-50', 'border-yellow-200');
        } else {
            feeContainer.classList.remove('bg-yellow-50', 'border-yellow-200');
        }

        if (fee > amt && amt > 0) {
            adminFeeInput.classList.add('text-red-600');
            feeContainer.classList.add('border-red-500', 'bg-red-50');
        } else {
            feeContainer.classList.remove('border-red-500');
        }
    }

    async function checkFeeSetting() {
        const cashId = cashAccountSelect.value;
        if (!cashId) {
            feeWarning.classList.add('hidden');
            return;
        }

        const res = await fetch(`/erp/sales/ajax/check-fee?cash_account_id=${cashId}`);
        const data = await res.json();

        if (data.has_fee) {
            feeLabel.innerText = `Kas ini memiliki biaya admin (${data.label})`;
            feeWarning.classList.remove('hidden');
        } else {
            feeWarning.classList.add('hidden');
        }
    }

    async function calculateFee() {
        const cashId = cashAccountSelect.value;
        const amt = window.cleanNumber(amountInput.value);

        if (!cashId || amt <= 0) {
            alert('Pilih Akun Kas dan isi Jumlah Bayar terlebih dahulu.');
            return;
        }

        btnCalcFee.disabled = true;
        btnCalcFee.innerText = 'WAIT...';

        try {
            const res = await fetch(`/erp/sales/ajax/calculate-fee?cash_account_id=${cashId}&amount=${amt}`);
            const data = await res.json();
            
            adminFeeInput.value = window.formatThousands(String(data.fee));
            // Expense account handled on backend now
            updateNet();
        } catch (e) {
            console.error(e);
        } finally {
            btnCalcFee.disabled = false;
            btnCalcFee.innerText = 'HITUNG';
        }
    }

    btnCalcFee.onclick = calculateFee;

    cashAccountSelect.addEventListener('change', function() {
        checkFeeSetting();
        updateNet();
    });

    amountInput.addEventListener('input', function() {
        this.dataset.auto = '0';
        updateNet();
    });

    adminFeeInput.addEventListener('input', updateNet);

    // Initial calculation
    updateNet();

    // ── PAKAI SALDO — kurangi total dengan saldo, isi amount ───────────────
    document.getElementById('btn_use_balance').onclick = function() {
        const total  = window.totalTagihan || 0;
        const saldo  = window.customerBalance || 0;

        if (total <= 0) { alert('Pilih tagihan terlebih dahulu.'); return; }
        if (saldo <= 0) return;

        const saldoUsed = Math.min(saldo, total);
        let   sisa      = total - saldoUsed;
        if (sisa < 0) sisa = 0;

        window.totalSetelahSaldo = sisa;

        // Update total display
        document.getElementById('total_tagihan').innerText = 'Rp ' + sisa.toLocaleString();

        // Fill amount
        document.getElementById('amount').value      = fmtAmount(sisa);
        document.getElementById('amount').dataset.auto = '0';

        showSaldoInfo(saldoUsed, sisa);
        updateNet();
    };

    // ── BAYAR PENUH ────────────────────────────────────────────────────────
    document.getElementById('btn_full').onclick = function() {
        const total = window.totalSetelahSaldo || 0;
        document.getElementById('amount').value      = fmtAmount(total);
        document.getElementById('amount').dataset.auto = '0';
        updateNet();
    };

    // ── BAYAR 50% ──────────────────────────────────────────────────────────
    document.getElementById('btn_half').onclick = function() {
        const total = window.totalSetelahSaldo || 0;
        if (total <= 0) { alert('Pilih tagihan terlebih dahulu.'); return; }

        const checked = document.querySelectorAll('input[name="item_ids[]"]:checked');
        if (checked.length > 1) {
            alert('⚠ Bayar 50% hanya untuk satu tagihan. Multi-faktur wajib bayar penuh.');
            return;
        }

        document.getElementById('amount').value      = fmtAmount(Math.ceil(total / 2));
        document.getElementById('amount').dataset.auto = '0';
        updateNet();
    };

    // ── SUBMIT validation ───────────────────────────────────────────────────
    document.getElementById('payment_form').onsubmit = function() {
        const checked = document.querySelectorAll('input[name="item_ids[]"]:checked');
        if (checked.length === 0) {
            alert('⚠️ Pilih dulu minimal satu tagihan yang mau dibayar.');
            return false;
        }

        const totalNet   = window.totalSetelahSaldo || 0;
        const amount     = window.cleanNumber(document.getElementById('amount').value);

        // Multi-invoice: harus bayar penuh (after saldo)
        if (checked.length > 1 && amount < (totalNet - 1)) {
            alert('❌ Multi-faktur harus bayar penuh atau gunakan billing.\nSisa tagihan: Rp ' + totalNet.toLocaleString());
            return false;
        }

        // Attach item_details JSON for backend type routing
        const itemData = Array.from(checked).map(cb => ({ id: cb.value, type: cb.dataset.type }));
        const input    = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'item_details';
        input.value = JSON.stringify(itemData);
        this.appendChild(input);

        // Normalisasi ke angka polos berdesimal (mis. "5041418.18") agar lolos validasi
        // `numeric` di backend & mempertahankan sen. Strip titik global (capture) sudah jalan
        // duluan, jadi di sini kita set ulang dari nilai bersih.
        const amtField = document.getElementById('amount');
        amtField.value = String(window.cleanNumber(amtField.value));
        const feeField = document.getElementById('admin_fee');
        if (feeField) feeField.value = String(window.cleanNumber(feeField.value));

        return true;
    };

    // ── PREFILL via query params (dari SO index → tombol DP) ──────────────
    @if(!empty($prefillCustomer))
    (function () {
        const prefillCustomerId = {{ (int) $prefillCustomer->id }};
        const prefillCustomerName = @json($prefillCustomer->name);
        const prefillSoId = {{ $prefillSoId ? (int) $prefillSoId : 'null' }};
        const prefillInvoiceId = {{ !empty($prefillInvoiceId) ? (int) $prefillInvoiceId : 'null' }};
        const prefillMode = @json($prefillMode);

        searchInput.value = prefillCustomerName;
        hiddenIdInput.value = prefillCustomerId;
        loadCustomerData(prefillCustomerId);

        if (prefillMode === 'uang_muka') {
            const umBtn = document.querySelector('.mode-btn[data-mode="uang_muka"]');
            if (umBtn) umBtn.click();
        }

        // Auto-centang dokumen target begitu daftar tagihan selesai di-render.
        function autoCheckItem(type, value) {
            const observer = new MutationObserver(() => {
                const cb = document.querySelector(
                    'input[name="item_ids[]"][data-type="' + type + '"][value="' + value + '"]'
                );
                if (cb && !cb.disabled && !cb.checked) {
                    cb.checked = true;
                    cb.dispatchEvent(new Event('change'));
                    observer.disconnect();
                }
            });
            observer.observe(
                document.getElementById('items_list_container'),
                { childList: true, subtree: true }
            );
        }

        if (prefillSoId) autoCheckItem('sales_order', prefillSoId);
        if (prefillInvoiceId) autoCheckItem('invoice', prefillInvoiceId);
    })();
    @endif
});
</script>
@endpush
