<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Perintah TULIS ke iklan Shopee: jeda / lanjutkan satu iklan produk lewat
 * v2.ads.edit_manual_product_ads, lalu status dibaca balik dari Shopee (sukses kirim
 * belum tentu berarti statusnya berubah). Tiap aksi dicatat di shopee_iklan_aksi.
 */
class ShopeeIklanAksiService
{
    public function __construct(private ShopeeClient $client) {}

    /** Status iklan langsung dari Shopee (bukan salinan ERP). */
    public function statusDiShopee(ShopeeShop $shop, ShopeeIklan $iklan): ?string
    {
        $r = $this->client->panggil($shop, 'GET', '/api/v2/ads/get_product_level_campaign_setting_info', [
            'campaign_id_list' => (string) $iklan->campaign_id, 'info_type_list' => '1',
        ]);
        $status = $r['campaign_list'][0]['common_info']['campaign_status'] ?? null;
        if ($status) {
            $iklan->update(['status' => $status, 'disinkron_at' => now()]);
        }

        return $status;
    }

    /**
     * Jeda atau lanjutkan. Untuk "lanjut" dicoba edit_action `resume`, lalu `start` bila Shopee
     * menolak nama aksinya.
     *
     * @return array{ok:bool, status:?string, pesan:string}
     */
    public function ubah(ShopeeShop $shop, ShopeeIklan $iklan, string $aksi, string $mode, ?int $kelompokId = null, ?string $alasan = null): array
    {
        $daftar = $aksi === 'jeda' ? ['pause'] : ['resume', 'start'];
        $pesan  = '';
        $ok     = false;
        foreach ($daftar as $editAction) {
            try {
                $this->client->panggil($shop, 'POST', '/api/v2/ads/edit_manual_product_ads', [
                    'reference_id' => 'noud-' . $editAction . '-' . $iklan->campaign_id . '-' . Str::lower(Str::random(8)),
                    'campaign_id'  => (int) $iklan->campaign_id,
                    'edit_action'  => $editAction,
                ]);
                $ok    = true;
                $pesan = $editAction;
                break;
            } catch (ShopeeException $e) {
                $pesan = $editAction . ': ' . $e->getMessage();
            }
        }

        $status = null;
        try {
            $status = $this->statusDiShopee($shop, $iklan);
        } catch (ShopeeException $e) {
            $pesan .= ' · baca status gagal: ' . $e->getMessage();
        }
        $harap = $aksi === 'jeda' ? 'paused' : 'ongoing';
        $ok    = $ok && $status === $harap;

        DB::table('shopee_iklan_aksi')->insert([
            'env' => $iklan->env, 'kelompok_id' => $kelompokId, 'campaign_id' => $iklan->campaign_id,
            'aksi' => $aksi, 'mode' => $mode, 'hasil' => $ok ? 'berhasil' : 'gagal',
            'alasan' => $alasan, 'pesan' => mb_substr($pesan . ' → status ' . ($status ?? '?'), 0, 1000),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => $ok, 'status' => $status, 'pesan' => $pesan];
    }
}
