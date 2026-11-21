<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Iklan Shopee — tahap 1 (BACA SAJA, tidak mengubah apa pun di Shopee).
 *
 * Menarik dari Ads API:
 *  - daftar iklan produk + pengaturannya (status, modal harian, target ROAS) → shopee_iklan
 *  - kinerja per jam: total toko (campaign_id 0) + per iklan yang masih hidup → shopee_iklan_jam
 *  - saldo iklan → cache
 * lalu menyusun ringkasan untuk halaman Iklan Shopee: peta jam ramai, kinerja per iklan, dan
 * jam modal harian habis (bahan rancangan jadwal/rotasi tahap 2).
 *
 * Tanggal di Ads API berformat dd-mm-YYYY, jam = jam toko (WIB).
 * Pesanan/GMV memakai atribusi "broad" (sama dengan angka utama di Seller Centre).
 */
class ShopeeIklanService
{
    private const PER_PANGGILAN = 50;

    /** Modal dianggap habis bila belanja sehari mencapai porsi ini dari modal harian. */
    public const AMBANG_HABIS = 0.95;

    public const CACHE_SALDO = 'shopee_iklan_saldo';

    public const CACHE_TARIK = 'shopee_iklan_tarik_terakhir';

    public function __construct(private ShopeeClient $client, private \App\Modules\Notifications\Services\TelegramNotifier $telegram) {}

    /**
     * Tarik semuanya: daftar iklan, saldo, dan kinerja hari ini + $hariMundur hari ke belakang.
     *
     * @return array{iklan:int, hari:int, baris:int, galat:array<int,string>}
     */
    public function tarik(ShopeeShop $shop, int $hariMundur = 1): array
    {
        $galat = [];
        $iklan = $this->tarikDaftar($shop);

        try {
            $this->tarikSaldo($shop);
        } catch (ShopeeException $e) {
            $galat[] = 'saldo: ' . $e->getMessage();
        }

        $baris = 0;
        for ($d = $hariMundur; $d >= 0; $d--) {
            $tgl = now()->startOfDay()->subDays($d);
            try {
                $baris += $this->tarikKinerja($shop, $tgl);
            } catch (ShopeeException $e) {
                $galat[] = $tgl->format('d M') . ': ' . $e->getMessage();
            }
        }

        Cache::forever(self::CACHE_TARIK, ['waktu' => now()->toIso8601String(), 'iklan' => $iklan, 'baris' => $baris, 'galat' => $galat]);

        return ['iklan' => $iklan, 'hari' => $hariMundur + 1, 'baris' => $baris, 'galat' => $galat];
    }

