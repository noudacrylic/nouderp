<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Isi balik No. Faktur Pemasok di faktur pembelian dari PO sumbernya.
 *
 * Nomor itu lazim diisi di PO, tapi dulu tidak ikut terbawa saat PO dijadikan
 * faktur. Sumber PO dicari dari purchase_order_id faktur, atau dari item faktur
 * yang merujuk item PO. Faktur yang sudah berisi nomor tidak disentuh; faktur
 * yang itemnya merujuk >1 PO dengan nomor berbeda dilewati (ambigu).
 */
class IsiNoFakturPemasok extends Command
{
    protected $signature = 'purchasing:isi-no-faktur-pemasok {--dry-run : Tampilkan saja, jangan ubah data}';

    protected $description = 'Salin No. Faktur Pemasok dari PO ke faktur pembelian yang masih kosong';

    public function handle(): int
    {
        $baris = DB::table('purchase_invoices as pi')
            ->leftJoin('purchase_invoice_items as pii', 'pii.purchase_invoice_id', '=', 'pi.id')
            ->leftJoin('purchase_order_items as poi', 'poi.id', '=', 'pii.purchase_order_item_id')
            ->leftJoin('purchase_orders as po', function ($j) {
                $j->on('po.id', '=', DB::raw('COALESCE(pi.purchase_order_id, poi.purchase_order_id)'));
            })
            ->where(fn ($q) => $q->whereNull('pi.supplier_invoice_no')->orWhere('pi.supplier_invoice_no', ''))
            ->whereNotNull('po.supplier_invoice_no')
            ->where('po.supplier_invoice_no', '<>', '')
            ->select('pi.id', 'pi.invoice_number', 'po.po_number', 'po.supplier_invoice_no')
            ->distinct()
            ->get()
            ->groupBy('id');

        $diisi = 0;
        $ambigu = 0;
        foreach ($baris as $id => $kandidat) {
            $nomor = $kandidat->pluck('supplier_invoice_no')->map(fn ($n) => trim($n))->unique();
            if ($nomor->count() > 1) {
                $ambigu++;
                $this->warn("Lewati {$kandidat->first()->invoice_number}: PO berbeda nomor (" . $nomor->implode(', ') . ')');
                continue;
            }
            $this->line("{$kandidat->first()->invoice_number} ← {$nomor->first()} (dari {$kandidat->pluck('po_number')->unique()->implode(', ')})");
            if (!$this->option('dry-run')) {
                DB::table('purchase_invoices')->where('id', $id)
                    ->where(fn ($q) => $q->whereNull('supplier_invoice_no')->orWhere('supplier_invoice_no', ''))
                    ->update(['supplier_invoice_no' => $nomor->first(), 'updated_at' => now()]);
            }
            $diisi++;
        }

        $this->info(($this->option('dry-run') ? '[UJI] akan diisi: ' : 'Diisi: ') . $diisi . " faktur; dilewati (ambigu): {$ambigu}.");
        return self::SUCCESS;
    }
}
