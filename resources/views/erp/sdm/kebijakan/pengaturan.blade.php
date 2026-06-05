@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pengaturan Akun &amp; Rate Payroll</h1>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error') || $errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        {{ session('error') }}
    </div>
@endif

@php
    $accById = $accounts->keyBy('id');
    $accLabel = function ($id) use ($accById) {
        $a = $accById->get($id);
        return $a ? ($a->code . ' — ' . $a->name) : '';
    };
@endphp

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

<form method="POST" action="{{ route('sdm.kebijakan.pengaturan.update') }}" class="space-y-4">
    @csrf @method('PUT')

    {{-- =========================== AKUN MAPPING =========================== --}}
    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold text-sm mb-1">Akun Jurnal Payroll</h2>
        <p class="text-xs text-gray-500 mb-4">
            Mapping akun yang dipakai sistem saat memposting jurnal pembayaran gaji, BPJS, dan kasbon. Ketik kode atau nama akun untuk mencari.
            <br>
            <span class="text-[11px] text-gray-400">THR / cuti tidak terpakai cukup ditambahkan sebagai baris di <a href="{{ route('sdm.kebijakan.kolom.index') }}" class="text-blue-600 hover:underline">Kebijakan → Kolom Akibat</a> (Baris Summary), nominalnya akan dibebankan ke akun Beban Gaji.</span>
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'expense_salary_account_id',
                'label' => 'Beban Gaji Karyawan',
                'required' => true,
                'value' => old('expense_salary_account_id', $setting->expense_salary_account_id),
                'displayValue' => $accLabel(old('expense_salary_account_id', $setting->expense_salary_account_id)),
                'searchTypes' => 'expense',
                'helper' => 'Akun beban (Expense) yang didebit saat post pembayaran gaji. Default: 5300. THR / komponen tambahan ikut ke akun ini.',
            ])

            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'bpjs_company_expense_account_id',
                'label' => 'Beban BPJS Perusahaan',
                'required' => false,
                'value' => old('bpjs_company_expense_account_id', $setting->bpjs_company_expense_account_id),
                'displayValue' => $accLabel(old('bpjs_company_expense_account_id', $setting->bpjs_company_expense_account_id)),
                'searchTypes' => 'expense',
                'helper' => 'Akun beban untuk komponen BPJS yang ditanggung perusahaan. Default: 5303.',
            ])

            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'kasbon_receivable_account_id',
                'label' => 'Piutang Kasbon Karyawan',
                'required' => false,
                'value' => old('kasbon_receivable_account_id', $setting->kasbon_receivable_account_id),
                'displayValue' => $accLabel(old('kasbon_receivable_account_id', $setting->kasbon_receivable_account_id)),
                'searchTypes' => 'asset',
                'helper' => 'Akun piutang yang didebit saat kasbon dicairkan & dikredit saat dilunasi. Default: 1150.',
            ])

            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'bpjs_kesehatan_liability_account_id',
                'label' => 'Hutang BPJS Kesehatan',
                'required' => false,
                'value' => old('bpjs_kesehatan_liability_account_id', $setting->bpjs_kesehatan_liability_account_id),
                'displayValue' => $accLabel(old('bpjs_kesehatan_liability_account_id', $setting->bpjs_kesehatan_liability_account_id)),
                'searchTypes' => 'liability',
                'helper' => 'Akun hutang yang dikredit saat potongan + iuran BPJS Kesehatan. Default: 2110.',
            ])

            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'bpjs_tk_liability_account_id',
                'label' => 'Hutang BPJS Ketenagakerjaan',
                'required' => false,
                'value' => old('bpjs_tk_liability_account_id', $setting->bpjs_tk_liability_account_id),
                'displayValue' => $accLabel(old('bpjs_tk_liability_account_id', $setting->bpjs_tk_liability_account_id)),
                'searchTypes' => 'liability',
                'helper' => 'Akun hutang yang dikredit saat potongan + iuran BPJS TK (JHT/JP/JKK/JKM). Default: 2111.',
            ])

            @include('erp.fixed-assets.categories._account_picker', [
                'name' => 'pph21_liability_account_id',
                'label' => 'Hutang PPh 21',
                'required' => false,
                'value' => old('pph21_liability_account_id', $setting->pph21_liability_account_id),
                'displayValue' => $accLabel(old('pph21_liability_account_id', $setting->pph21_liability_account_id)),
                'searchTypes' => 'liability',
                'helper' => 'Akun hutang pajak penghasilan PPh 21. Default: 2112.',
            ])
        </div>
    </div>

    {{-- =========================== BPJS KESEHATAN =========================== --}}
    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold text-sm mb-1">Rate BPJS Kesehatan</h2>
        <p class="text-xs text-gray-500 mb-4">
            Aktivasi per-karyawan via Master Karyawan → centang <em>Ikut BPJS Kesehatan</em>.
            Aturan umum: 1% potong dari karyawan + 4% ditanggung perusahaan, dengan dasar maks Rp 12.000.000.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Potongan Karyawan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_kesehatan_employee_rate"
                       value="{{ old('bpjs_kesehatan_employee_rate', $setting->bpjs_kesehatan_employee_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Iuran Perusahaan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_kesehatan_company_rate"
                       value="{{ old('bpjs_kesehatan_company_rate', $setting->bpjs_kesehatan_company_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Dasar Maks (Rp/bln)</label>
                <input type="text" inputmode="numeric" name="bpjs_kesehatan_max_base"
                       value="{{ old('bpjs_kesehatan_max_base', $setting->bpjs_kesehatan_max_base) }}"
                       class="rupiah-input w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
        </div>
    </div>

    {{-- =========================== BPJS TK =========================== --}}
    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold text-sm mb-1">Rate BPJS Ketenagakerjaan</h2>
        <p class="text-xs text-gray-500 mb-4">
            Aktivasi per-karyawan via Master Karyawan → centang <em>Ikut BPJS TK</em>.
            JHT 2%/3,7%, JP 1%/2% (dasar maks Rp 10.547.400), JKK 0,24%, JKM 0,30% (tarif default 2026).
        </p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <label class="block text-xs text-gray-600 mb-1">JHT Karyawan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_jht_employee_rate"
                       value="{{ old('bpjs_jht_employee_rate', $setting->bpjs_jht_employee_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">JHT Perusahaan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_jht_company_rate"
                       value="{{ old('bpjs_jht_company_rate', $setting->bpjs_jht_company_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">JP Karyawan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_jp_employee_rate"
                       value="{{ old('bpjs_jp_employee_rate', $setting->bpjs_jp_employee_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">JP Perusahaan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_jp_company_rate"
                       value="{{ old('bpjs_jp_company_rate', $setting->bpjs_jp_company_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">JKK Perusahaan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_jkk_company_rate"
                       value="{{ old('bpjs_jkk_company_rate', $setting->bpjs_jkk_company_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">JKM Perusahaan (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="bpjs_jkm_company_rate"
                       value="{{ old('bpjs_jkm_company_rate', $setting->bpjs_jkm_company_rate) }}"
                       class="w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
            <div class="col-span-2">
                <label class="block text-xs text-gray-600 mb-1">Dasar JP Maks (Rp/bln)</label>
                <input type="text" inputmode="numeric" name="bpjs_jp_max_base"
                       value="{{ old('bpjs_jp_max_base', $setting->bpjs_jp_max_base) }}"
                       class="rupiah-input w-full border rounded px-2 py-1.5 text-sm text-right">
            </div>
        </div>
    </div>

    {{-- =========================== PPH 21 =========================== --}}
    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold text-sm mb-1">PPh 21 (TER Bulanan PMK 168/2023)</h2>
        <p class="text-xs text-gray-500 mb-4">
            Aktivasi global di bawah, lalu pilih <em>Kategori PTKP</em> per karyawan di Master Karyawan.
            Tarif Efektif Rata-rata (TER) di-lookup otomatis dari penghasilan bruto bulanan.
        </p>

        <div class="flex items-center gap-2 mb-3">
            <input type="hidden" name="pph21_enabled" value="0">
            <input type="checkbox" name="pph21_enabled" value="1" id="pph21_enabled"
                   @checked($setting->pph21_enabled)
                   class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
            <label for="pph21_enabled" class="text-sm select-none cursor-pointer">
                Aktifkan perhitungan PPh 21 otomatis di Summary Absensi
            </label>
        </div>

        <div class="text-xs text-gray-600 bg-gray-50 rounded p-3 leading-relaxed">
            <div class="font-semibold mb-1">Kategori PTKP (PMK 168/2023):</div>
            <ul class="list-disc list-inside space-y-0.5">
                <li><strong>A</strong> — TK/0, TK/1, K/0 (PTKP 54-58,5 jt/thn). Cut-off bebas pajak: bruto bulanan &lt; Rp 5,4 jt.</li>
                <li><strong>B</strong> — TK/2, TK/3, K/1, K/2 (PTKP 63-67,5 jt/thn). Cut-off bebas pajak: bruto bulanan &lt; Rp 6,2 jt.</li>
                <li><strong>C</strong> — K/3 (PTKP 72 jt/thn). Cut-off bebas pajak: bruto bulanan &lt; Rp 6,6 jt.</li>
            </ul>
            <div class="mt-2 text-gray-500">Karyawan tanpa NPWP secara aturan dikenakan tarif 20% lebih tinggi — set kategori manual di slip kalau perlu.</div>
        </div>
    </div>

    <div class="flex justify-end">
        <button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm font-medium">Simpan Pengaturan</button>
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
        input.addEventListener('focus', () => {
            doSearch(input.value.trim());
        });
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
