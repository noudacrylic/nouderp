<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;

/**
 * Menyetujui permintaan batal pembeli langsung di Shopee (v2.order.handle_buyer_cancellation).
 *
 * Status dibaca LANGSUNG dari Shopee tiap kali, bukan dari Pesanan Bayangan (bisa 15 menit
 * basi) maupun Jubelio (sering tertinggal setelah seller menyetujui di Seller Centre).
 * Hanya pesanan berstatus IN_CANCEL yang disetujui; yang sudah CANCELLED cukup dilaporkan.
 */
class ShopeePembatalanService
{
    public function __construct(private ShopeeClient $client)
    {
    }

    /** Siap dipakai: kunci terisi dan toko di lingkungan aktif terhubung. */
    public function siap(): bool
    {
        $shop = ShopeeShop::aktif();

        return $this->client->siap() && $shop && !$shop->perluHubungkanUlang();
    }

    /** Status pesanan di Shopee saat ini (READY_TO_SHIP, IN_CANCEL, CANCELLED, …); null bila tak ditemukan. */
    public function status(string $orderSn): ?string
    {
        $r = $this->client->panggil($this->toko(), 'GET', '/api/v2/order/get_order_detail', ['order_sn_list' => $orderSn]);

        return data_get($r, 'order_list.0.order_status');
    }

    /**
     * Pastikan pesanan batal di Shopee: IN_CANCEL → setujui; CANCELLED → sudah.
     *
     * @return array{success:bool, status:?string, disetujui:bool, message:string}
     */
    public function setujui(string $orderSn): array
    {
        try {
            $status = $this->status($orderSn);

            if ($status === 'CANCELLED') {
                return ['success' => true, 'status' => $status, 'disetujui' => false, 'message' => 'Pesanan sudah batal di Shopee.'];
            }
            if ($status !== 'IN_CANCEL') {
                return ['success' => false, 'status' => $status, 'disetujui' => false,
                    'message' => 'Shopee tidak mencatat permintaan batal untuk pesanan ini (status ' . ($status ?? 'tidak ditemukan') . ') — ERP tidak di-void.'];
            }

            $this->client->panggil($this->toko(), 'POST', '/api/v2/order/handle_buyer_cancellation', [
                'order_sn' => $orderSn, 'operation' => 'ACCEPT',
            ]);
        } catch (ShopeeException $e) {
            return ['success' => false, 'status' => null, 'disetujui' => false, 'message' => 'Shopee menolak: ' . $e->getMessage()];
        }

        return ['success' => true, 'status' => 'CANCELLED', 'disetujui' => true, 'message' => 'Pembatalan disetujui di Shopee.'];
    }

    private function toko(): ShopeeShop
    {
        $shop = ShopeeShop::aktif();
        if (!$shop || $shop->perluHubungkanUlang()) {
            throw new ShopeeException('toko_belum_terhubung', 'Toko Shopee belum terhubung di Pengaturan › Integrasi › Shopee.');
        }

        return $shop;
    }
}
