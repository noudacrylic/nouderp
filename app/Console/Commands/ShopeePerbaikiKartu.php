<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use Illuminate\Console\Command;

/**
 * Pesan kartu Shopee yang tersalin sebelum jenisnya dikenali tersimpan sebagai teks mentah seperti
 * "[variation_card]"; isinya ditulis ulang dari raw-nya. Hanya kolom content. Aman diulang.
 */
class ShopeePerbaikiKartu extends Command
{
    private const JENIS = ['variation_card', 'out_of_stock_reminder_card', 'return_refund_card', 'track_rr_status_card', 'logistics_card'];

    protected $signature = 'shopee:perbaiki-kartu {--dry-run : Tampilkan saja, jangan ubah apa pun}';

    protected $description = 'Tulis ulang isi pesan kartu Shopee lama yang masih berupa teks mentah';

    public function handle(ShopeeChatService $chat): int
    {
        $shop = ShopeeShop::aktif();
        if (!$shop) {
            $this->error('Toko Shopee belum terhubung.');
            return self::FAILURE;
        }

        foreach (self::JENIS as $jenis) {
            $n = 0;
            CrmMessage::where('content', "[{$jenis}]")->orderByDesc('id')->each(function (CrmMessage $m) use ($chat, $shop, $jenis, &$n) {
                $isi = $chat->uraikan($shop, (array) $m->raw)['isi'] ?? null;
                if (!$isi || $isi === "[{$jenis}]") return;

                if (++$n <= 2) $this->line("#{$m->id}: " . str_replace("\n", ' | ', $isi));
                if (!$this->option('dry-run')) $m->forceFill(['content' => $isi])->save();
            });
            $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "{$jenis}: {$n} pesan.");
        }

        return self::SUCCESS;
    }
}
