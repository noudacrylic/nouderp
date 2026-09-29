<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Console\Command;

/**
 * Pindahkan retur DRAFT yang masih menempel ke Sales Order ke faktur SO tersebut.
 *
 * LATAR. Retur yang lahir sebelum faktur terbit menempel ke SO. Sejak faktur terbit, jalur
 * penerbitan faktur marketplace ikut memindahkan draft semacam itu — tapi yang terlanjur
 * (faktur terbit sebelum pemindahan otomatis ada) tetap tertinggal di SO. Command ini
 * membereskannya. Tanggal retur tidak diubah.
 *
 * Retur POSTED sengaja tidak disentuh: jurnalnya sudah membalik Uang Muka.
 */
class ReturPindahKeFaktur extends Command
{
    protected $signature = 'retur:pindah-ke-faktur
        {--dry-run : Hanya tampilkan yang akan dipindah, tanpa menulis apa pun}
        {--retur= : Batasi ke satu nomor retur}';

    protected $description = 'Pindahkan retur draft yang masih menempel ke SO ke faktur SO tersebut (tanggal retur tetap)';

    public function handle(SalesReturnService $service): int
    {
        $dry = (bool) $this->option('dry-run');

        $q = SalesReturn::with(['items', 'salesOrder:id,order_number'])
            ->whereNotNull('sales_order_id')
            ->whereNull('invoice_id')
            ->where('status', 'draft')
            ->orderBy('id');

        if ($nomor = $this->option('retur')) {
            $q->where('return_number', $nomor);
        }

        $returs = $q->get();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Ditemukan {$returs->count()} retur draft yang masih menempel ke SO.");

        $pindah = 0; $tertinggal = 0;

        foreach ($returs as $retur) {
            $label = "{$retur->return_number} ({$retur->salesOrder?->order_number}, tgl {$retur->return_date?->format('d/m/Y')})";
            $hasil = $service->fakturUntukReturSO($retur);

            if (!$hasil['invoice']) {
                $this->line("  • {$label}: tetap di SO — {$hasil['alasan']}");
                $tertinggal++;
                continue;
            }

            $this->line("  • {$label} → {$hasil['invoice']->invoice_number}");

            if (!$dry) {
                $service->pindahkanReturKeFaktur($retur, $hasil['invoice']);
            }
            $pindah++;
        }

        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Dipindah: {$pindah} · tetap di SO: {$tertinggal}");

        if ($dry) {
            $this->comment('Tidak ada yang ditulis. Jalankan tanpa --dry-run untuk menerapkan.');
        }

        return self::SUCCESS;
    }
}
