<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Core\Inventory\Product;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeOrder;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Carbon\Carbon;

/**
 * Tahap 2 — "pesanan bayangan": tarik pesanan Shopee langsung, simpan salinannya, lalu
 * bandingkan dengan SO yang dibuat Jubelio (customer_po_number 'SP-<order_sn>').
 * TIDAK menulis SO, stok, maupun jurnal — tujuannya membuktikan data langsung sama dengan
 * jalur Jubelio sebelum Jubelio dimatikan (tahap 4).
 */
class ShopeePesananService
{
    /** Batas Shopee untuk get_order_list: rentang waktu maksimal 15 hari per panggilan. */
    public const JENDELA_HARI = 15;

    /**
     * Data pembeli tidak disimpan di salinan (tak dibutuhkan untuk banding). Pengecualian:
     * buyer_user_id ke kolom sendiri — hanya ID, penghubung pesanan ke chat Shopee-nya.
     */
    private const BUANG = ['buyer_username', 'buyer_user_id', 'recipient_address', 'buyer_cpf_id', 'message_to_seller', 'note'];

    public function __construct(private ShopeeClient $client) {}

    /** @return array{pesanan: int, hasil: array<string,int>} */
    public function tarik(ShopeeShop $shop, Carbon $dari, Carbon $sampai): array
    {
        $sns = [];
        for ($awal = $dari->copy(); $awal->lt($sampai); $awal->addDays(self::JENDELA_HARI)) {
            $akhir = $awal->copy()->addDays(self::JENDELA_HARI)->min($sampai);
            $cursor = '';
            do {
                $r = $this->client->panggil($shop, 'GET', '/api/v2/order/get_order_list', [
                    'time_range_field' => 'update_time',
                    'time_from'        => $awal->timestamp,
                    'time_to'          => $akhir->timestamp,
                    'page_size'        => 100,
                    'cursor'           => $cursor,
                ]);
                foreach ($r['order_list'] ?? [] as $o) $sns[] = (string) $o['order_sn'];
                $cursor = (string) ($r['next_cursor'] ?? '');
            } while (!empty($r['more']) && $cursor !== '');
        }

        $hasil = [];
        foreach (array_chunk(array_values(array_unique($sns)), 50) as $kelompok) {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/order/get_order_detail', [
                'order_sn_list'            => implode(',', $kelompok),
                'response_optional_fields' => 'buyer_user_id,item_list,total_amount,shipping_carrier,pay_time',
            ]);
            foreach ($r['order_list'] ?? [] as $detail) {
                $order = $this->simpan($shop, $detail);
                $hasil[$order->hasil_banding] = ($hasil[$order->hasil_banding] ?? 0) + 1;
            }
        }

        return ['pesanan' => count(array_unique($sns)), 'hasil' => $hasil];
    }

    public function simpan(ShopeeShop $shop, array $d): ShopeeOrder
    {
        $order = ShopeeOrder::firstOrNew(['env' => $shop->env, 'shop_id' => $shop->shop_id, 'order_sn' => (string) $d['order_sn']]);
        $order->fill([
            'status'               => (string) ($d['order_status'] ?? ''),
            'buyer_user_id'        => !empty($d['buyer_user_id']) ? (int) $d['buyer_user_id'] : $order->buyer_user_id,
            'dibuat_shopee_at'     => isset($d['create_time']) ? Carbon::createFromTimestamp($d['create_time']) : null,
            'diperbarui_shopee_at' => isset($d['update_time']) ? Carbon::createFromTimestamp($d['update_time']) : null,
            'total'                => $d['total_amount'] ?? null,
            'kurir'                => isset($d['shipping_carrier']) ? mb_substr((string) $d['shipping_carrier'], 0, 100) : null,
            'raw'                  => array_diff_key($d, array_flip(self::BUANG)),
            'disinkron_at'         => now(),
        ])->save();

        $order->items()->delete();
        foreach ($d['item_list'] ?? [] as $it) {
            $itemId  = (int) ($it['item_id'] ?? 0);
            $modelId = (int) ($it['model_id'] ?? 0);
            $sku     = trim((string) (($it['model_sku'] ?? '') ?: ($it['item_sku'] ?? ''))) ?: null;
            $order->items()->create([
                'item_id'      => $itemId,
                'model_id'     => $modelId,
                'sku'          => $sku ? mb_substr($sku, 0, 100) : null,
                'nama'         => mb_substr((string) ($it['item_name'] ?? ''), 0, 255),
                'nama_model'   => ($it['model_name'] ?? '') !== '' ? mb_substr((string) $it['model_name'], 0, 255) : null,
                'qty'          => (int) ($it['model_quantity_purchased'] ?? 0),
                'harga_asli'   => $it['model_original_price'] ?? null,
                'harga_diskon' => $it['model_discounted_price'] ?? null,
                'product_id'   => $this->produkUntuk($shop, $itemId, $modelId, $sku),
            ]);
        }

        return $this->banding($order->fresh('items'));
    }

    /** Peta katalog (item+varian) dulu, baru SKU persis. */
    private function produkUntuk(ShopeeShop $shop, int $itemId, int $modelId, ?string $sku): ?int
    {
        $peta = ShopeeItem::where(['env' => $shop->env, 'shop_id' => $shop->shop_id, 'item_id' => $itemId, 'model_id' => $modelId])
            ->value('product_id');

        return $peta ?: ($sku ? Product::where('sku', $sku)->value('id') : null);
    }

    /** Bandingkan dengan SO Jubelio; isi hasil_banding + catatan_banding. */
    public function banding(ShopeeOrder $order): ShopeeOrder
    {
        $so = SalesOrder::where('customer_po_number', 'SP-' . $order->order_sn)->orderByRaw("status = 'void'")->orderByDesc('id')->first();
        $order->sales_order_id = $so?->id;
        $catatan = [];

        if (!$so) {
            $hasil = $order->status === 'UNPAID' ? 'belum_dibayar' : ($order->batal() ? 'cocok' : 'so_tidak_ada');
            if ($hasil === 'cocok') $catatan[] = 'Batal di Shopee dan memang tak ada SO.';
        } elseif ($order->batal() !== ($so->status === 'void') && $order->status !== 'UNPAID') {
            $hasil = 'beda_status';
            $catatan[] = "Shopee {$order->status}, SO {$so->status}.";
        } elseif ($order->items->contains(fn ($i) => !$i->product_id)) {
            $hasil = 'sku_belum_dipeta';
            $catatan[] = 'Belum dipetakan: ' . $order->items->whereNull('product_id')->map(fn ($i) => $i->sku ?: $i->nama)->implode(', ');
        } else {
            $hasil = $this->bandingBaris($order, $so, $catatan);
        }

        $order->hasil_banding   = $hasil;
        $order->catatan_banding = $catatan ? mb_substr(implode(' ', $catatan), 0, 2000) : null;
        $order->save();

        return $order;
    }

    private function bandingBaris(ShopeeOrder $order, SalesOrder $so, array &$catatan): string
    {
        $shopeeQty = []; $shopeeNilai = [];
        foreach ($order->items as $i) {
            $shopeeQty[$i->product_id]   = ($shopeeQty[$i->product_id] ?? 0) + $i->qty;
            $shopeeNilai[$i->product_id] = ($shopeeNilai[$i->product_id] ?? 0) + $i->qty * (float) $i->harga_diskon;
        }
        $soQty = []; $soNilai = [];
        foreach (SalesOrderItem::where('sales_order_id', $so->id)->whereNotNull('product_id')->get() as $i) {
            $soQty[$i->product_id]   = ($soQty[$i->product_id] ?? 0) + (float) $i->qty;
            $soNilai[$i->product_id] = ($soNilai[$i->product_id] ?? 0) + (float) $i->qty * (float) $i->net_unit_price;
        }

        $sku = fn ($pid) => Product::where('id', $pid)->value('sku') ?: "#$pid";
        foreach (array_unique(array_merge(array_keys($shopeeQty), array_keys($soQty))) as $pid) {
            $a = $shopeeQty[$pid] ?? 0; $b = $soQty[$pid] ?? 0;
            if (abs($a - $b) > 1e-6) $catatan[] = "{$sku($pid)}: Shopee {$a}, SO " . ($b + 0) . '.';
        }
        if ($catatan) return 'beda_barang';

        foreach ($shopeeNilai as $pid => $nilai) {
            if (abs($nilai - ($soNilai[$pid] ?? 0)) > 1) {
                $catatan[] = "{$sku($pid)}: Shopee Rp" . number_format($nilai, 0, ',', '.') . ', SO Rp' . number_format($soNilai[$pid] ?? 0, 0, ',', '.') . '.';
            }
        }
        return $catatan ? 'beda_harga' : 'cocok';
    }
}
