@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Saldo Awal Akun</h1>
    <a href="{{ route('accounts.opening-balance.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Kembali</a>
</div>

@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2 rounded mb-3">
        @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
    </div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2 rounded mb-3">{{ session('error') }}</div>
@endif

<div class="bg-white rounded shadow p-4 max-w-2xl">
    <p class="text-xs text-gray-500 mb-4">
        Saldo awal akun di-input dengan nilai langsung (saldo akhir per tanggal cut-off). Jika akun sudah memiliki saldo
        awal, input baru akan <strong>menimpa</strong> entry sebelumnya — 1 akun = 1 saldo aktif. Akun penyeimbang otomatis: Modal/Equity.
    </p>

    <form method="POST" action="{{ route('accounts.opening-balance.store') }}" class="space-y-4 text-sm">
        @csrf

        <div>
            <label class="block text-xs text-gray-600 mb-1">Akun (Kas / Bank)</label>
            <select name="account_id" id="accountSelect" required
                    class="border rounded px-2 py-1.5 w-full">
                <option value="" disabled {{ old('account_id') ? '' : 'selected' }}>Pilih akun...</option>
                @foreach($accounts as $acc)
                    @php $existing = $existingByAccount[$acc->id] ?? null; @endphp
                    <option value="{{ $acc->id }}"
                            data-existing="{{ $existing['amount'] ?? '' }}"
                            {{ old('account_id') == $acc->id ? 'selected' : '' }}>
                        {{ $acc->code }} - {{ $acc->name }}
                        @if($existing) — sudah ada saldo: Rp {{ number_format($existing['amount'], 0, ',', '.') }} (akan ditimpa) @endif
                    </option>
                @endforeach
            </select>
        </div>

        <div id="existingHint" class="hidden text-xs bg-amber-50 border border-amber-200 text-amber-800 px-2 py-1.5 rounded"></div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Saldo (Rp)</label>
                <input type="text" inputmode="numeric" name="amount" required
                       value="{{ old('amount') }}" placeholder="0"
                       class="border rounded px-2 py-1.5 w-full text-right rupiah-input">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Tanggal</label>
                <input type="date" name="date" required
                       value="{{ old('date', now()->format('Y-m-d')) }}"
                       class="border rounded px-2 py-1.5 w-full">
            </div>
        </div>

        <div>
            <label class="block text-xs text-gray-600 mb-1">Deskripsi (opsional)</label>
            <textarea name="description" rows="2"
                      placeholder="Contoh: Saldo awal Bank BCA per April 2026"
                      class="border rounded px-2 py-1.5 w-full">{{ old('description') }}</textarea>
        </div>

        <div class="flex justify-between items-center pt-2">
            <a href="{{ route('accounts.opening-balance.index') }}" class="text-sm text-gray-600">← Batal</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                Simpan Saldo Awal
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    const sel  = document.getElementById('accountSelect');
    const hint = document.getElementById('existingHint');
    function sync() {
        const opt = sel.options[sel.selectedIndex];
        const ex  = opt?.dataset.existing;
        if (ex && parseFloat(ex) > 0) {
            const fmt = parseFloat(ex).toLocaleString('id-ID');
            hint.textContent = 'Akun ini sudah memiliki saldo awal Rp ' + fmt + '. Menyimpan akan menimpa nilai tersebut.';
            hint.classList.remove('hidden');
        } else {
            hint.classList.add('hidden');
        }
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
