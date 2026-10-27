<?php

namespace App\Modules\Marketplace\Shopee\Controllers;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeOrder;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeeKatalogService;
use App\Modules\Marketplace\Shopee\Services\ShopeePesananService;
use Illuminate\Http\Request;

/**
 * Shopee tahap 2 (BACA SAJA): Peta SKU (varian Shopee ↔ produk ERP) dan Pesanan Bayangan
 * (pesanan Shopee langsung vs SO dari Jubelio). Super admin saja, sama dengan pengaturan Shopee.
 */
class ShopeeBayanganController extends Controller
{
    public function __construct(private ShopeeClient $client) {}

    public function petaSku(Request $request)
    {
        $this->superAdmin($request);
        $shop = ShopeeShop::aktif();
        $q = ShopeeItem::with('product:id,sku,name')
            ->where('env', $this->client->env())
            ->when($shop, fn ($x) => $x->where('shop_id', $shop->shop_id));

        $hitung = [
            'semua'  => (clone $q)->count(),
            'belum'  => (clone $q)->whereNull('product_id')->count(),
            'manual' => (clone $q)->where('peta_sumber', ShopeeItem::PETA_MANUAL)->count(),
        ];

        $filter = $request->input('filter', 'belum');
        $cari   = trim((string) $request->input('cari'));
        $items  = $q->when($filter === 'belum', fn ($x) => $x->whereNull('product_id'))
            ->when($filter === 'manual', fn ($x) => $x->where('peta_sumber', ShopeeItem::PETA_MANUAL))
            ->when($cari !== '', fn ($x) => $x->where(fn ($w) => $w->where('nama', 'like', "%$cari%")
                ->orWhere('nama_model', 'like', "%$cari%")->orWhere('item_sku', 'like', "%$cari%")->orWhere('model_sku', 'like', "%$cari%")))
            ->orderBy('nama')->orderBy('nama_model')
            ->paginate(per_page_size())->withQueryString();

        return view('erp.settings.shopee.peta-sku', compact('shop', 'items', 'hitung', 'filter', 'cari'));
    }

    /** Pasang / lepas peta manual: isi SKU ERP, kosongkan untuk kembali ke cocok-SKU otomatis. */
    public function simpanPeta(Request $request, int $id)
    {
        $this->superAdmin($request);
        $item = ShopeeItem::findOrFail($id);
        $sku  = trim((string) $request->input('sku_erp'));

        if ($sku === '') {
            $produk = ($s = $item->sku()) ? Product::where('sku', $s)->value('id') : null;
            $item->update(['product_id' => $produk, 'peta_sumber' => $produk ? ShopeeItem::PETA_SKU : null]);
            return back()->with('success', 'Peta manual dilepas — kembali ke cocok SKU otomatis.');
        }

        $produk = Product::where('sku', $sku)->first();
        if (!$produk) {
            return back()->with('error', "SKU ERP \"$sku\" tidak ditemukan.");
        }
        $item->update(['product_id' => $produk->id, 'peta_sumber' => ShopeeItem::PETA_MANUAL]);

        return back()->with('success', "{$item->namaLengkap()} → {$produk->sku}.");
    }

    public function pesanan(Request $request)
    {
        $this->superAdmin($request);
        $shop = ShopeeShop::aktif();
        $dari   = $request->date('dari') ?? now()->subDays(7)->startOfDay();
        $sampai = ($request->date('sampai') ?? now())->copy()->endOfDay();

        $q = ShopeeOrder::where('env', $this->client->env())
            ->when($shop, fn ($x) => $x->where('shop_id', $shop->shop_id))
            ->whereBetween('dibuat_shopee_at', [$dari, $sampai]);

        $hitung = (clone $q)->selectRaw('hasil_banding, count(*) n')->groupBy('hasil_banding')->pluck('n', 'hasil_banding');
        $hasil  = $request->input('hasil');
        $cari   = trim((string) $request->input('cari'));

        $orders = $q->with(['items.product:id,sku', 'salesOrder:id,order_number,status'])
            ->when($hasil, fn ($x) => $x->where('hasil_banding', $hasil))
            ->when($cari !== '', fn ($x) => $x->where('order_sn', 'like', "%$cari%"))
            ->orderByRaw('FIELD(hasil_banding, ' . collect(array_keys(ShopeeOrder::HASIL))->map(fn ($h) => "'$h'")->implode(',') . ')')
            ->orderByDesc('dibuat_shopee_at')
            ->paginate(per_page_size())->withQueryString();

        return view('erp.settings.shopee.pesanan', compact('shop', 'orders', 'hitung', 'hasil', 'cari', 'dari', 'sampai'));
    }

    public function tarikKatalog(Request $request, ShopeeKatalogService $katalog)
    {
        $this->superAdmin($request);
        if (!($shop = ShopeeShop::aktif())) return back()->with('error', 'Belum ada toko terhubung.');

        try {
            $k = $katalog->tarik($shop);
        } catch (ShopeeException $e) {
            return back()->with('error', 'Gagal menarik katalog: ' . $e->getMessage());
        }

        return back()->with('success', "Katalog ditarik: {$k['item']} item, {$k['varian']} varian — terpeta {$k['terpeta']}, belum {$k['belum']}.");
    }

    public function tarikPesanan(Request $request, ShopeePesananService $pesanan)
    {
        $this->superAdmin($request);
        if (!($shop = ShopeeShop::aktif())) return back()->with('error', 'Belum ada toko terhubung.');
        $hari = min(30, max(1, (int) $request->input('hari', 3)));

        try {
            $p = $pesanan->tarik($shop, now()->subDays($hari), now());
        } catch (ShopeeException $e) {
            return back()->with('error', 'Gagal menarik pesanan: ' . $e->getMessage());
        }

        return back()->with('success', "{$p['pesanan']} pesanan ({$hari} hari terakhir) ditarik dan dibandingkan.");
    }

    /** Bandingkan ulang tanpa memanggil Shopee — mis. sesudah memasang peta SKU. */
    public function bandingUlang(Request $request, ShopeePesananService $pesanan)
    {
        $this->superAdmin($request);
        $n = 0;
        ShopeeOrder::with('items')->where('env', $this->client->env())->where('hasil_banding', '!=', 'cocok')
            ->chunkById(200, function ($orders) use ($pesanan, &$n) {
                foreach ($orders as $o) {
                    foreach ($o->items->whereNull('product_id') as $it) {
                        $pid = ShopeeItem::where(['env' => $o->env, 'shop_id' => $o->shop_id, 'item_id' => $it->item_id, 'model_id' => $it->model_id])->value('product_id')
                            ?: ($it->sku ? Product::where('sku', $it->sku)->value('id') : null);
                        if ($pid) $it->update(['product_id' => $pid]);
                    }
                    $pesanan->banding($o->fresh('items'));
                    $n++;
                }
            });

        return back()->with('success', "$n pesanan dibandingkan ulang.");
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403, 'Hanya super admin yang boleh membuka data Shopee.');
    }
}
