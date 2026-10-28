<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Shopee\Models\ShopeeSetting;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Notifications\Services\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * Pengingat Telegram harian: Live API Partner Key Shopee hampir/sudah kedaluwarsa (H-30, H-14,
 * H-7, H-3, lalu tiap hari) dan toko Live yang izinnya habis. Tanpa pengingat, semua koneksi
 * Shopee berhenti diam-diam saat kunci mati.
 */
class ShopeeIngatkanKedaluwarsa extends Command
{
    protected $signature = 'shopee:ingatkan-kedaluwarsa';

    protected $description = 'Kirim pengingat Telegram bila kunci Live Shopee hampir kedaluwarsa atau toko perlu dihubungkan ulang';

    private const HARI_INGAT = [30, 14, 7];

    public function handle(TelegramNotifier $telegram): int
    {
        $setting = ShopeeSetting::singleton();
        $url = route('settings.integrations.shopee.index');
        $pesan = [];

        $sisa = $setting->sisaHariKunciLive();
        if ($sisa !== null && (in_array($sisa, self::HARI_INGAT, true) || $sisa <= 3)) {
            $tgl = $setting->live_key_expires_at->format('d/m/Y');
            $keadaan = match (true) {
                $sisa < 0  => "SUDAH kedaluwarsa sejak {$tgl} — koneksi Shopee berhenti!",
                $sisa === 0 => "kedaluwarsa HARI INI ({$tgl}).",
                default    => "kedaluwarsa {$sisa} hari lagi ({$tgl}).",
            };
            $pesan[] = "🔑 Live API Partner Key Shopee {$keadaan}\n"
                . "Perbarui di Shopee Open Platform › App Noudapp, lalu isi kunci & tanggal barunya di ERP.";
        }

        foreach (ShopeeShop::where('env', 'live')->get() as $shop) {
            if ($shop->perluHubungkanUlang()) {
                $nama = $shop->nama ?: $shop->shop_id;
                $pesan[] = "🔌 Toko Shopee {$nama}: izin kedaluwarsa — perlu dihubungkan ulang.";
            }
        }

        if (!$pesan) {
            $this->line('Tidak ada yang perlu diingatkan.');
            return self::SUCCESS;
        }

        $teks = "<b>Shopee Open Platform</b>\n\n" . e(implode("\n\n", $pesan)) . "\n\n" . $url;
        $terkirim = $telegram->notifyApprovers($teks);
        $this->info("Pengingat terkirim ke {$terkirim} chat.");

        return self::SUCCESS;
    }
}
