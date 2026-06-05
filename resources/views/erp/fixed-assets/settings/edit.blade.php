@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pengaturan Aset Tetap</h1>
</div>

@if(session('success')) <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded mb-3 text-sm">{{ session('success') }}</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif
@if($errors->any())
    <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">
        @foreach($errors->all() as $e) <div>• {{ $e }}</div> @endforeach
    </div>
@endif

<style>
.acc-pick { position: relative; }
.acc-pick-dropdown { position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #d1d5db; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 30; max-height: 240px; overflow-y: auto; display: none; }
.acc-pick-dropdown.show { display: block; }
.acc-pick-dropdown .item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
.acc-pick-dropdown .item:hover { background: #eff6ff; }
.acc-pick-dropdown .item .code { font-family: monospace; font-size: 11px; color: #2563eb; font-weight: bold; }
.acc-pick-dropdown .item .name { color: #1f2937; }
.acc-pick-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #9ca3af; font-size: 18px; }
.acc-pick-clear:hover { color: #ef4444; }
</style>

<form method="POST" action="{{ route('fixed-assets.settings.update') }}">
    @csrf @method('PUT')

    <div class="bg-white rounded shadow p-4 mb-4">
        <h2 class="font-semibold mb-1">Disposisi Aset</h2>
        <p class="text-xs text-gray-500 mb-3">Saat aset dijual/dirusak/dilepas, sistem otomatis hitung gain atau loss = (nilai jual − nilai buku). Lalu pilih akun yang sesuai dari setting di bawah.</p>

        <div class="grid grid-cols-2 gap-4 mb-3">
            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'disposal_gain_account_id',
                'label' => 'Akun Keuntungan Pelepasan',
                'required' => true,
                'value' => old('disposal_gain_account_id', $setting->disposal_gain_account_id),
                'displayValue' => $setting->disposalGainAccount ? ($setting->disposalGainAccount->code . ' — ' . $setting->disposalGainAccount->name) : '',
                'searchTypes' => 'revenue',
                'helper' => 'Dipakai saat hasil penjualan > nilai buku. Default: 7100 Keuntungan Pelepasan Aset (revenue).',
            ])

            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'disposal_loss_account_id',
                'label' => 'Akun Kerugian Pelepasan',
                'required' => true,
                'value' => old('disposal_loss_account_id', $setting->disposal_loss_account_id),
                'displayValue' => $setting->disposalLossAccount ? ($setting->disposalLossAccount->code . ' — ' . $setting->disposalLossAccount->name) : '',
                'searchTypes' => 'expense',
                'helper' => 'Dipakai saat aset rusak / dijual di bawah nilai buku. Default: 7200 Kerugian Pelepasan Aset (expense).',
            ])
        </div>

        <div class="grid grid-cols-2 gap-4">
            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'default_disposal_proceeds_account_id',
                'label' => 'Default Akun Penerimaan Disposisi',
                'required' => false,
                'value' => old('default_disposal_proceeds_account_id', $setting->default_disposal_proceeds_account_id),
                'displayValue' => $setting->defaultDisposalProceedsAccount ? ($setting->defaultDisposalProceedsAccount->code . ' — ' . $setting->defaultDisposalProceedsAccount->name) : '',
                'searchTypes' => 'asset',
                'helper' => 'Default akun Kas/Bank saat user buat disposisi tipe Jual (boleh dioverride per disposisi). Default: 1101 Kas.',
            ])
        </div>
    </div>

    <div class="bg-white rounded shadow p-4 mb-4">
        <h2 class="font-semibold mb-1">Saldo Awal Aset Existing</h2>
        <p class="text-xs text-gray-500 mb-3">Saat input aset existing manual & post-nya, sistem buat jurnal saldo awal: Dr Aset / Cr Akumulasi (jika sudah disusutkan) / Cr akun lawan ini sebesar nilai buku.</p>

        <div class="grid grid-cols-2 gap-4">
            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'opening_offset_account_id',
                'label' => 'Akun Lawan Saldo Awal',
                'required' => true,
                'value' => old('opening_offset_account_id', $setting->opening_offset_account_id),
                'displayValue' => $setting->openingOffsetAccount ? ($setting->openingOffsetAccount->code . ' — ' . $setting->openingOffsetAccount->name) : '',
                'searchTypes' => 'equity',
                'helper' => 'Default: 3100 Saldo Laba Ditahan (lebih akurat daripada Modal Awal untuk aset yang sudah operasional historis).',
            ])
        </div>
    </div>

    <div class="bg-white rounded shadow p-4 mb-4">
        <h2 class="font-semibold mb-1">Otomasi Periode</h2>
        <label class="inline-flex items-center mt-2">
            <input type="hidden" name="auto_run_depreciation_on_close" value="0">
            <input type="checkbox" name="auto_run_depreciation_on_close" value="1" {{ old('auto_run_depreciation_on_close', $setting->auto_run_depreciation_on_close) ? 'checked' : '' }} class="mr-2">
            <span class="text-sm">Auto-jalankan penyusutan saat tutup periode bulanan</span>
        </label>
        <p class="text-xs text-gray-500 mt-1">Jika dimatikan, user harus run penyusutan manual sebelum tutup periode.</p>
    </div>

    <div class="flex gap-2">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan Pengaturan</button>
    </div>
</form>

<script>
(function () {
    const SEARCH_URL = "{{ route('accounts.search') }}";
    document.querySelectorAll('.acc-pick').forEach(function (wrap) {
        const input = wrap.querySelector('.acc-pick-input');
        const hidden = wrap.querySelector('.acc-pick-id');
        const dropdown = wrap.querySelector('.acc-pick-dropdown');
        const clearBtn = wrap.querySelector('.acc-pick-clear');
        const types = (wrap.dataset.types || '').split(',').map(s => s.trim()).filter(Boolean);
        let timer;
        async function doSearch(q) {
            const params = new URLSearchParams();
            if (q) params.append('q', q);
            types.forEach(t => params.append('types[]', t));
            try {
                const res = await fetch(`${SEARCH_URL}?${params.toString()}`);
                const list = await res.json();
                renderResults(list);
            } catch (e) { console.error(e); }
        }
        function renderResults(list) {
            dropdown.innerHTML = '';
            if (!list.length) {
                dropdown.innerHTML = '<div class="item text-gray-400">Tidak ada akun cocok.</div>';
            } else {
                list.forEach(a => {
                    const div = document.createElement('div');
                    div.className = 'item';
                    div.innerHTML = `<div class="code">${a.code}</div><div class="name">${a.name}</div>`;
                    div.addEventListener('click', () => {
                        hidden.value = a.id;
                        input.value = `${a.code} — ${a.name}`;
                        dropdown.classList.remove('show');
                    });
                    dropdown.appendChild(div);
                });
            }
            dropdown.classList.add('show');
        }
        input.addEventListener('input', () => {
            hidden.value = '';
            clearTimeout(timer);
            timer = setTimeout(() => doSearch(input.value.trim()), 200);
        });
        input.addEventListener('focus', () => doSearch(input.value.trim()));
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) dropdown.classList.remove('show');
        });
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                hidden.value = '';
                input.value = '';
                input.focus();
            });
        }
    });
})();
</script>
@endsection
