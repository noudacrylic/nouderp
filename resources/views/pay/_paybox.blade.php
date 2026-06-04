@php $isSo = $is_so ?? false; @endphp

<div class="px-6 py-5"
     id="paybox"
     data-isso="{{ $isSo ? 1 : 0 }}"
     data-fee-threshold="{{ $fee_threshold }}"
     data-fee-amount="{{ $fee_amount }}"
     data-base="{{ $isSo ? 0 : ($base_amount ?? 0) }}"
     data-min-dp="{{ $isSo ? ($min_dp ?? 0) : 0 }}"
     data-remaining="{{ $remaining ?? 0 }}"
     data-charge-url="{{ route('pay.charge', $trx->link_token) }}"
     data-status-url="{{ route('pay.status', $trx->link_token) }}"
     data-done-url="{{ route('pay.done', $trx->link_token) }}">

    {{-- ============ STATE 1: PILIH METODE ============ --}}
    <div id="pay_select" class="space-y-4 {{ $instruction ? 'hidden' : '' }}">
        @if($isSo)
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Nominal DP yang Dibayar</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">Rp</span>
                    <input id="dp_input" type="number" inputmode="numeric"
                           min="{{ $min_dp }}" max="{{ $remaining }}" value="{{ $min_dp }}"
                           class="w-full border-2 border-gray-200 rounded-xl pl-9 pr-3 py-3 text-lg font-bold focus:border-emerald-400 focus:outline-none">
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    Minimal DP <b>Rp {{ number_format($min_dp, 0, ',', '.') }}</b> &middot; maksimal Rp {{ number_format($remaining, 0, ',', '.') }}.
                </p>
            </div>
        @endif

        <div>
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Pilih Metode Bayar</label>
            <div class="grid grid-cols-3 gap-2" id="channel_grid">
                <button type="button" data-channel="qris" class="channel-btn flex flex-col items-center justify-center gap-0.5 border-2 border-emerald-500 bg-emerald-50 text-emerald-700 rounded-xl py-3 transition hover:bg-emerald-100">
                    <span class="text-xs font-bold uppercase tracking-wide">QRIS</span>
                    <span class="text-[10px] font-semibold text-emerald-600">Tanpa biaya</span>
                </button>
                <button type="button" data-channel="va" class="channel-btn flex flex-col items-center justify-center gap-0.5 border-2 border-gray-200 text-gray-600 rounded-xl py-3 transition hover:border-emerald-300">
                    <span class="text-xs font-bold uppercase tracking-wide">VA</span>
                    <span id="va_fee_label" class="text-[10px] font-semibold text-gray-400">Tanpa biaya</span>
                </button>
                <button type="button" data-channel="bank_transfer" class="channel-btn flex flex-col items-center justify-center gap-0.5 border-2 border-gray-200 text-gray-600 rounded-xl py-3 transition hover:border-emerald-300">
                    <span class="text-xs font-bold uppercase tracking-wide">Transfer</span>
                    <span id="bt_fee_label" class="text-[10px] font-semibold text-gray-400">Tanpa biaya</span>
                </button>
            </div>
            <p id="fee_notice" class="hidden mt-2 text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2">
                Nominal di bawah Rp {{ number_format($fee_threshold, 0, ',', '.') }}: <b>VA</b> &amp; <b>Transfer Bank</b> dikenakan biaya admin <b>Rp {{ number_format($fee_amount, 0, ',', '.') }}</b>. <b>QRIS</b> tanpa biaya.
            </p>
        </div>

        <div id="bank_picker" class="hidden">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Pilih Bank Virtual Account</label>
            <div class="grid grid-cols-2 gap-2">
                @foreach (['bca'=>'BCA', 'mandiri'=>'Mandiri', 'bri'=>'BRI', 'permata'=>'Permata'] as $code => $label)
                    <button type="button" data-bank="{{ $code }}" class="bank-btn border-2 border-gray-200 text-gray-700 rounded-lg py-2.5 text-sm font-semibold hover:border-emerald-300 transition">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="border-t border-dashed pt-4">
            <div class="flex justify-between text-sm text-gray-600">
                <span>{{ $isSo ? 'DP' : 'Tagihan' }}</span>
                <span>Rp <span id="d_base">0</span></span>
            </div>
            <div class="flex justify-between text-sm text-gray-600" id="d_fee_row" style="display:none">
                <span>Biaya admin</span>
                <span>Rp <span id="d_fee">0</span></span>
            </div>
            <div class="flex justify-between text-base font-bold text-gray-900 mt-2 pt-2 border-t">
                <span>Total Bayar</span>
                <span>Rp <span id="d_total">0</span></span>
            </div>
        </div>

        <button id="btn_pay" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl py-3.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
            Tampilkan Cara Bayar
        </button>
        <div id="err_box" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
    </div>

    {{-- ============ STATE 2: INSTRUKSI BAYAR ============ --}}
    <div id="pay_instruction" class="space-y-4 {{ $instruction ? '' : 'hidden' }}">
        <div id="instruction_body"></div>
        <div id="pay_status" class="text-center text-xs text-gray-500">Menunggu pembayaran… status diperbarui otomatis.</div>
        <button id="btn_change" class="w-full border-2 border-gray-200 text-gray-600 hover:border-emerald-300 font-bold rounded-xl py-3 text-sm transition">
            Ubah metode pembayaran
        </button>
    </div>
