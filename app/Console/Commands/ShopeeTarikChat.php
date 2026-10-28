<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use Illuminate\Console\Command;

/**
 * Chat Shopee → CRM (Tahap A, baca saja). Tanpa --riwayat: percakapan yang berubah sejak
 * putaran terakhir (dijadwalkan tiap menit). Dengan --riwayat: mundur dari kursor tersimpan,
 * bisa dihentikan & dijalankan lagi — lanjut dari titik terakhir.
 */
class ShopeeTarikChat extends Command
{
    protected $signature = 'shopee:tarik-chat
                            {--riwayat : Tarik riwayat lama (mundur), lanjut dari kursor tersimpan}
                            {--halaman=10 : Jumlah halaman (60 percakapan) per jalan untuk --riwayat; 0 = sampai habis}';

    protected $description = 'Salin chat Shopee ke inbox CRM (baca saja)';

    public function handle(ShopeeClient $client, ShopeeChatService $chat): int
    {
        if (!$client->siap() || !($shop = ShopeeShop::aktif())) {
            $this->line('Kunci Shopee / toko belum terhubung — dilewati.');
            return self::SUCCESS;
        }

        try {
            if ($this->option('riwayat')) {
                if ($shop->chat_riwayat_selesai_at) {
                    $this->info('Riwayat chat sudah selesai ditarik ' . $shop->chat_riwayat_selesai_at->format('d/m/Y H:i') . '.');
                    return self::SUCCESS;
                }
                $halaman = (int) $this->option('halaman');
                do {
                    $h = $chat->tarikRiwayat($shop, $halaman > 0 ? $halaman : 10);
                    $this->info("Riwayat: {$h['diperiksa']} percakapan diperiksa — tampil {$h['tampil']}, tersembunyi (AI) {$h['tersembunyi']}, {$h['pesan']} pesan." . ($h['selesai'] ? ' SELESAI.' : ''));
                } while ($halaman === 0 && !$h['selesai']);
            } else {
                $h = $chat->tarikBaru($shop);
                $this->info("Chat: {$h['diperiksa']} percakapan berubah — tampil {$h['tampil']}, tersembunyi (AI) {$h['tersembunyi']}, {$h['pesan']} pesan baru.");
            }
        } catch (ShopeeException $e) {
            $this->error('Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
