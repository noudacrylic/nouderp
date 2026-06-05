@php
    $bulanNama = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $isEdit = isset($sp);
    $defaults = $isEdit ? [
        'date'                 => $sp->date->toDateString(),
        'karyawan_id'          => $sp->karyawan_id,
        'periode_bulan'        => $sp->periode_bulan,
        'periode_tahun'        => $sp->periode_tahun,
        'cash_account_id'      => $sp->cash_account_id,
        'bruto_gaji'           => (float) $sp->bruto_gaji,
        'bpjs_kes_employee'    => (float) $sp->bpjs_kes_employee,
        'bpjs_kes_company'     => (float) $sp->bpjs_kes_company,
        'bpjs_tk_employee'     => (float) $sp->bpjs_tk_employee,
        'bpjs_tk_company'      => (float) $sp->bpjs_tk_company,
        'pph21_amount'         => (float) $sp->pph21_amount,
        'kasbon_potongan'      => (float) $sp->kasbon_potongan,
        'nett_dibayar'         => (float) $sp->nett_dibayar,
        'admin_fee'            => (float) $sp->admin_fee,
        'admin_fee_account_id' => $sp->admin_fee_account_id,
        'notes'                => $sp->notes,
    ] : [
        'date'                 => now()->toDateString(),
        'karyawan_id'          => request('karyawan_id', ''),
        'periode_bulan'        => (int) request('periode_bulan', now()->month),
        'periode_tahun'        => (int) request('periode_tahun', now()->year),
        'cash_account_id'      => '',
        'bruto_gaji'           => 0,
        'bpjs_kes_employee'    => 0,
        'bpjs_kes_company'     => 0,
        'bpjs_tk_employee'     => 0,
        'bpjs_tk_company'      => 0,
        'pph21_amount'         => 0,
        'kasbon_potongan'      => 0,
        'nett_dibayar'         => 0,
        'admin_fee'            => 0,
        'admin_fee_account_id' => $defaultAdminFeeAccountId ?? null,
        'notes'                => null,
    ];
    $fmt = fn($v) => number_format((float) $v, 0, ',', '.');
@endphp