</div>

<script>
(function () {
    const box = document.getElementById('paybox');
    if (!box) return;

    const IS_SO = box.dataset.isso === '1';
    const BASE = Number(box.dataset.base) || 0;
    const MIN_DP = Number(box.dataset.minDp) || 0;
    const REMAINING = Number(box.dataset.remaining) || 0;
    const FEE_THRESHOLD = Number(box.dataset.feeThreshold) || 0;
    const FEE_AMOUNT = Number(box.dataset.feeAmount) || 0;
    const CHARGE_URL = box.dataset.chargeUrl;
    const STATUS_URL = box.dataset.statusUrl;
    const DONE_URL = box.dataset.doneUrl;
    const INITIAL = @json($instruction);

    const selEl = document.getElementById('pay_select');
    const instrEl = document.getElementById('pay_instruction');
    const bodyEl = document.getElementById('instruction_body');
    const statusEl = document.getElementById('pay_status');
    const errBox = document.getElementById('err_box');
    const btnPay = document.getElementById('btn_pay');
    const dpInput = document.getElementById('dp_input');

    let channel = 'qris';
    let bank = null;
    let pollTimer = null;

    const fmt = n => Number(n).toLocaleString('id-ID');
    const getAmount = () => IS_SO ? Math.floor(Number(dpInput?.value) || 0) : BASE;
    const computeFee = amt => (['va', 'bank_transfer'].includes(channel) && amt < FEE_THRESHOLD) ? FEE_AMOUNT : 0;

    function refresh() {
        const amt = getAmount();
        document.querySelectorAll('.channel-btn').forEach(b => {
            const active = b.dataset.channel === channel;
            b.className = 'channel-btn flex flex-col items-center justify-center gap-0.5 border-2 rounded-xl py-3 transition ' +
                (active ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-gray-200 text-gray-600 hover:border-emerald-300');
        });

        const feeApplies = amt < FEE_THRESHOLD;
        ['va_fee_label', 'bt_fee_label'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = feeApplies ? '+Rp ' + fmt(FEE_AMOUNT) : 'Tanpa biaya';
            el.className = 'text-[10px] font-semibold ' + (feeApplies ? 'text-amber-600' : 'text-gray-400');
        });
        document.getElementById('fee_notice').classList.toggle('hidden', !feeApplies);

        const needBank = ['va', 'bank_transfer'].includes(channel);
        document.getElementById('bank_picker').classList.toggle('hidden', !needBank);
        if (!needBank) bank = null;
        document.querySelectorAll('.bank-btn').forEach(b => {
            const active = b.dataset.bank === bank;
            b.className = 'bank-btn border-2 rounded-lg py-2.5 text-sm font-semibold transition ' +
                (active ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-gray-200 text-gray-700 hover:border-emerald-300');
        });

        const fee = computeFee(amt);
        document.getElementById('d_base').textContent = fmt(amt);
        document.getElementById('d_fee_row').style.display = fee > 0 ? 'flex' : 'none';
        document.getElementById('d_fee').textContent = fmt(fee);
        document.getElementById('d_total').textContent = fmt(amt + fee);
    }

    document.querySelectorAll('.channel-btn').forEach(b =>
        b.addEventListener('click', () => { channel = b.dataset.channel; refresh(); }));
    document.querySelectorAll('.bank-btn').forEach(b =>
        b.addEventListener('click', () => { bank = b.dataset.bank; refresh(); }));
    if (dpInput) dpInput.addEventListener('input', refresh);

    function copyBtn(value) {
        return `<button type="button" onclick="navigator.clipboard.writeText('${value}'); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Salin',1500)" class="px-3 py-2 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700 shrink-0">Salin</button>`;
    }
    function field(label, value, mono = true) {
        return `<div class="mb-3">
            <div class="text-xs text-gray-500 mb-1">${label}</div>
            <div class="flex gap-2 items-center">
                <div class="flex-1 border rounded-lg px-3 py-2 bg-gray-50 ${mono ? 'font-mono' : ''} text-lg font-bold tracking-wide">${value}</div>
                ${copyBtn(value)}
            </div>
        </div>`;
    }

    function renderInstruction(d) {
        let html = '';
        if (d.type === 'va') {
            html += `<div class="text-center text-sm text-gray-600 mb-3">Bayar via <b>Virtual Account ${d.bank}</b></div>`;
            html += field('Nomor Virtual Account', d.va_number);
        } else if (d.type === 'mandiri') {
            html += `<div class="text-center text-sm text-gray-600 mb-3">Bayar via <b>Mandiri Bill Payment</b></div>`;
            html += field('Kode Perusahaan (Biller)', d.biller_code);
            html += field('Kode Bayar (Bill Key)', d.bill_key);
        } else if (d.type === 'qris') {
            html += `<div class="text-center text-sm text-gray-600 mb-3">Scan QRIS dengan aplikasi bank / e-wallet</div>`;
            html += `<div class="flex justify-center"><img src="${d.qr_url}" alt="QRIS" class="w-56 h-56 object-contain border rounded-xl bg-white p-2"></div>`;
        }
        html += `<div class="flex justify-between text-base font-bold text-gray-900 mt-4 pt-3 border-t">
                    <span>Total Bayar</span><span class="text-emerald-700">Rp ${fmt(d.amount)}</span>
                 </div>`;
        bodyEl.innerHTML = html;
        selEl.classList.add('hidden');
        instrEl.classList.remove('hidden');
        startPolling();
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(async () => {
            try {
                const r = await fetch(STATUS_URL, { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                if (d.paid) {
                    stopPolling();
                    statusEl.innerHTML = '<span class="text-emerald-600 font-bold">✓ Pembayaran diterima!</span>';
                    setTimeout(() => location.href = d.redirect || DONE_URL, 1200);
                } else if (['expire', 'cancel', 'deny', 'failure'].includes(d.status)) {
                    stopPolling();
                    statusEl.innerHTML = `<span class="text-red-600 font-bold">Pembayaran ${d.status}.</span>`;
                }
            } catch (e) { /* keep polling */ }
        }, 3000);
    }
    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    document.getElementById('btn_change').addEventListener('click', () => {
        stopPolling();
        instrEl.classList.add('hidden');
        selEl.classList.remove('hidden');
    });

    btnPay.addEventListener('click', async () => {
        errBox.classList.add('hidden');
        const amt = getAmount();

        if (IS_SO) {
            if (amt < MIN_DP) { showErr('DP minimal Rp ' + fmt(MIN_DP) + '.'); return; }
            if (amt > REMAINING) { showErr('DP melebihi sisa tagihan (Rp ' + fmt(REMAINING) + ').'); return; }
        }
        if (['va', 'bank_transfer'].includes(channel) && !bank) { showErr('Silakan pilih bank.'); return; }

        btnPay.disabled = true;
        btnPay.textContent = 'Memproses…';
        try {
            const r = await fetch(CHARGE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ channel, bank, amount: IS_SO ? amt : null }),
            });
            const d = await r.json();
            if (!r.ok) throw new Error(d.error || 'Gagal memproses pembayaran');
            renderInstruction(d);
        } catch (e) {
            showErr(e.message);
        } finally {
            btnPay.disabled = false;
            btnPay.textContent = 'Tampilkan Cara Bayar';
        }
    });

    function showErr(msg) { errBox.textContent = msg; errBox.classList.remove('hidden'); }

    // Init
    refresh();
    if (INITIAL) renderInstruction(INITIAL);
})();
</script>
