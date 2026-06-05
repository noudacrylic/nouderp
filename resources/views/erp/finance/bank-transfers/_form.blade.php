@php
    $isEdit = isset($bt);
    $defaultAdminFeeAccountId = $defaultAdminFeeAccountId ?? null;
    $defaults = $isEdit ? [
        'date' => optional($bt->date)->format('Y-m-d'),
        'from_account_id' => $bt->from_account_id,
        'to_account_id' => $bt->to_account_id,
        'admin_fee_account_id' => $bt->admin_fee_account_id,
        'amount' => (float) $bt->amount,
        'admin_fee' => (float) $bt->admin_fee,
        'fee_borne_by' => $bt->fee_borne_by ?: 'source',
        'reference' => $bt->reference,
        'notes' => $bt->notes,
    ] : [
        'date' => old('date', now()->toDateString()),
        'from_account_id' => old('from_account_id'),
        'to_account_id' => old('to_account_id'),
        'admin_fee_account_id' => old('admin_fee_account_id', $defaultAdminFeeAccountId),
        'amount' => old('amount', 0),
        'admin_fee' => old('admin_fee', 0),
        'fee_borne_by' => old('fee_borne_by', 'source'),
        'reference' => old('reference'),
        'notes' => old('notes'),
    ];

    $cashAccountById = $cashAccounts->keyBy('id');
    $expenseAccountById = $expenseAccounts->keyBy('id');
    $fromInitialLabel = ($a = $cashAccountById->get($defaults['from_account_id'])) ? $a->code.' — '.$a->name : '';
    $toInitialLabel   = ($a = $cashAccountById->get($defaults['to_account_id']))   ? $a->code.' — '.$a->name : '';
    $feeInitialLabel  = ($a = $expenseAccountById->get($defaults['admin_fee_account_id'])) ? $a->code.' — '.$a->name : '';
@endphp

