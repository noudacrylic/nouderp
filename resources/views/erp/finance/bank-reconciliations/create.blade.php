@extends('layouts.erp')

@section('content')
@php
    $accountInitialLabel = '';
    if ($prefillAccountId) {
        $a = $accounts->firstWhere('id', $prefillAccountId);
        $accountInitialLabel = $a ? ($a->code.' — '.$a->name) : '';
    } elseif (old('account_id')) {
        $a = $accounts->firstWhere('id', (int) old('account_id'));
        $accountInitialLabel = $a ? ($a->code.' — '.$a->name) : '';
    }
@endphp
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Mulai Rekonsiliasi Bank</h1>
</div>

<form method="POST" action="{{ route('finance.cash-bank.reconciliations.store') }}">
    @csrf
    <div class="bg-white rounded shadow p-4 space-y-3">
        @if(session('error') || $errors->any())
            <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm">
                {{ session('error') }}
                @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
            </div>
        @endif

        <div class="grid grid-cols-12 gap-3 text-sm">
            <div class="col-span-5">
                <label class="block text-xs text-gray-500 mb-1">Akun Bank/Kas <span class="text-red-500">*</span></label>
                <input type="text" id="accountSearch" list="accountList"
                       value="{{ $accountInitialLabel }}"
                       placeholder="Ketik kode / nama kas atau bank…"
                       class="border rounded px-2 h-9 w-full" autocomplete="off" required>
                <input type="hidden" name="account_id" id="accountId"
                       value="{{ old('account_id', $prefillAccountId) }}">
                <div id="accountStatus" class="text-xs mt-1 text-gray-500"></div>
            </div>
            <div class="col-span-3">
                <label class="block text-xs text-gray-500 mb-1">Bulan <span class="text-red-500">*</span></label>
                <input type="month" name="period" id="periodInput"
                       value="{{ old('period', $prefillPeriod) }}"
                       class="border rounded px-2 h-9 w-full" required>
                <div id="periodStatus" class="text-xs mt-1 text-gray-500"></div>
            </div>
            <div class="col-span-4">
                <label class="block text-xs text-gray-500 mb-1">Saldo Akhir Per Rekening Koran</label>
                <input type="text" inputmode="numeric" name="statement_balance"
                       value="{{ old('statement_balance', 0) }}"
                       class="border rounded px-2 h-9 w-full text-right rupiah-input">
                <div class="text-xs text-gray-400 mt-1">Bisa diubah lagi nanti.</div>
            </div>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">Catatan</label>
            <textarea name="notes" rows="2" class="border rounded px-2 py-1.5 w-full">{{ old('notes') }}</textarea>
        </div>

        <datalist id="accountList">
            @foreach($accounts as $acc)
                @php
                    $accMap   = $reconciledMap[$acc->id] ?? [];
                    $latest   = $accMap ? max(array_keys($accMap)) : null;
                    $hint     = $latest ? ' · terakhir '.$latest : '';
                @endphp
                <option data-id="{{ $acc->id }}"
                        value="{{ $acc->code }} — {{ $acc->name }}"
                        label="{{ $latest ? '✔'.$hint : 'belum pernah direkonsiliasi' }}"></option>
            @endforeach
        </datalist>

        <div class="flex justify-between border-t pt-3">
            <a href="{{ route('finance.cash-bank.reconciliations.index') }}" class="text-gray-500 text-sm">← Kembali</a>
            <button type="submit" id="submitBtn" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Mulai Rekonsiliasi</button>
        </div>
    </div>
</form>

<script>
(() => {
    const accountsByLabel = {!! json_encode($accounts->map(fn($a)=>['id'=>$a->id,'label'=>$a->code.' — '.$a->name])->values()) !!}
        .reduce((m, a) => (m[a.label] = a, m), {});
    const reconciledMap = @json($reconciledMap);

    const searchEl   = document.getElementById('accountSearch');
    const idEl       = document.getElementById('accountId');
    const periodEl   = document.getElementById('periodInput');
    const accStatus  = document.getElementById('accountStatus');
    const perStatus  = document.getElementById('periodStatus');
    const submitBtn  = document.getElementById('submitBtn');

    function badge(text, cls){ return `<span class="inline-block px-2 py-0.5 rounded ${cls}">${text}</span>`; }
    function monthLabel(p){
        if (!p) return '';
        const [y, m] = p.split('-').map(Number);
        const names = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        return names[m-1] + ' ' + y;
    }

    function refreshAccountStatus(){
        const accId = idEl.value;
        if (!accId) { accStatus.innerHTML = ''; return; }
        const map = reconciledMap[accId] || {};
        const periods = Object.keys(map).sort();
        if (!periods.length) {
            accStatus.innerHTML = badge('Belum pernah direkonsiliasi', 'bg-gray-100 text-gray-700');
            return;
        }
        const last = periods[periods.length-1];
        const lastSt = map[last].status;
        const cls = lastSt === 'completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700';
        const label = lastSt === 'completed' ? 'Sudah s/d' : 'Draf di';
        accStatus.innerHTML = badge(`${label} ${monthLabel(last)}`, cls)
            + ` <span class="text-gray-500">(total ${periods.length} bulan)</span>`;
    }

    function refreshPeriodStatus(){
        const accId = idEl.value;
        const period = periodEl.value;
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        if (!accId || !period) { perStatus.innerHTML = ''; return; }
        const entry = (reconciledMap[accId] || {})[period];
        if (!entry) {
            perStatus.innerHTML = badge('⚠ Belum direkonsiliasi', 'bg-red-50 text-red-700 border border-red-200');
            return;
        }
        if (entry.status === 'completed') {
            perStatus.innerHTML = badge('✅ Sudah direkonsiliasi: ' + entry.number,
                'bg-green-100 text-green-700');
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        } else {
            perStatus.innerHTML = badge('✏ Masih draf: ' + entry.number,
                'bg-yellow-100 text-yellow-700');
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }
    }

    function syncSearch(){
        const acc = accountsByLabel[searchEl.value];
        idEl.value = acc ? acc.id : '';
        refreshAccountStatus();
        refreshPeriodStatus();
    }
    searchEl.addEventListener('input', syncSearch);
    searchEl.addEventListener('change', syncSearch);
    periodEl.addEventListener('input', refreshPeriodStatus);
    periodEl.addEventListener('change', refreshPeriodStatus);

    refreshAccountStatus();
    refreshPeriodStatus();
})();
</script>
@endsection
