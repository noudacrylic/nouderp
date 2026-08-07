@extends('layouts.erp')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Pengaturan Ongkir</h2>
        <p class="text-sm text-gray-500 mb-6">
            Akun yang dipakai saat pembayaran ongkir ke ekspedisi punya selisih dengan titipan customer
            (akun {{ $titipanAccount?->code ?? '1203' }} — {{ $titipanAccount?->name ?? 'Titipan Ongkir' }}).
        </p>


        @php
            $gainAcc   = $accounts->firstWhere('id', $setting->gain_account_id);
            $lossAcc   = $accounts->firstWhere('id', $setting->loss_account_id);
            $saldoAcc  = $accounts->firstWhere('id', $setting->biteship_saldo_account_id);
            $feeAcc    = $accounts->firstWhere('id', $setting->biteship_fee_account_id);
            $jSaldoAcc = $accounts->firstWhere('id', $setting->jubelio_saldo_account_id);
            $jFeeAcc   = $accounts->firstWhere('id', $setting->jubelio_fee_account_id);
            $gainLabel = $gainAcc ? "{$gainAcc->code} — {$gainAcc->name}" : '';
            $lossLabel = $lossAcc ? "{$lossAcc->code} — {$lossAcc->name}" : '';
            $saldoLabel= $saldoAcc ? "{$saldoAcc->code} — {$saldoAcc->name}" : '';
            $feeLabel  = $feeAcc ? "{$feeAcc->code} — {$feeAcc->name}" : '';
            $jSaldoLabel = $jSaldoAcc ? "{$jSaldoAcc->code} — {$jSaldoAcc->name}" : '';
            $jFeeLabel   = $jFeeAcc ? "{$jFeeAcc->code} — {$jFeeAcc->name}" : '';
        @endphp

        <form method="POST" action="{{ route('settings.freight.update') }}" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Selisih Lebih (Gain)</label>
                    <input type="text" list="accountList" value="{{ $gainLabel }}"
                           class="w-full border rounded px-3 py-2 acc-search" data-target="gain_account_id"
                           placeholder="Ketik kode/nama akun pendapatan…">
                    <input type="hidden" name="gain_account_id" id="gain_account_id" value="{{ $setting->gain_account_id }}">
                    <p class="text-xs text-gray-500 mt-1">
                        Dipakai saat titipan ongkir <b>lebih besar</b> dari ongkir aktual yang dibayar (kelebihan jadi pendapatan).
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Selisih Kurang (Loss)</label>
                    <input type="text" list="accountList" value="{{ $lossLabel }}"
                           class="w-full border rounded px-3 py-2 acc-search" data-target="loss_account_id"
                           placeholder="Ketik kode/nama akun beban…">
                    <input type="hidden" name="loss_account_id" id="loss_account_id" value="{{ $setting->loss_account_id }}">
                    <p class="text-xs text-gray-500 mt-1">
                        Dipakai saat titipan ongkir <b>kurang</b> dari ongkir aktual yang dibayar (kekurangan jadi beban).
                    </p>
                </div>
            </div>

            <div class="border-t pt-4">
                <h3 class="text-sm font-bold text-gray-700 mb-1">Biteship</h3>
                <p class="text-xs text-gray-500 mb-3">Saat <b>Booking Resi</b>: Dr Titipan Ongkir / Cr Saldo Biteship (ongkir aktual). Selisih dengan ongkir yang ditagih customer di-settle saat invoice posted.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Saldo Biteship</label>
                        <input type="text" list="accountList" value="{{ $saldoLabel }}"
                               class="w-full border rounded px-3 py-2 acc-search" data-target="biteship_saldo_account_id"
                               placeholder="Akun deposit Biteship (kas)…">
                        <input type="hidden" name="biteship_saldo_account_id" id="biteship_saldo_account_id" value="{{ $setting->biteship_saldo_account_id }}">
                        <p class="text-xs text-gray-500 mt-1">Akun kas deposit Biteship. Top-up lewat <b>Kas & Bank → Transfer Antar Bank</b>.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Beban API Biteship</label>
                        <input type="text" list="accountList" value="{{ $feeLabel }}"
                               class="w-full border rounded px-3 py-2 acc-search" data-target="biteship_fee_account_id"
                               placeholder="Akun beban biaya API/cek ongkir…">
                        <input type="hidden" name="biteship_fee_account_id" id="biteship_fee_account_id" value="{{ $setting->biteship_fee_account_id }}">
                        <p class="text-xs text-gray-500 mt-1">Untuk biaya request API harian (cek ongkir & cek resi). Dipakai di panel "Catat Biaya API" di bawah.</p>
                    </div>
                </div>
            </div>

            <div class="border-t pt-4">
                <h3 class="text-sm font-bold text-gray-700 mb-1">Jubelio Shipment <span class="text-[10px] font-normal text-white bg-emerald-600 px-1.5 py-0.5 rounded align-middle">AKTIF</span></h3>
                <p class="text-xs text-gray-500 mb-3">Saldo Jubelio prabayar (<b>Coins</b>). Saat <b>Booking Resi</b>: Dr Titipan Ongkir / Cr Saldo Jubelio Shipment (ongkir aktual) — persis pola Biteship.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Saldo Jubelio Shipment</label>
                        <input type="text" list="accountList" value="{{ $jSaldoLabel }}"
                               class="w-full border rounded px-3 py-2 acc-search" data-target="jubelio_saldo_account_id"
                               placeholder="Akun deposit Jubelio (kas)…">
                        <input type="hidden" name="jubelio_saldo_account_id" id="jubelio_saldo_account_id" value="{{ $setting->jubelio_saldo_account_id }}">
                        <p class="text-xs text-gray-500 mt-1">Akun kas deposit Coins. Top-up lewat <b>Kas &amp; Bank → Transfer Antar Bank</b>. <b>Wajib diisi</b> — tanpa ini resi Jubelio gagal dijurnal.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Beban Layanan Jubelio</label>
                        <input type="text" list="accountList" value="{{ $jFeeLabel }}"
                               class="w-full border rounded px-3 py-2 acc-search" data-target="jubelio_fee_account_id"
                               placeholder="Akun beban layanan…">
                        <input type="hidden" name="jubelio_fee_account_id" id="jubelio_fee_account_id" value="{{ $setting->jubelio_fee_account_id }}">
                        <p class="text-xs text-gray-500 mt-1">Untuk selisih saat rekonsiliasi saldo Coins.</p>
                    </div>
                </div>
            </div>

            <datalist id="accountList">
                @foreach($accounts as $a)
                    <option data-id="{{ $a->id }}" value="{{ $a->code }} — {{ $a->name }}"></option>
                @endforeach
            </datalist>

            <div class="flex justify-between border-t pt-4">
                <a href="{{ route('finance.cash-bank.disbursements.freight') }}" class="text-gray-500 text-sm">← Kembali</a>
                <button class="bg-blue-600 text-white px-5 py-2 rounded font-semibold">Simpan</button>
            </div>
        </form>
    </div>

    {{-- INFO: pencatatan biaya API pindah ke Kas & Bank --}}
    <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mt-6 text-sm text-indigo-800">
        💡 Pencatatan <b>biaya API Biteship</b> (upload laporan bulanan) ada di
        <a href="{{ route('finance.cash-bank.disbursements.api-cost') }}" class="underline font-semibold">Kas &amp; Bank → Pengeluaran → Biaya API Biteship</a>.
        Akun yang dipakai = <b>Beban API Biteship</b> &amp; <b>Saldo Biteship</b> yang diset di atas.
    </div>

    {{-- REKONSILIASI SALDO DEPOSIT — satu panel per agregator berbayar-di-muka.
         Kontrak API Jubelio v1.8 tidak punya endpoint saldo, jadi angkanya memang diketik
         manual dari dashboard, sama seperti Biteship. --}}
    @foreach(\App\Models\FreightSetting::PROVIDERS as $provKey => $provLabel)
    <div class="bg-white shadow rounded-lg border p-6 mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-1">Rekonsiliasi Saldo {{ $provLabel }} <span class="text-xs font-normal text-gray-400">(pengaman/opsional)</span></h2>
        <p class="text-sm text-gray-500 mb-4">
            Samakan saldo buku dengan saldo asli di dashboard {{ $provLabel }}; selisihnya dicatat ke <b>Beban Layanan {{ $provLabel }}</b>.
        </p>

        <div class="flex items-center gap-6 mb-4">
            <div>
                <div class="text-xs text-gray-500">Saldo {{ $provLabel }} (buku)</div>
                <div class="text-xl font-bold text-gray-800">Rp {{ number_format($book[$provKey] ?? 0, 0, ',', '.') }}</div>
            </div>
        </div>

        <form method="POST" action="{{ route('settings.freight.reconcile') }}"
              class="flex flex-wrap items-end gap-3"
              onsubmit="return confirm('Catat selisih saldo {{ $provLabel }} ke Beban Layanan? Pastikan saldo asli sudah benar.')">
            @csrf
            <input type="hidden" name="provider" value="{{ $provKey }}">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Saldo Asli di {{ $provLabel }} (Rp)</label>
                <input type="number" step="1" min="0" name="actual_balance" required
                       class="border rounded px-3 py-2 w-48 text-right" placeholder="cek di dashboard">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Tanggal</label>
                <input type="date" name="recon_date" value="{{ date('Y-m-d') }}" class="border rounded px-3 py-2">
            </div>
            <button class="bg-amber-600 hover:bg-amber-700 text-white px-5 py-2 rounded font-semibold">Sesuaikan Saldo</button>
        </form>
        <p class="text-[11px] text-gray-400 mt-2">Buku &gt; saldo asli → selisih jadi beban. Cukup dijalankan berkala (mingguan/bulanan), tidak perlu tiap cek ongkir.</p>
    </div>
    @endforeach
</div>

<script>
(() => {
    const accounts = {!! json_encode($accounts->map(fn($a) => ['id' => $a->id, 'label' => "{$a->code} — {$a->name}"])->values()) !!};
    const byLabel = Object.fromEntries(accounts.map(a => [a.label, a]));

    document.querySelectorAll('.acc-search').forEach(search => {
        const idEl = document.getElementById(search.dataset.target);
        const sync = () => {
            const acc = byLabel[search.value];
            idEl.value = acc ? acc.id : '';
        };
        search.addEventListener('input', sync);
        search.addEventListener('change', sync);
        search.addEventListener('blur', () => {
            // kalau user ketik bebas tapi tidak match, kosongkan label
            if (!byLabel[search.value]) {
                search.value = '';
                idEl.value = '';
            }
        });
    });
})();
</script>
@endsection
