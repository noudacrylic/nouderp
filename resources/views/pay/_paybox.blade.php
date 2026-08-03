@php
    $isSo = $is_so ?? false;
    $requireFull = $require_full ?? false; // pesanan website: wajib lunas, tanpa input DP
    // Nominal dasar: web (lunas) & invoice = tetap; SO DP admin = diisi pembeli (0, dinamis).
    $payBase = $requireFull ? ($remaining ?? 0) : ($isSo ? 0 : ($base_amount ?? 0));

    // Hanya metode yang sudah AKTIF di Midtrans yang ditawarkan. Alfamart,
    // Kredivo/Akulaku, dan Kartu Kredit butuh pengajuan terpisah; menawarkannya sebelum
    // disetujui membuat pembeli mentok di halaman Snap tepat saat ia hendak membayar.
    // Diatur di Pengaturan → Midtrans.
    $aktif = $active_channels ?? \App\Models\MidtransSetting::DEFAULT_ACTIVE_CHANNELS;
    $lainnya = collect([
        'va'          => 'Virtual Account',
        'ewallet'     => 'E-Wallet',
        'credit_card' => 'Kartu Kredit',
        'alfamart'    => 'Alfamart',
        'paylater'    => 'Kredivo / Akulaku',
    ])->filter(fn ($label, $key) => in_array($key, $aktif, true));
    $qrisAktif = in_array('qris', $aktif, true);
    // Pilihan awal: QRIS bila aktif, kalau tidak metode aktif pertama.
    $channelAwal = $qrisAktif ? 'qris' : ($lainnya->keys()->first() ?? 'qris');
@endphp

