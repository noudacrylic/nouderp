<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use App\Modules\Marketplace\Shopee\Services\ShopeeSaldoService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Tahap 1b — tarik mutasi Saldo Penjual Shopee lewat API, lalu buat draft Transfer Bank
 * (penarikan dana) & draft Pengeluaran (isi ulang iklan). Tanpa --dari: sejak sehari sebelum
 * mutasi API terakhir (pertama kali: awal hari ini). --dari untuk menarik mundur sekali jalan.
 */
class ShopeeTarikSaldo extends Command
{
    protected $signature = 'shopee:tarik-saldo
                            {--dari= : Tanggal mulai (Y-m-d) untuk menarik mundur}';

    protected $description = 'Tarik mutasi saldo Shopee + draft penarikan dana & isi ulang iklan';

    public function handle(ShopeeClient $client, ShopeeSaldoService $saldo): int
    {
        if (!$client->siap() || !($shop = ShopeeShop::aktif()) || $shop->perluHubungkanUlang()) {
            $this->line('Kunci Shopee / toko belum terhubung — dilewati.');

            return self::SUCCESS;
        }

        try {
            $h = $saldo->tarik($shop, $this->option('dari') ? Carbon::parse($this->option('dari'))->startOfDay() : null);
        } catch (ShopeeException $e) {
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Mutasi baru {$h['baru']} — draft transfer {$h['draft_transfer']}, draft iklan {$h['draft_iklan']}, sudah tercatat {$h['sudah_tercatat']}.");

        return self::SUCCESS;
    }
}
