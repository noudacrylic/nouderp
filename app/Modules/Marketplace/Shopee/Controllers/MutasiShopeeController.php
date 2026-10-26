<?php

namespace App\Modules\Marketplace\Shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Shopee\Models\ShopeeWalletTransaction as Mutasi;
use App\Modules\Marketplace\Shopee\Services\MutasiShopeeService;
use Illuminate\Http\Request;

/**
 * Keuangan › Rekonsiliasi Marketplace › Mutasi Saldo Shopee: tiap baris mutasi Saldo Penjual
 * dicocokkan dengan jurnal 1104, plus saldo harian Shopee vs ERP. Baca saja — belum memposting.
 */
class MutasiShopeeController extends Controller
{
    public function __construct(private MutasiShopeeService $svc) {}

    public function index(Request $request)
    {
        $tab = $request->tab === 'transaksi' ? 'transaksi' : 'harian';

        $ringkas = [
            'n'        => Mutasi::count(),
            'dari'     => Mutasi::min('waktu'),
            'sampai'   => Mutasi::max('waktu'),
            'terakhir' => Mutasi::orderByDesc('waktu')->orderByDesc('urutan')->first(),
            'cocok'    => Mutasi::whereNotNull('cocok')->selectRaw('cocok, count(*) n, sum(jumlah) total')->groupBy('cocok')->get()->keyBy('cocok'),
            'kategori' => Mutasi::where('cocok', '!=', Mutasi::COCOK)->selectRaw('kategori, count(*) n, sum(jumlah) total')
                ->groupBy('kategori')->get(),
        ];

        $harian = $tab === 'harian' ? $this->svc->harian($request->dari, $request->sampai) : [];

        $transaksi = $tab === 'transaksi'
            ? Mutasi::query()
                ->when($request->filled('cocok'), fn ($q) => $q->where('cocok', $request->cocok))
                ->when($request->filled('kategori'), fn ($q) => $q->where('kategori', $request->kategori))
                ->when($request->filled('dari'), fn ($q) => $q->whereDate('waktu', '>=', $request->dari))
                ->when($request->filled('sampai'), fn ($q) => $q->whereDate('waktu', '<=', $request->sampai))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                    ->where('order_sn', 'like', '%' . $request->q . '%')->orWhere('deskripsi', 'like', '%' . $request->q . '%')))
                ->orderByDesc('waktu')->orderByDesc('urutan')
                ->paginate(per_page_size())->withQueryString()
            : null;

        $ringkas['erp'] = $ringkas['terakhir'] ? $this->svc->saldoErp($ringkas['terakhir']->waktu->toDateString()) : null;

        $nomorJurnal = $transaksi
            ? \App\Core\Journal\Journal::whereIn('id', $transaksi->getCollection()->pluck('journal_ids')->flatten()->filter()->unique())
                ->pluck('journal_number', 'id')
            : collect();

        return view('erp.finance.mutasi-shopee.index', compact('tab', 'ringkas', 'harian', 'transaksi', 'nomorJurnal'));
    }

    public function impor(Request $request)
    {
        $request->validate([
            'files'   => 'required|array|min:1|max:12',
            'files.*' => 'file|mimes:xlsx|max:20480',
        ]);

        $hasil = ['baru' => 0, 'diperbarui' => 0, 'dibaca' => 0];
        try {
            foreach ($request->file('files') as $f) {
                $r = $this->svc->impor($f->getRealPath(), $request->user()?->id);
                foreach ($hasil as $k => $v) $hasil[$k] += $r[$k];
            }
            $cocok = $this->svc->cocokkan();
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$hasil['dibaca']} baris dibaca: {$hasil['baru']} baru, {$hasil['diperbarui']} diperbarui statusnya. "
            . 'Hasil pencocokan: ' . ($cocok[Mutasi::COCOK] ?? 0) . ' cocok, ' . ($cocok[Mutasi::SELISIH] ?? 0) . ' selisih, '
            . ($cocok[Mutasi::BELUM] ?? 0) . ' belum dijurnal.');
    }

    /** Cocokkan ulang — mis. setelah jurnal susulan diposting. */
    public function cocokkan()
    {
        $cocok = $this->svc->cocokkan();

        return back()->with('success', 'Dicocokkan ulang: ' . ($cocok[Mutasi::COCOK] ?? 0) . ' cocok, '
            . ($cocok[Mutasi::SELISIH] ?? 0) . ' selisih, ' . ($cocok[Mutasi::BELUM] ?? 0) . ' belum dijurnal.');
    }
}
