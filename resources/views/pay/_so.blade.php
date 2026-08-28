@php
    $lunas  = $remaining <= 0;

    // Stok sudah tidak cukup → pembayaran ditutup. Tautan boleh disebar sejak SO draft,
    // jadi dua pembeli bisa memegang tautan atas barang yang sama; yang membayar
    // belakangan tidak boleh membuat stok minus. Kecuali SO ini memang disepakati
    // sebagai pesanan yang stoknya menyusul (keep stock).
    $kurangStok = $stock_shortages ?? [];

    $canPay = !$lunas && !$expired && empty($kurangStok);

    // Produk preorder = dibuat setelah dipesan (termasuk pesanan custom). Produksinya
    // baru dimulai begitu DP masuk — lihat PreorderAutoProductionService.
    $adaPreorder = $so->items->contains(fn ($i) => optional($i->product)->sale_type === 'preorder');
@endphp

<div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden">
    <div class="bg-emerald-600 px-6 py-5 text-white">
        <div class="text-xs uppercase tracking-widest opacity-80">Noud Acrylic</div>
        <div class="text-lg font-bold mt-1">{{ ($require_full ?? false) ? 'Pembayaran Pesanan' : 'Uang Muka (DP)' }} — {{ $so->order_number }}</div>
    </div>

    <div class="px-6 py-5 border-b">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-gray-500">Untuk</div>
                <div class="text-base font-semibold text-gray-800">{{ $customer?->name ?? '-' }}</div>
            </div>
            @if($lunas)
                <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">LUNAS</span>
            @endif
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
            <div>
                <div class="text-xs text-gray-500">Tanggal Pesanan</div>
                <div class="font-semibold">{{ \Carbon\Carbon::parse($so->order_date)->format('d M Y') }}</div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500">Total Pesanan</div>
                <div class="font-semibold">Rp {{ number_format($grand_total, 0, ',', '.') }}</div>
            </div>
        </div>

        <div class="mt-3 border-t border-dashed pt-3 space-y-1 text-sm">
            <div class="flex justify-between text-gray-600">
                <span>Sudah dibayar</span>
                <span>Rp {{ number_format($paid_amount, 0, ',', '.') }}</span>
            </div>
            <div class="flex justify-between font-bold text-gray-900">
                <span>Sisa tagihan</span>
                <span class="text-emerald-700">Rp {{ number_format(max(0, $remaining), 0, ',', '.') }}</span>
            </div>
        </div>

        {{-- Kartu ini sengaja hanya memuat ANGKA TOTAL — rinciannya ada di PDF. Label
             "Download Pesanan" dulu tidak memberi tahu itu, jadi pembeli bertanya
             "rinciannya mana?". Baris kedua yang menjawabnya; jangan dihapus. --}}
        <a href="{{ $pdf_url }}"
           class="mt-4 flex items-center justify-center gap-2.5 w-full border-2 border-emerald-500 text-emerald-700 hover:bg-emerald-50 rounded-xl py-2.5 px-3 transition">
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            <span class="text-left leading-tight">
                <span class="block text-sm font-bold">Unduh Nota Pesanan (PDF)</span>
                <span class="block text-[11px] font-medium text-emerald-600">Rincian barang, jumlah &amp; harga satuan</span>
            </span>
        </a>

        {{-- Jalan pulang ke toko. Halaman lacak tinggal di noudakrilik.com dan tetap
             hidup setelah tautan bayar ini kedaluwarsa — pertanyaan "pesanan saya
             sampai mana" justru baru muncul sesudah dibayar.

             Emas, bukan hijau — warna yang sama dengan penanda "tahap berjalan" di
             halaman lacak. Bobotnya ikut keadaan: selama masih ada tagihan, yang
             utama tetap tombol Bayar, jadi tombol ini cukup bergaris (setara tombol
             nota, tidak kalah). Begitu lunas tombol Bayar hilang dan INI yang jadi
             langkah berikutnya — barulah ia dipadatkan. --}}
        @if(!empty($track_url))
            <a href="{{ $track_url }}"
               class="mt-2 flex items-center justify-center gap-2 w-full font-bold rounded-xl py-2.5 text-sm transition
                      {{ $canPay
                            ? 'border-2 border-[#d99400] text-[#b87a00] hover:bg-amber-50'
                            : 'bg-[#d99400] hover:bg-[#b87a00] text-white shadow-sm' }}">
                Lihat status pesanan →
            </a>
        @endif
    </div>

    @if($canPay && $so->status === 'draft')
        {{-- Pesanan draft belum memesan stok — sampaikan apa adanya supaya pembeli tahu
             kenapa membayar lebih cepat itu penting. --}}
        <div class="px-6 pt-4">
            <div class="flex gap-2 rounded-xl bg-amber-50 border border-amber-200 px-3 py-2.5 text-[13px] leading-relaxed text-amber-800">
                <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <span>Stok <b>belum kami tahan</b>. Barang baru dialokasikan untuk Anda setelah pembayaran atau DP diterima — sebelum itu masih bisa terjual ke pembeli lain.</span>
            </div>
        </div>
    @endif

    @if($canPay && $adaPreorder)
        {{-- Pesanan custom/preorder: produksi baru jalan setelah ada uang masuk. --}}
        <div class="px-6 pt-3">
            <div class="flex gap-2 rounded-xl bg-sky-50 border border-sky-200 px-3 py-2.5 text-[13px] leading-relaxed text-sky-800">
                <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span>Pesanan ini dibuat <b>sesuai pesanan (custom/preorder)</b>. Produksi kami mulai setelah pembayaran atau <b>DP</b> diterima — jadi semakin cepat dibayar, semakin cepat barang Anda dikerjakan.</span>
            </div>
        </div>
    @endif

    @if($canPay)
        @include('pay._paybox')
    @elseif($lunas)
        <div class="px-6 py-6 text-center text-sm text-emerald-700 font-semibold">Pesanan ini sudah lunas. Terima kasih!</div>
    @elseif(!$expired && !empty($kurangStok))
        <div class="px-6 py-5">
            <div class="rounded-xl bg-red-50 border border-red-200 px-4 py-3.5">
                <div class="flex gap-2">
                    <svg class="w-5 h-5 shrink-0 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <div class="text-[13px] leading-relaxed text-red-800">
                        <div class="font-bold">Stok tersisa tidak mencukupi</div>
                        <p class="mt-1">Sebagian barang pada pesanan ini sudah habis diambil pembeli lain, jadi pembayaran kami tutup untuk sementara. <b>Mohon hubungi admin kami lagi</b> — pesanan Anda masih bisa dilanjutkan setelah stoknya kami siapkan.</p>
                        <ul class="mt-2 space-y-0.5">
                            @foreach($kurangStok as $k)
                                <li class="flex justify-between gap-3 border-t border-red-200/70 pt-1">
                                    <span class="font-semibold">{{ $k['name'] }}</span>
                                    <span class="whitespace-nowrap">dipesan {{ format_qty($k['needed']) }} &middot; tersisa {{ format_qty($k['available']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            <a href="{{ $pdf_url }}" class="mt-3 block text-center text-xs text-gray-500 hover:text-gray-700">Unduh nota pesanan (PDF) — rincian barang &amp; harga</a>
        </div>
    @else
        <div class="px-6 py-6 text-center text-sm text-gray-500">Kode pembayaran sebelumnya sudah kedaluwarsa. Silakan muat ulang halaman ini untuk membuat kode bayar baru, atau hubungi Noud Acrylic.</div>
    @endif

    {{-- Tautan ini tidak berbatas waktu: pembeli memakainya lagi untuk memantau
         pesanan dan mengunduh nota. Yang berbatas waktu hanya kode bayar (QRIS/VA)
         yang terbit saat tombol Bayar ditekan. --}}
    <div class="px-6 py-4 bg-gray-50 text-center text-xs text-gray-500">
        @if($trx->expired_at)
            Kode pembayaran berlaku hingga {{ $trx->expired_at->format('d M Y H:i') }}
        @else
            Simpan tautan ini &mdash; bisa dibuka kapan saja untuk memantau pesanan &amp; mengunduh nota.
        @endif
    </div>
</div>