<div class="px-6 py-5"
     id="paybox"
     data-isso="{{ $isSo ? 1 : 0 }}"
     data-require-full="{{ $requireFull ? 1 : 0 }}"
     data-fee-threshold="{{ $fee_threshold }}"
     data-fee-amount="{{ $fee_amount }}"
     data-base="{{ $payBase }}"
     data-min-dp="{{ ($isSo && !$requireFull) ? ($min_dp ?? 0) : 0 }}"
     data-remaining="{{ $remaining ?? 0 }}"
     data-channel-fees='@json($channel_fees ?? [])'
     data-default-channel="{{ $channelAwal }}"
     data-snap-url="{{ route('pay.snap', $trx->link_token) }}">

    <div class="space-y-4">
        @if($isSo && !$requireFull)
            @php
                // Persentase DP minimal, untuk label tombol pintasan ("DP 50%").
                $dpPersen = $remaining > 0 ? (int) round($min_dp / $remaining * 100) : 0;
                $adaPilihanDp = $min_dp > 0 && $min_dp < $remaining;
            @endphp
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Nominal yang Dibayar</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">Rp</span>
                    {{-- Bawaannya TAGIHAN PENUH, bukan DP minimal. Saat isian ini langsung
                         menampilkan setengah tagihan, pembeli membacanya seperti potongan
                         harga dan bingung kenapa yang ditagih lebih kecil dari total
                         pesanan. Yang ingin mencicil menekan tombol DP di bawah. --}}
                    <input id="dp_input" type="number" inputmode="numeric"
                           min="{{ $min_dp }}" max="{{ $remaining }}" value="{{ $remaining }}"
                           class="w-full border-2 border-gray-200 rounded-xl pl-9 pr-3 py-3 text-lg font-bold focus:border-emerald-400 focus:outline-none">
                </div>

                @if($adaPilihanDp)
                    <div class="flex gap-2 mt-2">
                        <button type="button" class="dp-quick flex-1 border-2 border-gray-200 rounded-lg px-3 py-2 text-xs font-semibold text-gray-700 transition hover:border-emerald-300"
                                data-amount="{{ (int) $remaining }}">
                            Bayar Lunas
                            <span class="block text-[11px] font-bold text-gray-500">Rp {{ number_format($remaining, 0, ',', '.') }}</span>
                        </button>
                        <button type="button" class="dp-quick flex-1 border-2 border-gray-200 rounded-lg px-3 py-2 text-xs font-semibold text-gray-700 transition hover:border-emerald-300"
                                data-amount="{{ (int) $min_dp }}">
                            DP {{ $dpPersen }}%
                            <span class="block text-[11px] font-bold text-gray-500">Rp {{ number_format($min_dp, 0, ',', '.') }}</span>
                        </button>
                    </div>
                @endif

                <p class="text-xs text-gray-500 mt-2">
                    Bawaannya <b>bayar lunas</b>. Ingin membayar uang muka dulu? Tekan <b>DP {{ $dpPersen }}%</b>
                    atau isi sendiri nominalnya &mdash; minimal <b>Rp {{ number_format($min_dp, 0, ',', '.') }}</b>,
                    maksimal Rp {{ number_format($remaining, 0, ',', '.') }}.
                </p>

                {{-- Ketentuan DP ditaruh tepat di bawah pilihannya, bukan di bagian lain
                     halaman: pembeli memutuskan besaran bayar di sini, dan kewajiban
                     melunasi sebelum barang berpindah harus terbaca pada detik itu juga. --}}
                @if($adaPilihanDp)
                    <div class="mt-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5 text-[11px] leading-relaxed text-amber-900">
                        <p class="font-bold mb-1">Ketentuan pembayaran DP</p>
                        <ul class="list-disc pl-4 space-y-0.5">
                            <li>DP minimal <b>{{ $dpPersen }}%</b> dari total pesanan sebagai <b>tanda jadi</b>.</li>
                            <li><b>Ambil di toko:</b> pelunasan dilakukan saat barang diambil.</li>
                            <li><b>Kirim via kurir / instant:</b> pelunasan harus dilakukan <b>sebelum</b> barang dikirim.</li>
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div>
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Pilih Metode Bayar</label>

            @if($qrisAktif)
                {{-- QRIS ditonjolkan (1 baris besar) — arahkan pembeli ke sini, biaya termurah. --}}
                <button type="button" data-channel="qris"
                        class="channel-btn w-full flex items-center justify-between gap-3 border-2 border-gray-200 rounded-xl px-4 py-3.5 transition text-left mb-2">
                    <span class="flex flex-col">
                        <span class="text-base font-extrabold text-gray-800">QRIS</span>
                        <span class="text-[11px] text-gray-500">Scan pakai m-banking / e-wallet apa pun</span>
                    </span>
                    <span class="flex flex-col items-end">
                        <span class="fee-label text-sm font-bold">Tanpa biaya</span>
                        <span class="text-[10px] font-semibold text-emerald-600 whitespace-nowrap">★ Paling hemat</span>
                    </span>
                </button>
            @endif

            @if($lainnya->isNotEmpty())
                @if($qrisAktif)
                    <p class="text-[11px] text-gray-400 mb-1.5">Metode lain:</p>
                @endif
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($lainnya as $key => $label)
                        <button type="button" data-channel="{{ $key }}"
                                class="channel-btn flex flex-col items-start gap-0.5 border-2 border-gray-200 rounded-lg px-3 py-2 transition text-left hover:border-emerald-300">
                            <span class="text-xs font-semibold text-gray-700">{{ $label }}</span>
                            <span class="fee-label text-[10px] font-semibold">Tanpa biaya</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="border-t border-dashed pt-4">
            <div class="flex justify-between text-sm text-gray-600">
                <span>{{ ($isSo && !$requireFull) ? 'DP' : 'Tagihan' }}</span>
                <span>Rp <span id="d_base">0</span></span>
            </div>
            <div class="flex justify-between text-sm text-gray-600 mt-1" id="d_fee_row" style="display:none">
                <span>Biaya admin</span>
                <span>Rp <span id="d_fee">0</span></span>
            </div>
            <div class="flex justify-between text-base font-bold text-gray-900 mt-2 pt-2 border-t">
                <span>Total Pembayaran</span>
                <span>Rp <span id="d_total">0</span></span>
            </div>
        </div>

        <button id="btn_pay" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl py-3.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
            Bayar
        </button>
        <p class="text-[11px] text-center text-gray-400 -mt-1">Anda akan diarahkan ke halaman pembayaran Midtrans.</p>

        <div id="err_box" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
    </div>
</div>

