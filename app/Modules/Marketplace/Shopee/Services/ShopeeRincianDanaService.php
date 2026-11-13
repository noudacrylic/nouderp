<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Models\MarketplaceOrderFee;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\MarketplaceFeeBreakdownService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Rincian potongan pesanan Shopee langsung dari Shopee (get_escrow_detail) — angka yang sama
 * dengan "Informasi Pembayaran" + "Penyesuaian Pesanan" di Seller Centre, termasuk retur /
 * refund SETELAH dana dilepas. Menggantikan angka Jubelio untuk pesanan Shopee (peringkat
 * sumber lebih tinggi; lihat MarketplaceFeeBreakdownService::simpan).
 */
class ShopeeRincianDanaService
{
    public function __construct(
        private ShopeeClient $client,
        private MarketplaceFeeBreakdownService $rincian,
    ) {}

    /** Nomor pesanan Shopee dari SO (SO Shopee: customer_po_number "SP-<order_sn>"). */
    public static function orderSn(SalesOrder $so): ?string
    {
        $po = (string) $so->customer_po_number;

        return str_starts_with($po, 'SP-') && strlen($po) > 3 ? substr($po, 3) : null;
    }

    /** Toko Shopee siap dipanggil, atau null. */
    public function toko(): ?ShopeeShop
    {
        $shop = $this->client->siap() ? ShopeeShop::aktif() : null;

        return $shop && !$shop->perluHubungkanUlang() ? $shop : null;
    }

    /**
     * Ambil & simpan rincian satu pesanan. null = bukan pesanan Shopee / toko belum terhubung /
     * Shopee tak membawa rincian dana.
     *
     * @throws ShopeeException gangguan API
     */
    public function ambil(SalesOrder $so, ?ShopeeShop $shop = null): ?MarketplaceOrderFee
    {
        $sn = self::orderSn($so);
        $shop ??= $this->toko();
        if (!$sn || !$shop) {
            return null;
        }

        $r  = $this->client->panggil($shop, 'GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $sn]);
        $oi = ($r['response'] ?? $r)['order_income'] ?? null;
        if (!is_array($oi) || !isset($oi['escrow_amount'])) {
            return null;
        }

        // Angka escrow baru final setelah pesanan selesai.
        $d = $this->client->panggil($shop, 'GET', '/api/v2/order/get_order_detail', ['order_sn_list' => $sn]);
        $selesai = (($d['response'] ?? $d)['order_list'][0]['order_status'] ?? null) === 'COMPLETED';

        return $this->rincian->simpan($so, $this->rincian->dariShopee($oi, (float) $so->grand_total, $selesai));
    }

    /**
     * Sapuan berkala: pesanan Shopee yang baru selesai (≤30 hari) dan belum punya rincian final
     * dari Shopee, plus pesanan yang returnya bergerak (≤45 hari) — penyesuaian setelah dana
     * dilepas bisa muncul berhari-hari kemudian. Tiap pesanan paling sering 12 jam sekali.
     *
     * @return array{dicek:int, tersimpan:int, gagal:int}
     */
    public function sapu(int $batas = 40): array
    {
        $h = ['dicek' => 0, 'tersimpan' => 0, 'gagal' => 0];
        if (!($shop = $this->toko())) {
            return $h;
        }

        $basi = now()->subHours(12);
        $belumSegar = fn ($q) => $q->whereNotExists(fn ($f) => $f->from('marketplace_order_fees as f')
            ->whereColumn('f.sales_order_id', 'sales_orders.id')->where('f.sumber', 'shopee')->where('f.diambil_at', '>=', $basi));

        $selesai = SalesOrder::where('customer_po_number', 'like', 'SP-%')->where('status', '!=', 'void')
            ->whereExists(fn ($q) => $q->from('jubelio_order_links as l')->whereColumn('l.sales_order_id', 'sales_orders.id')
                ->where('l.mp_completed_at', '>=', now()->subDays(30)))
            ->whereNotExists(fn ($q) => $q->from('marketplace_order_fees as f')->whereColumn('f.sales_order_id', 'sales_orders.id')
                ->where('f.sumber', 'shopee')->where('f.final', true))
            ->tap($belumSegar)->orderByDesc('id')->limit($batas * 5)->pluck('id');

        $retur = SalesOrder::where('customer_po_number', 'like', 'SP-%')->where('status', '!=', 'void')
            ->whereExists(fn ($q) => $q->from('sales_returns as r')->where('r.updated_at', '>=', now()->subDays(45))
                ->where(fn ($w) => $w->whereColumn('r.sales_order_id', 'sales_orders.id')
                    ->orWhereExists(fn ($i) => $i->from('sales_invoices as i')->whereColumn('i.id', 'r.invoice_id')
                        ->whereColumn('i.sales_order_id', 'sales_orders.id'))))
            ->tap($belumSegar)->orderByDesc('id')->limit($batas * 5)->pluck('id');

        // Pesanan yang Shopee-nya tak membawa rincian tetap tak berubah → jangan dicoba ulang
        // tiap putaran (menghalangi antrean di belakangnya).
        $ids = $retur->merge($selesai)->unique()->reject(fn ($id) => Cache::has("shopee-rincian-dana:$id"))->take($batas);

        foreach (SalesOrder::whereIn('id', $ids)->get() as $so) {
            $h['dicek']++;
            Cache::put("shopee-rincian-dana:{$so->id}", true, now()->addHours(12));
            try {
                if ($this->ambil($so, $shop)) {
                    $h['tersimpan']++;
                }
            } catch (\Throwable $e) {
                $h['gagal']++;
                Log::warning('Shopee rincian dana gagal', ['so' => $so->id, 'error' => $e->getMessage()]);
            }
        }

        return $h;
    }
}
