<?php

namespace App\Console\Commands;

use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Skills\Admin\BalasUlasanBagus;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeUlasanService;
use Illuminate\Console\Command;

/**
 * Ulasan pembeli Shopee → shopee_ulasan, lalu draf balasan AI untuk ulasan jelek yang belum
 * dibalas (bahan kartu "Balas ulasan" di Meja Layanan), dan balasan ulasan bagus (BalasUlasanBagus). Draf hanya dibuat selama skill
 * admin.ulasan-jelek tidak Nonaktif — supaya token AI tak terpakai untuk kartu yang tak tampil.
 */
class ShopeeTarikUlasan extends Command
{
    protected $signature = 'shopee:tarik-ulasan {--hari=60 : Jendela tanggal ulasan ke belakang} {--tanpa-draf : Jangan buat draf AI}';

    protected $description = 'Tarik ulasan pembeli Shopee & buat draf balasan untuk ulasan jelek';

    public function handle(ShopeeClient $client, ShopeeUlasanService $svc, KaryawanVirtualService $kv): int
    {
        if (!$client->siap() || !($shop = ShopeeShop::aktif()) || $shop->perluHubungkanUlang()) {
            $this->warn('Toko Shopee belum terhubung.');

            return self::SUCCESS;
        }
        $this->line($svc->tarik($shop, (int) $this->option('hari')) . ' ulasan disimpan.');

        // Ulasan bagus dibalas Manajer Admin sendiri (mode & jurnal diatur karyawan virtual).
        // Lebih dulu dari draf: ulasan bagus yang ternyata keluhan langsung ikut didrafkan di bawah.
        $jalan = $kv->jalankan(BalasUlasanBagus::KEY, KaryawanVirtualService::PEMICU_JADWAL);
        $this->line('Ulasan bagus: mode ' . $jalan['mode'] . ' — ' . count($jalan['hasil']) . ' ulasan.');

        if ($this->option('tanpa-draf') || $kv->mode('admin.ulasan-jelek') === Skill::NONAKTIF) {
            return self::SUCCESS;
        }
        foreach ($svc->calonDraf() as $u) {
            $svc->buatDraf($u);
            $this->line("Draf SP-{$u->order_sn} (⭐{$u->rating_star}): " . ($u->draf_galat ? 'gagal — ' . $u->draf_galat : 'siap'));
        }

        return self::SUCCESS;
    }
}
