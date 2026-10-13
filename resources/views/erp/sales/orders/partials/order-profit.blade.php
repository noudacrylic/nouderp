{{--
    Kartu Laba Pesanan — penjualan − HPP barang − biaya pesanan − potongan marketplace.
    AKTUAL dari faktur posted (HPP FIFO dari Surat Jalan), TAKSIRAN sebelum ada faktur (HPP
    Analisa × qty). Sebelum pajak. Lihat SalesOrderProfitService. Param: $so.

    HANYA super_admin (permintaan pemilik, 13 Okt 2026): HPP & keuntungan per pesanan bukan
    untuk admin/CS. Disaring sebelum dihitung — untuk peran lain tak ada yang dihitung sama sekali.
--}}
@php
    $laba = $so && $so->status !== 'void' && $so->status !== 'draft'
            && auth()->user()?->role === 'super_admin'
        ? app(\App\Modules\Sales\Services\SalesOrderProfitService::class)->untukPesanan($so)
        : null;
@endphp

@if($laba)
@php
    $rpL  = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $rpLn = fn ($n) => '− Rp ' . number_format(abs((float) $n), 0, ',', '.');
    $aktual = $laba['sumber'] === 'aktual';
@endphp
<div class="mt-4">
    <div class="bg-white border border-gray-100 rounded-lg shadow-sm p-4 text-sm">
        <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
            <div>
                <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Laba Pesanan</div>
                <div class="text-xs text-gray-500 mt-0.5">
                    @if($aktual)
                        Dari {{ $laba['faktur'] }} faktur posted — HPP barang sesuai jurnal (FIFO dari Surat Jalan). Sebelum pajak.
                    @else
                        Belum ada faktur posted — HPP barang ditaksir dari HPP per unit di Analisa. Angka aktual muncul setelah faktur diposting. Sebelum pajak.
                    @endif
                </div>
            </div>
            <span class="px-2 py-0.5 rounded text-[11px] font-semibold {{ $aktual ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                {{ $aktual ? 'Aktual · dari faktur' : 'Taksiran' }}
            </span>
        </div>

        <div class="max-w-md ml-auto">
            <div class="flex justify-between py-1 text-gray-600">
                <span>Penjualan</span><span class="tabular-nums">{{ $rpL($laba['penjualan']) }}</span>
            </div>
            <div class="flex justify-between py-1 text-gray-700">
                <span>HPP barang</span><span class="tabular-nums text-red-500">{{ $rpLn($laba['hpp']) }}</span>
            </div>
            @if($laba['biaya_pesanan'] > 0)
                <div class="flex justify-between py-1 text-gray-700">
                    <span>Biaya pesanan</span><span class="tabular-nums text-red-500">{{ $rpLn($laba['biaya_pesanan']) }}</span>
                </div>
            @endif
            @if($laba['potongan_mp'] !== null)
                <div class="flex justify-between py-1 text-gray-700">
                    <span>Potongan marketplace <span class="text-[11px] text-gray-400">({{ $laba['potongan_final'] ? 'final' : 'estimasi' }}, tanpa pajak)</span></span>
                    <span class="tabular-nums text-red-500">{{ $rpLn($laba['potongan_mp']) }}</span>
                </div>
            @endif
            <div class="flex justify-between pt-2 mt-1 border-t border-gray-100 font-semibold">
                <span>Keuntungan</span>
                <span class="tabular-nums {{ $laba['laba'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">
                    {{ $laba['laba'] < 0 ? $rpLn($laba['laba']) : $rpL($laba['laba']) }}
                    @if($laba['margin'] !== null)
                        <span class="text-xs font-normal text-gray-500">({{ number_format($laba['margin'], 1, ',', '.') }}%)</span>
                    @endif
                </span>
            </div>
            @if($laba['hpp_kosong'])
                <div class="text-[11px] text-amber-700 mt-2">
                    HPP belum terukur untuk {{ implode(', ', $laba['hpp_kosong']) }} — tidak ikut dihitung, keuntungan sebenarnya lebih kecil.
                </div>
            @endif
        </div>
    </div>
</div>
@endif