<div class="space-y-4">
    @if(session('error') || $errors->any())
        <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm">
            {{ session('error') }}
            @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
        </div>
    @endif

    {{-- Header strip --}}
    <div class="bg-white rounded shadow p-4">
        <div class="grid grid-cols-12 gap-3 text-sm">
            <div class="col-span-3">
                <label class="block text-xs text-gray-500 mb-1">Tanggal <span class="text-red-500">*</span></label>
                <input type="date" name="date" value="{{ $defaults['date'] }}" class="border rounded px-2 h-9 w-full" required>
            </div>
            <div class="col-span-9">
                <label class="block text-xs text-gray-500 mb-1">Referensi</label>
                <input type="text" name="reference" value="{{ $defaults['reference'] }}" class="border rounded px-2 h-9 w-full" placeholder="Misal: nomor mutasi bank">
            </div>
        </div>
    </div>

    {{-- Transfer Uang: 2 kolom (kiri DARI + nilai, kanan KE) --}}
    <div class="bg-white rounded shadow p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Transfer Uang</h3>
        <div class="grid grid-cols-2 gap-6">
            {{-- KIRI: DARI --}}
            <div class="space-y-3 border-r pr-6">
                <div>
                    <label class="block text-xs font-semibold text-rose-700 mb-1">DARI KAS / BANK <span class="text-red-500">*</span></label>
                    <input type="text" id="fromAccountSearch" list="cashAccountList"
                           value="{{ $fromInitialLabel }}"
                           placeholder="Ketik kode / nama kas atau bank…"
                           class="border border-rose-400 bg-rose-50 rounded px-2 h-9 w-full font-semibold text-rose-900 account-search"
                           autocomplete="off" required>
                    <input type="hidden" name="from_account_id" id="fromAccount" value="{{ $defaults['from_account_id'] }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jumlah Transfer <span class="text-red-500">*</span></label>
                    <input type="text" inputmode="numeric" name="amount" id="amount"
                           value="{{ $defaults['amount'] }}" class="border rounded px-2 h-9 w-full text-right text-base font-semibold rupiah-input" required>
                </div>
            </div>

            {{-- KANAN: KE --}}
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-emerald-700 mb-1">KE KAS / BANK <span class="text-red-500">*</span></label>
                    <input type="text" id="toAccountSearch" list="cashAccountList"
                           value="{{ $toInitialLabel }}"
                           placeholder="Ketik kode / nama kas atau bank…"
                           class="border border-emerald-500 bg-emerald-50 rounded px-2 h-9 w-full font-semibold text-emerald-900 account-search"
                           autocomplete="off" required>
                    <input type="hidden" name="to_account_id" id="toAccount" value="{{ $defaults['to_account_id'] }}">
                </div>
            </div>
        </div>
    </div>

    {{-- Biaya Admin section --}}
    <div class="bg-white rounded shadow p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Biaya Admin Bank</h3>
        <div class="grid grid-cols-12 gap-3 text-sm">
            <div class="col-span-3">
                <label class="block text-xs text-gray-500 mb-1">Nominal Biaya</label>
                <input type="text" inputmode="numeric" name="admin_fee" id="adminFee"
                       value="{{ $defaults['admin_fee'] }}" class="border rounded px-2 h-9 w-full text-right rupiah-input">
            </div>
            <div class="col-span-5">
                <label class="block text-xs text-gray-500 mb-1">Akun Beban Admin</label>
                <input type="text" id="adminFeeAccountSearch" list="expenseAccountList"
                       value="{{ $feeInitialLabel }}"
                       placeholder="Ketik kode / nama akun beban (wajib jika ada biaya)…"
                       class="border rounded px-2 h-9 w-full" autocomplete="off">
                <input type="hidden" name="admin_fee_account_id" id="adminFeeAccount" value="{{ $defaults['admin_fee_account_id'] }}">
            </div>
            <div class="col-span-4">
                <label class="block text-xs text-gray-500 mb-1">Ditanggung Oleh</label>
                <div class="flex gap-3 pt-1.5 text-sm">
                    <label class="inline-flex items-center gap-1 cursor-pointer">
                        <input type="radio" name="fee_borne_by" value="source" @checked($defaults['fee_borne_by']==='source')>
                        <span>Sumber (Dari)</span>
                    </label>
                    <label class="inline-flex items-center gap-1 cursor-pointer">
                        <input type="radio" name="fee_borne_by" value="destination" @checked($defaults['fee_borne_by']==='destination')>
                        <span>Tujuan (Ke)</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    {{-- Catatan + Summary 2-kolom --}}
    <div class="grid grid-cols-2 gap-4">
        <div class="bg-white rounded shadow p-4">
            <label class="block text-xs text-gray-500 mb-1">Catatan</label>
            <textarea name="notes" rows="4" class="border rounded px-2 py-1.5 w-full">{{ $defaults['notes'] }}</textarea>
        </div>
        <div class="bg-gray-50 rounded shadow p-4 border">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Ringkasan</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between items-center">
                    <span class="text-rose-700"><span id="sumFromLabel">Dari</span> berkurang</span>
                    <span id="sumFromAmt" class="font-bold text-rose-700">Rp 0</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-emerald-700"><span id="sumToLabel">Ke</span> bertambah</span>
                    <span id="sumToAmt" class="font-bold text-emerald-700">Rp 0</span>
                </div>
                <div class="flex justify-between items-center border-t pt-2">
                    <span class="text-gray-600">Biaya Admin (beban)</span>
                    <span id="sumFeeAmt" class="font-semibold text-gray-700">Rp 0</span>
                </div>
                <div id="sumNote" class="text-[11px] text-gray-500 pt-1"></div>
            </div>
        </div>
    </div>

    <datalist id="cashAccountList">
        @foreach($cashAccounts as $acc)
            <option data-id="{{ $acc->id }}" value="{{ $acc->code }} — {{ $acc->name }}"></option>
        @endforeach
    </datalist>
    <datalist id="expenseAccountList">
        @foreach($expenseAccounts as $acc)
            <option data-id="{{ $acc->id }}" value="{{ $acc->code }} — {{ $acc->name }}"></option>
        @endforeach
    </datalist>

    <div class="flex justify-between border-t pt-3">
        <a href="{{ route('finance.cash-bank.transfers.index') }}" class="text-gray-500 text-sm">← Kembali</a>
        <div class="flex gap-2">
            <button type="submit" name="_after_save" value="" class="bg-gray-600 text-white px-4 py-2 rounded text-sm">Simpan Draf</button>
            <button type="submit" name="_after_save" value="post" class="bg-green-600 text-white px-4 py-2 rounded text-sm"
                    onclick="return confirm('Simpan & langsung POST?')">Simpan & Post</button>
        </div>
    </div>
