<?php

namespace App\Console\Commands;

use App\Modules\Analysis\Services\ChannelPricingService;
use App\Modules\Marketplace\Jubelio\Services\JubelioProductSyncService;
use Illuminate\Console\Command;

/**
 * Tarik harga yang sedang dipegang Jubelio untuk sebuah kanal, seluruh katalog sekaligus.
 *
 * Tombol di halaman Harga sengaja dibatasi per klik supaya permintaan web tidak kehabisan
 * waktu. Perintah ini tidak punya batasan itu, jadi ia yang dipakai untuk sapuan penuh —
 * dan untuk uji hidup pertama, karena keluarannya menyebut persis apa yang tidak terbaca
 * bila bentuk respons Jubelio ternyata berbeda dari dugaan.
 */
class PullJubelioPrices extends Command
{
    protected $signature = 'jubelio:tarik-harga
                            {kanal : kunci kanal, mis. shopee / tokopedia / lazada}
                            {--limit=1000 : berapa produk sekali jalan}';

    protected $description = 'Tarik harga toko dari Jubelio untuk satu kanal (Analisa ▸ Harga Produk)';

    public function handle(ChannelPricingService $pricing, JubelioProductSyncService $sync): int
    {
        $kunci   = (string) $this->argument('kanal');
        $channel = $pricing->channel($kunci);

        if (!$channel) {
            $this->error("Kanal '{$kunci}' tidak dikenal. Yang ada: "
                . $pricing->channels()->keys()->implode(', '));

            return self::FAILURE;
        }
        if (($channel['kind'] ?? null) === 'internal') {
            $this->error('Kanal Website tidak lewat Jubelio — harganya harga master.');

            return self::FAILURE;
        }
        if (empty($channel['store_ids'])) {
            $this->error("Kanal {$channel['label']} belum punya toko Jubelio yang dipetakan.");

            return self::FAILURE;
        }

        $this->info(sprintf('Menarik harga %s (toko %s)…',
            $channel['label'], implode(', ', $channel['store_ids'])));

        $stats = $sync->pullStorePrices($channel['store_ids'], null, (int) $this->option('limit'));

        if (array_sum($stats) === 0) {
            $this->error('Jubelio belum tersambung — cek Pengaturan ▸ Integrasi.');

            return self::FAILURE;
        }

        $this->table(['terisi', 'kosong', 'gagal', 'belum giliran'], [[
            $stats['terisi'], $stats['kosong'], $stats['gagal'], $stats['sisa'],
        ]]);

        // "Gagal" di sini paling sering berarti bentuk respons Jubelio tidak seperti dugaan.
        // Alasannya tersimpan per produk di jubelio_store_prices.note — sebutkan jalannya,
        // supaya yang menjalankan tidak perlu menebak ke mana harus melihat.
        if ($stats['gagal'] > 0) {
            $this->warn('Ada yang gagal. Alasannya tersimpan di kolom `note` tabel '
                . '`jubelio_store_prices` dan tampil sebagai tooltip "—" di halaman Harga.');
        }

        return self::SUCCESS;
    }
}
