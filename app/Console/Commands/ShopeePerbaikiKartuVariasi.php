<?php

namespace App\Console\Commands;

use App\Modules\CRM\Models\CrmMessage;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use Illuminate\Console\Command;

/**
 * Pesan kartu variasi Shopee yang tersalin sebelum jenisnya dikenali tersimpan sebagai teks
 * "[variation_card]"; isinya ditulis ulang dari raw-nya. Aman diulang.
 */
class ShopeePerbaikiKartuVariasi extends Command
{
    protected $signature = 'shopee:perbaiki-kartu-variasi {--dry-run : Tampilkan saja, jangan ubah apa pun}';

    protected $description = 'Tulis ulang isi pesan [variation_card] lama menjadi nama produk & variannya';

    public function handle(ShopeeChatService $chat): int
    {
        $shop = ShopeeShop::aktif();
        if (!$shop) {
            $this->error('Toko Shopee belum terhubung.');
            return self::FAILURE;
        }

        $n = 0;
        CrmMessage::where('content', '[variation_card]')->orderBy('id')->each(function (CrmMessage $m) use ($chat, $shop, &$n) {
            $isi = $chat->uraikan($shop, (array) $m->raw)['isi'] ?? null;
            if (!$isi) return;

            $n++;
            if ($n <= 5) $this->line("#{$m->id}: {$isi}");
            if (!$this->option('dry-run')) $m->forceFill(['content' => $isi])->save();
        });

        $this->info(($this->option('dry-run') ? '[dry-run] akan ditulis ulang: ' : 'Ditulis ulang: ') . $n . ' pesan.');

        return self::SUCCESS;
    }
}