    /** Daftar semua iklan produk + pengaturannya. @return int jumlah iklan */
    public function tarikDaftar(ShopeeShop $shop): int
    {
        $ids    = [];
        $offset = 0;
        do {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/ads/get_product_level_campaign_id_list', ['offset' => $offset, 'limit' => 100]);
            foreach ($r['campaign_list'] ?? [] as $x) {
                $ids[] = (int) $x['campaign_id'];
            }
            $offset += 100;
        } while (($r['has_next_page'] ?? false) && $offset < 10000);

        $env      = $this->client->env();
        $lama     = ShopeeIklan::where('env', $env)->whereNotNull('kelompok_id')->get(['campaign_id', 'modal_harian', 'nama'])->keyBy('campaign_id');
        $naik     = [];
        foreach (array_chunk($ids, 100) as $potong) {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/ads/get_product_level_campaign_setting_info', [
                'campaign_id_list' => implode(',', $potong),
                'info_type_list'   => '1,3', // 1 = info umum, 3 = lelang otomatis (target ROAS)
            ]);
            foreach ($r['campaign_list'] ?? [] as $c) {
                $u    = $c['common_info'] ?? [];
                $sblm = $lama[(int) $c['campaign_id']] ?? null;
                if ($sblm && (float) ($u['campaign_budget'] ?? 0) > (float) $sblm->modal_harian) {
                    $naik[] = mb_substr((string) $sblm->nama, 0, 45) . ': Rp' . number_format((float) $sblm->modal_harian, 0, ',', '.')
                        . ' → Rp' . number_format((float) $u['campaign_budget'], 0, ',', '.');
                }
                $masa = $u['campaign_duration'] ?? [];
                ShopeeIklan::updateOrCreate(
                    ['env' => $env, 'campaign_id' => (int) $c['campaign_id']],
                    [
                        'shop_id'        => $shop->shop_id,
                        'ad_type'        => $u['ad_type'] ?? null,
                        'nama'           => isset($u['ad_name']) ? mb_substr($u['ad_name'], 0, 255) : null,
                        'status'         => $u['campaign_status'] ?? null,
                        'bidding_method' => $u['bidding_method'] ?? null,
                        'placement'      => $u['campaign_placement'] ?? null,
                        'modal_harian'   => (float) ($u['campaign_budget'] ?? 0),
                        'roas_target'    => $c['auto_bidding_info']['roas_target'] ?? null,
                        'item_ids'       => $u['item_id_list'] ?? [],
                        'mulai_at'       => !empty($masa['start_time']) ? Carbon::createFromTimestamp($masa['start_time']) : null,
                        'selesai_at'     => !empty($masa['end_time']) ? Carbon::createFromTimestamp($masa['end_time']) : null,
                        'disinkron_at'   => now(),
                    ],
                );
            }
        }

        if ($naik) {
            try {
                $this->telegram->notifyApprovers("ℹ️ Modal harian Shopee naik pada " . count($naik) . " iklan anggota kelompok "
                    . "(belanja tetap dijaga modal ERP):\n- " . implode("\n- ", array_slice($naik, 0, 15)));
            } catch (\Throwable) {
                // kabar saja
            }
        }

        return count($ids);
    }

    public function tarikSaldo(ShopeeShop $shop): void
    {
        $r = $this->client->panggil($shop, 'GET', '/api/v2/ads/get_total_balance');
        Cache::forever(self::CACHE_SALDO, ['saldo' => (float) ($r['total_balance'] ?? 0), 'waktu' => now()->toIso8601String()]);
    }

    /** Kinerja per jam satu tanggal: total toko + per iklan yang masih hidup. @return int baris tersimpan */
    public function tarikKinerja(ShopeeShop $shop, Carbon $tanggal): int
    {
        $env   = $this->client->env();
        $tgl   = $tanggal->format('d-m-Y');
        $baris = [];

        $toko = $this->client->panggil($shop, 'GET', '/api/v2/ads/get_all_cpc_ads_hourly_performance', ['performance_date' => $tgl]);
        foreach ($toko as $m) {
            if (is_array($m) && isset($m['hour'])) {
                $baris[] = $this->baris($env, 0, $tanggal, $m);
            }
        }

        $ids = ShopeeIklan::where('env', $env)->whereIn('status', ShopeeIklan::STATUS_HIDUP)->pluck('campaign_id')->all();
        foreach (array_chunk($ids, self::PER_PANGGILAN) as $potong) {
            $r = $this->client->panggil($shop, 'GET', '/api/v2/ads/get_product_campaign_hourly_performance', [
                'performance_date' => $tgl,
                'campaign_id_list' => implode(',', $potong),
            ]);
            foreach ($r['campaign_list'] ?? [] as $c) {
                foreach ($c['metrics_list'] ?? [] as $m) {
                    $baris[] = $this->baris($env, (int) $c['campaign_id'], $tanggal, $m);
                }
            }
        }

        foreach (array_chunk($baris, 500) as $potong) {
            DB::table('shopee_iklan_jam')->upsert(
                $potong,
                ['env', 'campaign_id', 'tanggal', 'jam'],
                ['tayang', 'klik', 'biaya', 'pesanan', 'gmv', 'pesanan_langsung', 'gmv_langsung', 'updated_at'],
            );
        }

        return count($baris);
    }

    private function baris(string $env, int $campaignId, Carbon $tanggal, array $m): array
    {
        return [
            'env'              => $env,
            'campaign_id'      => $campaignId,
            'tanggal'          => $tanggal->toDateString(),
            'jam'              => (int) $m['hour'],
            'tayang'           => (int) ($m['impression'] ?? 0),
            'klik'             => (int) ($m['clicks'] ?? 0),
            'biaya'            => (float) ($m['expense'] ?? 0),
            'pesanan'          => (int) ($m['broad_order'] ?? 0),
            'gmv'              => (float) ($m['broad_gmv'] ?? 0),
            'pesanan_langsung' => (int) ($m['direct_order'] ?? 0),
            'gmv_langsung'     => (float) ($m['direct_gmv'] ?? 0),
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
    }

    // ───────────────────────── Ringkasan untuk halaman ─────────────────────────

    /**
     * Total semua iklan toko per jam (0–23) dalam rentang, plus rata-rata per hari.
     *
     * @return array<int, array{jam:int, klik:int, biaya:float, pesanan:int, gmv:float, roas:?float, biaya_per_hari:float}>
     */
    public function petaJam(Carbon $dari, Carbon $sampai): array
    {
        $hari = max(1, $this->jumlahHariBerdata($dari, $sampai));
        $rows = DB::table('shopee_iklan_jam')
            ->where('env', $this->client->env())->where('campaign_id', 0)
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->groupBy('jam')
            ->selectRaw('jam, SUM(klik) klik, SUM(biaya) biaya, SUM(pesanan) pesanan, SUM(gmv) gmv')
            ->get()->keyBy('jam');

        $hasil = [];
        for ($j = 0; $j < 24; $j++) {
            $r       = $rows[$j] ?? null;
            $biaya   = (float) ($r->biaya ?? 0);
            $gmv     = (float) ($r->gmv ?? 0);
            $hasil[] = [
                'jam'            => $j,
                'klik'           => (int) ($r->klik ?? 0),
                'biaya'          => $biaya,
                'pesanan'        => (int) ($r->pesanan ?? 0),
                'gmv'            => $gmv,
                'roas'           => $biaya > 0 ? $gmv / $biaya : null,
                'biaya_per_hari' => $biaya / $hari,
            ];
        }

        return $hasil;
    }

    /** Total toko per tanggal (terbaru dulu). */
    public function harian(Carbon $dari, Carbon $sampai): array
    {
        return DB::table('shopee_iklan_jam')
            ->where('env', $this->client->env())->where('campaign_id', 0)
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->groupBy('tanggal')->orderByDesc('tanggal')
            ->selectRaw('tanggal, SUM(tayang) tayang, SUM(klik) klik, SUM(biaya) biaya, SUM(pesanan) pesanan, SUM(gmv) gmv')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => Carbon::parse($r->tanggal),
                'tayang'  => (int) $r->tayang,
                'klik'    => (int) $r->klik,
                'biaya'   => (float) $r->biaya,
                'pesanan' => (int) $r->pesanan,
                'gmv'     => (float) $r->gmv,
                'roas'    => $r->biaya > 0 ? $r->gmv / $r->biaya : null,
            ])->all();
    }

    /**
     * Kinerja tiap iklan (semua status; penyaringan di halaman), plus kapan
     * modal hariannya biasanya habis. Hari ini tidak dihitung untuk "habis" (belum selesai).
     *
     * @return array<int, array> diurutkan biaya terbesar dulu
     */
    public function perIklan(Carbon $dari, Carbon $sampai): array
    {
        $env  = $this->client->env();
        $rows = DB::table('shopee_iklan_jam')
            ->where('env', $env)->where('campaign_id', '>', 0)
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->orderBy('tanggal')->orderBy('jam')
            ->get(['campaign_id', 'tanggal', 'jam', 'klik', 'biaya', 'pesanan', 'gmv']);

        $per = [];
        foreach ($rows as $r) {
            $p              = &$per[$r->campaign_id];
            $p['klik']      = ($p['klik'] ?? 0) + $r->klik;
            $p['biaya']     = ($p['biaya'] ?? 0) + $r->biaya;
            $p['pesanan']   = ($p['pesanan'] ?? 0) + $r->pesanan;
            $p['gmv']       = ($p['gmv'] ?? 0) + $r->gmv;
            $p['jam'][$r->tanggal][$r->jam] = (float) $r->biaya;
            unset($p);
        }

        $iklan = ShopeeIklan::where('env', $env)->get();

        $hariIni = now()->toDateString();
        $belanjaHariIni = DB::table('shopee_iklan_jam')
            ->where('env', $env)->where('campaign_id', '>', 0)->where('tanggal', $hariIni)
            ->groupBy('campaign_id')->selectRaw('campaign_id, SUM(biaya) biaya')
            ->pluck('biaya', 'campaign_id');

        $hasil = [];
        foreach ($iklan as $i) {
            $p     = $per[$i->campaign_id] ?? [];
            $biaya = (float) ($p['biaya'] ?? 0);
            $gmv   = (float) ($p['gmv'] ?? 0);
            $habis = $this->jamHabis($p['jam'] ?? [], (float) $i->modal_harian, $hariIni);

            $hasil[] = [
                'iklan'      => $i,
                'klik'       => (int) ($p['klik'] ?? 0),
                'biaya'      => $biaya,
                'pesanan'    => (int) ($p['pesanan'] ?? 0),
                'gmv'        => $gmv,
                'roas'       => $biaya > 0 ? $gmv / $biaya : null,
                'hari_ini'   => (float) ($belanjaHariIni[$i->campaign_id] ?? 0),
                'hari_aktif' => $habis['hari_aktif'],
                'hari_habis' => $habis['hari_habis'],
                'jam_habis'  => $habis['jam'],
            ];
        }

        usort($hasil, fn ($a, $b) => $b['biaya'] <=> $a['biaya']);

        return $hasil;
    }

    /**
     * Dari belanja per jam per tanggal: berapa hari modal habis, dan median jam habisnya.
     *
     * @param  array<string, array<int, float>>  $perTanggal
     * @return array{hari_aktif:int, hari_habis:int, jam:?int}
     */
    public function jamHabis(array $perTanggal, float $modal, ?string $kecuali = null): array
    {
        $aktif = 0;
        $jam   = [];
        foreach ($perTanggal as $tgl => $perJam) {
            if ($tgl === $kecuali || array_sum($perJam) <= 0) {
                continue;
            }
            $aktif++;
            if ($modal <= 0) {
                continue;
            }
            ksort($perJam);
            $kumulatif = 0;
            foreach ($perJam as $j => $b) {
                $kumulatif += $b;
                if ($kumulatif >= $modal * self::AMBANG_HABIS) {
                    $jam[] = (int) $j;
                    break;
                }
            }
        }
        sort($jam);
        $median = $jam ? $jam[intdiv(count($jam), 2)] : null;

        return ['hari_aktif' => $aktif, 'hari_habis' => count($jam), 'jam' => $median];
    }

    private function jumlahHariBerdata(Carbon $dari, Carbon $sampai): int
    {
        return (int) DB::table('shopee_iklan_jam')
            ->where('env', $this->client->env())->where('campaign_id', 0)
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->distinct()->count('tanggal');
    }
}
