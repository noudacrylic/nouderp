<?php

namespace App\Console\Commands;

use App\Modules\Purchasing\Models\PengajuanBayarOwner;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use Illuminate\Console\Command;

/** Faktur lama yang belum lunas → daftar hutang di Pending Meja Owner. Sekali jalan; aman diulang. */
class MasukkanHutangLama extends Command
{
    protected $signature = 'pembelian:hutang-lama {--simpan : Benar-benar membuat pengajuan; tanpa ini hanya menampilkan daftar}';

    protected $description = 'Masukkan faktur pembelian lama yang belum lunas ke daftar hutang Meja Owner';

    public function handle(): int
    {
        $faktur = PurchaseInvoice::with('supplier:id,name')->where('status', 'posted')->where('outstanding_amount', '>', 0)
            ->whereNotIn('id', PengajuanBayarOwner::whereNotNull('purchase_invoice_id')->select('purchase_invoice_id'))
            ->orderBy('due_date')->orderBy('id')->get();

        $this->table(['Faktur', 'Pemasok', 'Sisa', 'Jatuh tempo'], $faktur->map(fn ($f) => [
            $f->invoice_number, $f->supplier?->name, number_format((float) $f->outstanding_amount, 0, ',', '.'), $f->due_date?->format('d/m/Y') ?? '-',
        ]));
        $this->info($faktur->count() . ' faktur, total Rp' . number_format((float) $faktur->sum('outstanding_amount'), 0, ',', '.'));

        if (!$this->option('simpan')) {
            $this->warn('Belum disimpan. Jalankan ulang dengan --simpan bila daftarnya benar.');
            return self::SUCCESS;
        }

        foreach ($faktur as $f) {
            PengajuanBayarOwner::create(['purchase_invoice_id' => $f->id, 'tempo' => true]);
        }
        $this->info('Tersimpan. Kartu muncul di Pending Meja Owner pada sinkron berikutnya.');

        return self::SUCCESS;
    }
}