<script>
(function () {
    const box = document.getElementById('paybox');
    if (!box) return;

    const IS_SO = box.dataset.isso === '1';
    const REQUIRE_FULL = box.dataset.requireFull === '1';   // pesanan web: wajib lunas, tanpa input DP
    const BASE = Number(box.dataset.base) || 0;
    const MIN_DP = Number(box.dataset.minDp) || 0;
    const REMAINING = Number(box.dataset.remaining) || 0;
    const FEE_THRESHOLD = Number(box.dataset.feeThreshold) || 0;
    const FEE_AMOUNT = Number(box.dataset.feeAmount) || 0;
    const SNAP_URL = box.dataset.snapUrl;
    let CHANNEL_FEES = {};
    try { CHANNEL_FEES = JSON.parse(box.dataset.channelFees || '{}') || {}; } catch (e) { CHANNEL_FEES = {}; }
    if (Array.isArray(CHANNEL_FEES)) CHANNEL_FEES = {};

    const errBox = document.getElementById('err_box');
    const btnPay = document.getElementById('btn_pay');
    const dpInput = document.getElementById('dp_input');

    // Bukan selalu 'qris': kalau QRIS dimatikan di Pengaturan, tombolnya tidak ada dan
    // pilihan awal harus jatuh ke metode aktif pertama.
    let channel = box.dataset.defaultChannel || 'qris';

    const fmt = n => Number(n).toLocaleString('id-ID');
    const getAmount = () => (IS_SO && !REQUIRE_FULL) ? Math.floor(Number(dpInput?.value) || 0) : BASE;

    // Cermin logika MidtransFeeCalculator::customerCharge() (per metode, model subsidi).
    function computeFee(ch, base) {
        const cf = CHANNEL_FEES[ch];
        if (cf) {
            const pct = Math.max(0, (Number(cf.mdr_percent) || 0) - (Number(cf.subsidy_percent) || 0));
            const flatFull = Math.max(0, (Number(cf.mdr_flat) || 0) - (Number(cf.subsidy_flat) || 0));
            const thr = Number(cf.flat_threshold) || 0;
            const flat = (thr <= 0 || base < thr) ? flatFull : 0;
            return Math.round(base * (pct / 100)) + flat;
        }
        // Fallback perilaku lama: VA di bawah threshold → biaya admin flat; lainnya 0.
        if (base >= FEE_THRESHOLD) return 0;
        return ch === 'va' ? FEE_AMOUNT : 0;
    }

    function refresh() {
        const amt = getAmount();

        document.querySelectorAll('.channel-btn').forEach(b => {
            const active = b.dataset.channel === channel;
            b.classList.toggle('border-emerald-500', active);
            b.classList.toggle('bg-emerald-50', active);
            b.classList.toggle('border-gray-200', !active);

            const fee = computeFee(b.dataset.channel, amt);
            const lbl = b.querySelector('.fee-label');
            if (lbl) {
                lbl.textContent = fee > 0 ? '+Rp ' + fmt(fee) : 'Tanpa biaya';
                lbl.classList.toggle('text-amber-600', fee > 0);
                lbl.classList.toggle('text-emerald-600', fee <= 0);
            }
        });

        // Tandai pintasan yang nilainya sedang dipakai — termasuk saat nominal diketik
        // manual, supaya tidak ada dua tombol yang sama-sama terlihat "terpilih".
        document.querySelectorAll('.dp-quick').forEach(b => {
            const active = Number(b.dataset.amount) === amt;
            b.classList.toggle('border-emerald-500', active);
            b.classList.toggle('bg-emerald-50', active);
            b.classList.toggle('border-gray-200', !active);
        });

        const fee = computeFee(channel, amt);
        document.getElementById('d_base').textContent = fmt(amt);
        document.getElementById('d_fee_row').style.display = fee > 0 ? 'flex' : 'none';
        document.getElementById('d_fee').textContent = fmt(fee);
        document.getElementById('d_total').textContent = fmt(amt + fee);
    }

    document.querySelectorAll('.channel-btn').forEach(b =>
        b.addEventListener('click', () => { channel = b.dataset.channel; refresh(); }));
    if (dpInput) dpInput.addEventListener('input', refresh);

    document.querySelectorAll('.dp-quick').forEach(b =>
        b.addEventListener('click', () => {
            if (!dpInput) return;
            dpInput.value = b.dataset.amount;
            refresh();
        }));

    function showErr(msg) { errBox.textContent = msg; errBox.classList.remove('hidden'); }

    btnPay.addEventListener('click', async () => {
        errBox.classList.add('hidden');
        const amt = getAmount();

        if (IS_SO && !REQUIRE_FULL) {
            if (amt < MIN_DP) { showErr('DP minimal Rp ' + fmt(MIN_DP) + '.'); return; }
            if (amt > REMAINING) { showErr('DP melebihi sisa tagihan (Rp ' + fmt(REMAINING) + ').'); return; }
        }

        btnPay.disabled = true;
        btnPay.textContent = 'Membuka halaman Midtrans…';
        try {
            const r = await fetch(SNAP_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ channel, amount: IS_SO ? amt : null }),
            });
            const d = await r.json();
            if (!r.ok || !d.redirect_url) throw new Error(d.error || 'Gagal membuka halaman Midtrans');
            location.href = d.redirect_url;
        } catch (e) {
            showErr(e.message);
            btnPay.disabled = false;
            btnPay.textContent = 'Bayar';
        }
    });

    // Init
    refresh();
})();
</script>
