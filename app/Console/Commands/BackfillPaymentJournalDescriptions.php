<?php

namespace App\Console\Commands;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Modules\Sales\Services\CustomerPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill keterangan jurnal Customer Payment lama supaya menampilkan dokumen sumber
 * (Invoice utk pelunasan, Sales Order utk uang muka) menggantikan nomor payment yang
 * dulunya dipajang berulang. Nomor payment tetap tersimpan di kolom REF (reference_number).
 *
 * Rekonstruksi invoice/SO dari tabel customer_payment_allocations, lalu memakai resolver
 * yang SAMA dengan jalur posting live (CustomerPaymentService::documentReference) agar
 * hasil backfill identik dengan data baru.
 *
 * Idempoten: hanya menyentuh keterangan yang masih format lama (mengandung nomor payment
 * pada prefix yang ditarget). Aman dijalankan berkali-kali. Pakai --dry-run utk pratinjau.
 */
class BackfillPaymentJournalDescriptions extends Command
{
    protected $signature = 'payments:backfill-journal-descriptions {--dry-run : Hanya tampilkan yang akan diubah, tanpa menyimpan}';
    protected $description = 'Perbaiki keterangan jurnal Customer Payment lama: nomor payment -> nomor Invoice/SO (untuk audit)';

    /** Prefix keterangan baris yang boleh diubah (menghindari baris saldo/kelebihan bayar). */
    private const LINE_PREFIXES = [
        'Penerimaan Kas (Net) - ',
        'Biaya Admin/Bank - ',
        'Pelunasan Piutang - ',
        'Penerimaan Uang Muka SO - ',
    ];

    public function handle(CustomerPaymentService $service): int
    {
        $dry = (bool) $this->option('dry-run');

        $journalCount = 0;   // jumlah jurnal yang tersentuh
        $lineCount = 0;      // jumlah baris keterangan yang berubah
        $headerCount = 0;    // jumlah keterangan header jurnal yang berubah
        $skipFallback = 0;   // payment yang docRef-nya tetap = nomor payment (tak ada dok)

        $journals = Journal::where('reference_type', 'customer_payment')->orderBy('id');
        $total = (clone $journals)->count();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Memproses {$total} jurnal customer_payment...");

        $journals->chunkById(200, function ($chunk) use ($service, $dry, &$journalCount, &$lineCount, &$headerCount, &$skipFallback) {
            foreach ($chunk as $journal) {
                $payment = CustomerPayment::find($journal->reference_id);
                if (!$payment) {
                    continue; // jurnal yatim, lewati
                }

                $pn = $payment->payment_number;

                // Rekonstruksi invoice & SO dari alokasi
                $allocs = CustomerPaymentAllocation::where('customer_payment_id', $payment->id)->get();
                $invoiceIds = $allocs->whereNotNull('invoice_id')->pluck('invoice_id')->unique()->values()->all();
                $soIds = $allocs->whereNotNull('sales_order_id')->pluck('sales_order_id')->unique()->values()->all();

                $docRef = $service->documentReference($payment, $invoiceIds, $soIds);

                // Tidak ada dokumen -> docRef == nomor payment: tak ada yang bisa diperbaiki
                if ($docRef === $pn) {
                    $skipFallback++;
                    continue;
                }

                $typeLabel = $payment->payment_type === 'invoice' ? 'Pelunasan' : 'Uang Muka';
                $touchedJournal = false;

                // 1) Keterangan baris jurnal (hanya prefix yang ditarget & masih memuat nomor payment)
                $lines = JournalLine::where('journal_id', $journal->id)->get();
                foreach ($lines as $line) {
                    $desc = (string) $line->description;
                    foreach (self::LINE_PREFIXES as $prefix) {
                        if ($desc === $prefix . $pn) {
                            $newDesc = $prefix . $docRef;
                            if ($newDesc !== $desc) {
                                if ($dry) {
                                    $this->line("  L#{$line->id}: \"{$desc}\"  ->  \"{$newDesc}\"");
                                } else {
                                    $line->description = $newDesc;
                                    $line->save();
                                }
                                $lineCount++;
                                $touchedJournal = true;
                            }
                            break;
                        }
                    }
                }

                // 2) Keterangan header jurnal: "Customer Payment {pn}" -> "{typeLabel} - {docRef}"
                if ($journal->description === "Customer Payment {$pn}") {
                    $newHeader = "{$typeLabel} - {$docRef}";
                    if ($dry) {
                        $this->line("  J#{$journal->id}: \"{$journal->description}\"  ->  \"{$newHeader}\"");
                    } else {
                        $journal->description = $newHeader;
                        $journal->save();
                    }
                    $headerCount++;
                    $touchedJournal = true;
                }

                if ($touchedJournal) {
                    $journalCount++;
                }
            }
        });

        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '') . 'Selesai.');
        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Jurnal tersentuh', $journalCount],
                ['Baris keterangan diubah', $lineCount],
                ['Header jurnal diubah', $headerCount],
                ['Payment tanpa dokumen (dilewati)', $skipFallback],
            ]
        );

        if ($dry) {
            $this->comment('Ini pratinjau. Jalankan tanpa --dry-run untuk menyimpan.');
        }

        return self::SUCCESS;
    }
}