</div>

<script>
(() => {
    const cashAccounts = {!! json_encode($cashAccounts->map(fn($a)=>['id'=>$a->id,'label'=>$a->code.' — '.$a->name])->values()) !!};
    const expenseAccounts = {!! json_encode($expenseAccounts->map(fn($a)=>['id'=>$a->id,'label'=>$a->code.' — '.$a->name])->values()) !!};
    const cashByLabel    = Object.fromEntries(cashAccounts.map(a => [a.label, a]));
    const expenseByLabel = Object.fromEntries(expenseAccounts.map(a => [a.label, a]));

    const fromSearch = document.getElementById('fromAccountSearch');
    const toSearch   = document.getElementById('toAccountSearch');
    const feeSearch  = document.getElementById('adminFeeAccountSearch');
    const fromAccount = document.getElementById('fromAccount');
    const toAccount   = document.getElementById('toAccount');
    const adminFeeAccount = document.getElementById('adminFeeAccount');

    const amountEl    = document.getElementById('amount');
    const feeEl       = document.getElementById('adminFee');
    const borneRadios = document.querySelectorAll('input[name="fee_borne_by"]');

    const sumFromLabel = document.getElementById('sumFromLabel');
    const sumToLabel   = document.getElementById('sumToLabel');
    const sumFromAmt   = document.getElementById('sumFromAmt');
    const sumToAmt     = document.getElementById('sumToAmt');
    const sumFeeAmt    = document.getElementById('sumFeeAmt');
    const sumNote      = document.getElementById('sumNote');

    function fmt(n){ return 'Rp ' + Number(n||0).toLocaleString('id-ID'); }
    function bindAccountSearch(searchEl, hiddenEl, dict){
        const sync = () => {
            const acc = dict[searchEl.value];
            hiddenEl.value = acc ? acc.id : '';
            recalc();
        };
        searchEl.addEventListener('input', sync);
        searchEl.addEventListener('change', sync);
    }
    bindAccountSearch(fromSearch, fromAccount, cashByLabel);
    bindAccountSearch(toSearch,   toAccount,   cashByLabel);
    bindAccountSearch(feeSearch,  adminFeeAccount, expenseByLabel);

    function labelOf(searchEl, hiddenEl, fallback){
        return hiddenEl.value && searchEl.value ? searchEl.value : fallback;
    }

    function recalc(){
        const amount = window.cleanNumber(amountEl.value) || 0;
        const fee    = window.cleanNumber(feeEl.value) || 0;
        const borne  = document.querySelector('input[name="fee_borne_by"]:checked')?.value || 'source';

        sumFromLabel.textContent = labelOf(fromSearch, fromAccount, 'Sumber');
        sumToLabel.textContent   = labelOf(toSearch, toAccount, 'Tujuan');
        sumFeeAmt.textContent    = fmt(fee);

        let fromOut, toIn, note;
        if (borne === 'destination') {
            fromOut = amount;
            toIn    = amount - fee;
            note    = `Biaya ditanggung tujuan: ${labelOf(toSearch, toAccount, 'Tujuan')} hanya menerima ${fmt(amount)} − ${fmt(fee)} = ${fmt(toIn)}.`;
            if (toIn < 0) note += ' ⚠ Biaya melebihi jumlah transfer!';
        } else {
            fromOut = amount + fee;
            toIn    = amount;
            note    = `Biaya ditanggung sumber: ${labelOf(fromSearch, fromAccount, 'Sumber')} berkurang ${fmt(amount)} + ${fmt(fee)} = ${fmt(fromOut)}.`;
        }
        sumFromAmt.textContent = fmt(fromOut);
        sumToAmt.textContent   = fmt(toIn);
        sumNote.textContent    = (amount > 0 || fee > 0) ? note : '';
    }

    [amountEl, feeEl].forEach(el => el.addEventListener('input', recalc));
    borneRadios.forEach(r => r.addEventListener('change', recalc));

    recalc();
})();
</script>
