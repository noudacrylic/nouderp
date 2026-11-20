<?php

namespace App\Modules\Marketplace\Shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Penjualan › Iklan Shopee — tahap 1, BACA SAJA: peta jam ramai, kinerja per iklan, jam modal
 * harian habis. Belum ada tombol yang mengubah iklan di Shopee.
 */
class IklanShopeeController extends Controller
{
    public const RENTANG = [7, 14, 30];

    public function __construct(private ShopeeClient $client, private ShopeeIklanService $iklan) {}

    public function index(Request $request)
    {
        $hari   = in_array((int) $request->input('hari'), self::RENTANG, true) ? (int) $request->input('hari') : 14;
        // Rentang = hari penuh sampai kemarin; hari ini (belum selesai) ditampilkan terpisah.
        $sampai = now()->subDay()->startOfDay();
        $dari   = $sampai->copy()->subDays($hari - 1);

        $petaJam = $this->iklan->petaJam($dari, $sampai);
        $harian  = $this->iklan->harian($dari, now()->startOfDay());
        $perIklan = $this->iklan->perIklan($dari, $sampai);
        $hitungStatus = collect($perIklan)->countBy(fn ($r) => $r['iklan']->status)->all();

        $status = $request->input('status', 'hidup');
        $cari   = trim((string) $request->input('cari'));
        $perIklan = array_values(array_filter($perIklan, function ($r) use ($status, $cari) {
            $i = $r['iklan'];
            if ($status === 'hidup' && !in_array($i->status, ['ongoing', 'paused', 'scheduled'], true)) {
                return false;
            }
            if ($status !== 'hidup' && $status !== 'semua' && $i->status !== $status) {
                return false;
            }

            return $cari === '' || mb_stripos((string) $i->nama, $cari) !== false;
        }));

        $total = [
            'biaya'   => array_sum(array_column($petaJam, 'biaya')),
            'gmv'     => array_sum(array_column($petaJam, 'gmv')),
            'pesanan' => array_sum(array_column($petaJam, 'pesanan')),
        ];
        $total['roas'] = $total['biaya'] > 0 ? $total['gmv'] / $total['biaya'] : null;

        $shop = ShopeeShop::aktif();

        return view('erp.sales.iklan-shopee.index', [
            'hari'         => $hari,
            'dari'         => $dari,
            'sampai'       => $sampai,
            'petaJam'      => $petaJam,
            'harian'       => $harian,
            'perIklan'     => $perIklan,
            'hitungStatus' => $hitungStatus,
            'status'       => $status,
            'cari'         => $cari,
            'total'        => $total,
            'saldo'        => Cache::get(ShopeeIklanService::CACHE_SALDO),
            'tarik'        => Cache::get(ShopeeIklanService::CACHE_TARIK),
            'shop'         => $shop,
            'stok'         => $this->stokListing($perIklan),
        ]);
    }

    /** Tarik ulang hari ini + kemarin dari Shopee (beberapa detik). */
    public function tarik(Request $request)
    {
        $shop = ShopeeShop::aktif();
        if (!$this->client->siap() || !$shop || $shop->perluHubungkanUlang()) {
            return back()->with('error', 'Toko Shopee belum terhubung — cek Pengaturan › Integrasi › Shopee.');
        }

        try {
            $h = $this->iklan->tarik($shop, 1);
        } catch (ShopeeException $e) {
            return back()->with('error', 'Gagal menarik data iklan: ' . $e->getMessage());
        }

        return back()->with($h['galat'] ? 'warning' : 'success',
            "Data iklan diperbarui: {$h['iklan']} iklan." . ($h['galat'] ? ' Sebagian gagal: ' . implode('; ', $h['galat']) : ''));
    }

    /** Stok total listing Shopee per item_id (dari Peta SKU) — penanda iklan untuk barang yang habis. */
    private function stokListing(array $perIklan): array
    {
        $items = collect($perIklan)->flatMap(fn ($r) => $r['iklan']->item_ids ?? [])->unique()->values()->all();
        if (!$items) {
            return [];
        }

        return ShopeeItem::where('env', $this->client->env())->whereIn('item_id', $items)
            ->groupBy('item_id')->selectRaw('item_id, SUM(stok) stok')->pluck('stok', 'item_id')
            ->map(fn ($s) => (int) $s)->all();
    }
}
