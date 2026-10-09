<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\LabelPesananService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Console\Command;

/**
 * Tautkan pesanan lama ke chat pelanggannya — susulan CRM Tahap 3.
 *
 * Tautan chat↔pesanan baru ada sejak 8 Okt 2026, dan sampai 9 Okt hanya SO
 * yang dibuat lewat tombol di chat yang tertaut. SO yang diketik di modul
 * Sales (orang pesan lewat WA) tidak pernah tertaut, jadi labelnya diam di
 * tempat walau OP-nya sudah dikerjakan.
 *
 * Aturannya sama dengan tautan otomatis (pelanggan punya tepat satu chat),
 * ditambah: HANYA pesanan yang masih berjalan. Pesanan yang sudah selesai
 * tidak ditautkan — kalau ia jadi pesanan terbaru chat itu, chat yang sedang
 * Tanya Harga untuk pesanan baru akan melompat ke Selesai lalu tertutup.
 *
 * Aman diulang: SO yang sudah tertaut dilewati.
 */
class CrmTautkanPesananLama extends Command
{
    protected $signature = 'crm:tautkan-pesanan-lama {--dry-run : Tampilkan saja, jangan ubah apa pun}';

    protected $description = 'Tautkan SO lama yang masih berjalan ke chat pelanggannya, lalu hitung ulang labelnya';

    public function handle(LabelPesananService $label): int
    {
        $kering = (bool) $this->option('dry-run');
        $baris  = [];

        SalesOrder::query()
            ->whereNotIn('status', ['void', 'cancelled'])
            ->whereNotIn('id', fn ($q) => $q->select('sales_order_id')->from('crm_pesanan_chat'))
            ->whereIn('customer_id', fn ($q) => $q->select('customer_id')->from('crm_conversations')->whereNotNull('customer_id'))
            ->orderBy('id')
            ->each(function (SalesOrder $so) use ($label, $kering, &$baris) {
                $chatId = $label->chatUntukTautanOtomatis($so);
                $kode   = $chatId ? $label->labelUntuk($so) : null;

                // Draft lama yang tak pernah dibayar = pesanan yang tak jadi.
                $draftBasi = $so->status === 'draft' && $so->created_at?->lt(now()->subDays(30));

                if (! $kode || $kode === LabelPesananService::SELESAI || $draftBasi) {
                    return;
                }

                $baris[] = [$so->order_number, $chatId, $kode];

                if (! $kering) {
                    $label->tautkanOtomatis($so);
                    $label->perbaruiUntukSo($so->id);
                }
            });

        $this->table(['SO', 'Chat', 'Label'], $baris);
        $this->info(($kering ? '[dry-run] akan ditautkan: ' : 'Ditautkan: ') . count($baris) . ' pesanan.');

        return self::SUCCESS;
    }
}
