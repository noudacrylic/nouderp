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
    /** Jarak minimal antar-perintah tulis (detik) supaya tak kena batas kecepatan per toko. */
    public const JARAK_DETIK = 0.5;

    /** Tunggu sebelum mengulang saat kena batas kecepatan (ads.rate_limit.*). */
    public const TUNGGU_ULANG = [2, 5, 10];

    /** @var callable(float):void penunda — diganti di tes agar tak benar-benar menunggu */
    public $tidur;

    private float $terakhir = 0.0;

    public function __construct(private ShopeeClient $client)
    {
        $this->tidur = fn (float $detik) => usleep((int) round($detik * 1_000_000));
    }

    /**
     * Batas kecepatan Ads API (dokumentasi edit_manual_product_ads): ads.rate_limit.exceed_api (5),
     * .campaign_level (24), .exceed_shop_api (26), .exceed_partner_api (29) — "coba lagi nanti".
     */
    public static function batasKecepatan(ShopeeException $e): bool
    {
        return str_starts_with($e->kode, 'ads.rate_limit');
    }

    /** Satu perintah edit, diberi jarak dari perintah sebelumnya dan diulang bila kena batas kecepatan. */
    private function kirimEdit(ShopeeShop $shop, ShopeeIklan $iklan, string $editAction): void
    {
        $sela = self::JARAK_DETIK - (microtime(true) - $this->terakhir);
        if ($this->terakhir > 0 && $sela > 0) {
            ($this->tidur)($sela);
        }

        foreach ([...self::TUNGGU_ULANG, null] as $tunggu) {
            try {
                $this->client->panggil($shop, 'POST', '/api/v2/ads/edit_manual_product_ads', [
                    'reference_id' => 'noud-' . $editAction . '-' . $iklan->campaign_id . '-' . Str::lower(Str::random(8)),
                    'campaign_id'  => (int) $iklan->campaign_id,
                    'edit_action'  => $editAction,
                ]);
                $this->terakhir = microtime(true);

                return;
            } catch (ShopeeException $e) {
                $this->terakhir = microtime(true);
                if ($tunggu === null || !self::batasKecepatan($e)) {
                    throw $e;
                }
                ($this->tidur)($tunggu);
            }
        }
    }

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

    /** Perbarui status beberapa iklan sekaligus dari Shopee (maks 100 per panggilan). */
    public function segarkanStatus(ShopeeShop $shop, \Illuminate\Support\Collection $iklan): void
    {
        foreach ($iklan->chunk(100) as $potong) {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/ads/get_product_level_campaign_setting_info', [
                'campaign_id_list' => $potong->pluck('campaign_id')->implode(','), 'info_type_list' => '1',
            ]);
            $status = collect($r['campaign_list'] ?? [])->mapWithKeys(fn ($c) => [(int) $c['campaign_id'] => $c['common_info']['campaign_status'] ?? null]);
            foreach ($potong as $i) {
                if ($status->get($i->campaign_id)) {
                    $i->update(['status' => $status[$i->campaign_id], 'disinkron_at' => now()]);
                }
            }
        }
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
                $this->kirimEdit($shop, $iklan, $editAction);
                $ok    = true;
                $pesan = $editAction;
                break;
            } catch (ShopeeException $e) {
                $pesan = $editAction . ': ' . $e->getMessage();
                if (self::batasKecepatan($e)) {
                    break; // ditolak karena terlalu rapat, bukan karena nama aksinya — jangan coba 'start'
                }
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