@if(session('error') || $errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        {{ session('error') }}
    </div>
@endif

<form method="POST" action="{{ $isEdit ? route('finance.cash-bank.salary-payments.update', $sp->id) : route('finance.cash-bank.salary-payments.store') }}"
      class="space-y-4">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold text-sm mb-3">Header Pembayaran</h2>
        <div class="grid grid-cols-4 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tanggal Bayar</label>
                <input type="date" name="date" value="{{ old('date', $defaults['date']) }}" required class="border rounded px-2 py-1.5 w-full">
            </div>
            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Karyawan</label>
                <select name="karyawan_id" id="karyawan_id" required class="border rounded px-2 py-1.5 w-full">
                    <option value="">-- pilih karyawan --</option>
                    @foreach($karyawans as $k)
                        <option value="{{ $k->id }}"
                                data-gaji="{{ $k->gaji_pokok }}"
                                data-ptkp="{{ $k->ptkp_category }}"
                                data-kes="{{ (int) $k->ikut_bpjs_kesehatan }}"
                                data-tk="{{ (int) $k->ikut_bpjs_tk }}"
                                @selected(old('karyawan_id', $defaults['karyawan_id']) == $k->id)>
                            {{ $k->name }} ({{ $k->staf_code }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Akun Kas/Bank</label>
                <select name="cash_account_id" required class="border rounded px-2 py-1.5 w-full">
                    <option value="">-- pilih akun --</option>
                    @foreach($cashAccounts as $a)
                        <option value="{{ $a->id }}" @selected(old('cash_account_id', $defaults['cash_account_id']) == $a->id)>
                            {{ $a->code }} - {{ $a->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Periode Bulan</label>
                <select name="periode_bulan" id="periode_bulan" required class="border rounded px-2 py-1.5 w-full">
                    @foreach($bulanNama as $b => $label)
                        <option value="{{ $b }}" @selected(old('periode_bulan', $defaults['periode_bulan']) == $b)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Periode Tahun</label>
                <input type="number" name="periode_tahun" id="periode_tahun" min="2020" max="2100"
                       value="{{ old('periode_tahun', $defaults['periode_tahun']) }}" required class="border rounded px-2 py-1.5 w-full">
            </div>
            <div class="col-span-2 flex items-end">
                <button type="button" id="btn-preview"
                        class="border border-emerald-300 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">
                    🧮 Hitung Otomatis dari Absensi
                </button>
                <span id="preview-status" class="ml-3 text-xs text-gray-500"></span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold text-sm mb-3">Komponen Gaji & Potongan</h2>
        <p class="text-xs text-gray-500 mb-3">
            Boleh diisi manual atau di-fill otomatis pakai tombol di atas.
            <strong>Bruto − Potongan Karyawan = Nett</strong> (harus balance).
        </p>

        <div class="grid grid-cols-2 gap-6">
            {{-- KIRI: BRUTO + COMPANY SHARES (BPJS Comp Side) --}}
            <div>
                <h3 class="text-xs font-semibold uppercase text-gray-600 mb-2">Sisi Beban Perusahaan</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-gray-700 flex-1">Bruto Gaji (gaji pokok + lembur + tunjangan)</label>
                        <input type="text" name="bruto_gaji" class="js-num border rounded px-2 py-1 text-right w-44"
                               value="{{ old('bruto_gaji', $fmt($defaults['bruto_gaji'])) }}">
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-gray-700 flex-1 text-xs text-gray-500">BPJS Kesehatan (perusahaan)</label>
                        <input type="text" name="bpjs_kes_company" class="js-num border rounded px-2 py-1 text-right w-44"
                               value="{{ old('bpjs_kes_company', $fmt($defaults['bpjs_kes_company'])) }}">
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-gray-700 flex-1 text-xs text-gray-500">BPJS TK (perusahaan)</label>
                        <input type="text" name="bpjs_tk_company" class="js-num border rounded px-2 py-1 text-right w-44"
                               value="{{ old('bpjs_tk_company', $fmt($defaults['bpjs_tk_company'])) }}">
                    </div>
                </div>
            </div>

            {{-- KANAN: POTONGAN KARYAWAN --}}
            <div>
                <h3 class="text-xs font-semibold uppercase text-gray-600 mb-2">Potongan Karyawan</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-gray-700 flex-1">BPJS Kesehatan (karyawan)</label>
                        <input type="text" name="bpjs_kes_employee" class="js-num js-potongan border rounded px-2 py-1 text-right w-44"
                               value="{{ old('bpjs_kes_employee', $fmt($defaults['bpjs_kes_employee'])) }}">
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-gray-700 flex-1">BPJS TK (karyawan)</label>
                        <input type="text" name="bpjs_tk_employee" class="js-num js-potongan border rounded px-2 py-1 text-right w-44"
                               value="{{ old('bpjs_tk_employee', $fmt($defaults['bpjs_tk_employee'])) }}">
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-gray-700 flex-1">PPh 21</label>
                        <input type="text" name="pph21_amount" class="js-num js-potongan border rounded px-2 py-1 text-right w-44"
                               value="{{ old('pph21_amount', $fmt($defaults['pph21_amount'])) }}">
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-gray-700 flex-1">Potongan Kasbon</label>
                        <input type="text" name="kasbon_potongan" class="js-num js-potongan border rounded px-2 py-1 text-right w-44"
                               value="{{ old('kasbon_potongan', $fmt($defaults['kasbon_potongan'])) }}">
                    </div>
                </div>
            </div>
        </div>

        <hr class="my-4">

        <div class="flex items-center justify-end gap-3 text-sm">
            <div class="text-gray-500">Total Potongan: <span id="total-potongan" class="font-mono">0</span></div>
            <div class="text-gray-500">Bruto − Potongan = </div>
            <input type="text" name="nett_dibayar" id="nett_dibayar"
                   class="js-num border-2 border-emerald-400 rounded px-3 py-1.5 text-right w-48 font-bold text-emerald-700 bg-emerald-50"
                   value="{{ old('nett_dibayar', $fmt($defaults['nett_dibayar'])) }}">
        </div>

        <hr class="my-4">

        <h3 class="text-xs font-semibold uppercase text-gray-600 mb-2">Biaya Admin Bank <span class="text-gray-400 normal-case font-normal">(opsional — transfer beda bank)</span></h3>
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Biaya Admin</label>
                <input type="text" name="admin_fee" id="admin_fee"
                       class="js-num border rounded px-2 py-1.5 w-full text-right"
                       value="{{ old('admin_fee', $fmt($defaults['admin_fee'])) }}">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Akun Beban Admin Bank <span id="admin-fee-account-req" class="text-red-500 hidden">*</span></label>
                <select name="admin_fee_account_id" id="admin_fee_account_id" class="border rounded px-2 py-1.5 w-full">
                    <option value="">— pilih akun (kalau ada biaya admin) —</option>
                    @foreach($expenseAccounts as $a)
                        <option value="{{ $a->id }}" @selected(old('admin_fee_account_id', $defaults['admin_fee_account_id']) == $a->id)>
                            {{ $a->code }} - {{ $a->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="text-xs text-gray-500 mt-2">
            Total Kas Keluar (Nett + Admin) = <span id="total-cash-out" class="font-mono font-semibold">0</span>
        </div>

        <div class="mt-3">
            <label class="block text-xs text-gray-500 mb-1">Catatan</label>
            <textarea name="notes" rows="2" class="border rounded px-2 py-1.5 w-full text-sm">{{ old('notes', $defaults['notes']) }}</textarea>
        </div>
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('finance.cash-bank.salary-payments.index') }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
        <button type="submit" name="_after_save" value="draft" class="bg-gray-600 text-white px-4 py-2 rounded text-sm">Simpan Draf</button>
        <button type="submit" name="_after_save" value="post" class="bg-green-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan &amp; Post</button>
    </div>
</form>

<script>
(function() {
    const parseNum = (v) => parseFloat(String(v || '0').replace(/\./g, '').replace(',', '.')) || 0;
    const fmtNum   = (n) => Math.round(n).toLocaleString('id-ID');

    const inputs = document.querySelectorAll('.js-num');
    inputs.forEach(inp => {
        inp.addEventListener('blur', () => { inp.value = fmtNum(parseNum(inp.value)); });
        inp.addEventListener('input', recomputeNett);
    });

    const bruto      = document.querySelector('[name="bruto_gaji"]');
    const potongan   = document.querySelectorAll('.js-potongan');
    const nettInp    = document.getElementById('nett_dibayar');
    const totPotEl   = document.getElementById('total-potongan');
    const adminFeeInp = document.getElementById('admin_fee');
    const adminAccInp = document.getElementById('admin_fee_account_id');
    const adminReq    = document.getElementById('admin-fee-account-req');
    const totalCashOutEl = document.getElementById('total-cash-out');

    function recomputeNett() {
        let totalPot = 0;
        potongan.forEach(p => totalPot += parseNum(p.value));
        totPotEl.textContent = fmtNum(totalPot);
        const nett = parseNum(bruto.value) - totalPot;
        nettInp.value = fmtNum(nett);
        recomputeCashOut();
    }
    function recomputeCashOut() {
        const nett     = parseNum(nettInp.value);
        const adminFee = parseNum(adminFeeInp ? adminFeeInp.value : 0);
        if (totalCashOutEl) totalCashOutEl.textContent = 'Rp ' + fmtNum(nett + adminFee);
        if (adminAccInp && adminReq) {
            const need = adminFee > 0;
            adminAccInp.required = need;
            adminReq.classList.toggle('hidden', !need);
        }
    }
    recomputeNett();
    if (adminFeeInp) adminFeeInp.addEventListener('input', recomputeCashOut);
    if (nettInp)     nettInp.addEventListener('input', recomputeCashOut);

    // AJAX preview
    document.getElementById('btn-preview').addEventListener('click', async () => {
        const kid = document.querySelector('[name="karyawan_id"]').value;
        const bulan = document.querySelector('[name="periode_bulan"]').value;
        const tahun = document.querySelector('[name="periode_tahun"]').value;
        const status = document.getElementById('preview-status');

        if (!kid) { status.textContent = '⚠ Pilih karyawan dulu.'; status.className = 'ml-3 text-xs text-red-600'; return; }

        status.textContent = 'Menghitung dari data absensi...'; status.className = 'ml-3 text-xs text-gray-500';

        try {
            const res = await fetch('{{ route('finance.cash-bank.salary-payments.preview') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: JSON.stringify({ karyawan_id: kid, periode_bulan: bulan, periode_tahun: tahun }),
            });
            if (!res.ok) throw new Error(await res.text());
            const d = await res.json();
            const setVal = (name, val) => { const el = document.querySelector(`[name="${name}"]`); if (el) el.value = fmtNum(val || 0); };
            setVal('bruto_gaji',        d.bruto_gaji);
            setVal('bpjs_kes_employee', d.bpjs_kes_employee);
            setVal('bpjs_kes_company',  d.bpjs_kes_company);
            setVal('bpjs_tk_employee',  d.bpjs_tk_employee);
            setVal('bpjs_tk_company',   d.bpjs_tk_company);
            setVal('pph21_amount',      d.pph21_amount);
            setVal('kasbon_potongan',   d.kasbon_potongan);
            recomputeNett();
            status.textContent = `✓ Bruto Rp ${fmtNum(d.bruto_gaji)} · Nett Rp ${fmtNum(d.nett_dibayar)}`;
            status.className = 'ml-3 text-xs text-emerald-700';
        } catch (e) {
            status.textContent = '✗ Gagal hitung: ' + e.message;
            status.className = 'ml-3 text-xs text-red-600';
        }
    });
})();
</script>
