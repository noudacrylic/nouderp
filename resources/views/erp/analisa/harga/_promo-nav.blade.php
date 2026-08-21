{{-- Dua bentuk simulasi promo. Param: $mode ('transaksi' | 'produk'), $channel. --}}
<div class="inline-flex rounded-xl border border-slate-200 bg-white overflow-hidden mb-4">
    <a href="{{ route('analisa.harga.promo', ['kanal' => $channel['key']]) }}"
       class="px-4 py-2 text-sm font-semibold {{ $mode === 'transaksi' ? 'bg-slate-800 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
        Simulasi Transaksi
    </a>
    <a href="{{ route('analisa.harga.promo.produk', ['kanal' => $channel['key']]) }}"
       class="px-4 py-2 text-sm font-semibold border-l border-slate-200 {{ $mode === 'produk' ? 'bg-slate-800 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
        Per Produk
    </a>
</div>
