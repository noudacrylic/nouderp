<?php

namespace App\Modules\Sales\Services;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Enums\AccountCodeEnum;
use App\Models\SalesInvoice;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Models\CashDisbursementLine;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Biaya Pesanan — pengeluaran yang melekat pada SATU pesanan (tukang pasang dari luar,
 * jasa antar, sewa alat) dan harus ikut jadi HPP pesanan itu.
 *
 * Sumbernya baris Pengeluaran Umum yang diberi `sales_order_id`. Siklusnya:
 *
 *   1. Pengeluaran di-post   → Dr 1204 Biaya Pesanan Ditangguhkan / Cr Kas.
 *                               Belum jadi beban: pendapatannya belum diakui, dan tukang
 *                               sering dibayar di bulan yang berbeda dari fakturnya.
 *   2. Faktur SO di-post     → Dr akun beban baris (bawaan 5008 HPP Jasa) / Cr 1204,
 *                               bertanggal faktur — biaya & pendapatan jatuh di bulan sama.
 *      Dibayar SETELAH faktur → dipindah saat itu juga, bertanggal pengeluaran.
 *   3. Faktur di-void        → pemindahan dibatalkan; biaya kembali menunggu faktur
 *                               (atau langsung pindah ke faktur posted lain milik SO itu).
 *   4. Pengeluaran di-void   → jurnal kas & pemindahannya sama-sama batal.
 *
 * Satu jurnal pemindahan per baris, supaya void satu pengeluaran tidak menyentuh baris
 * pengeluaran lain yang kebetulan diakui di faktur yang sama.
 */
class SalesOrderCostService
{
    public const REFERENCE_TYPE = 'sales_order_cost';

    public function deferredAccountId(): int
    {
        $id = Account::where('code', AccountCodeEnum::ORDER_COST_DEFERRED)->value('id');
        if (!$id) {
            throw new DomainException('Akun ' . AccountCodeEnum::ORDER_COST_DEFERRED
                . ' (Biaya Pesanan Ditangguhkan) belum ada di COA. Jalankan migrasi.');
        }

        return (int) $id;
    }

    public function defaultCostAccountId(): ?int
    {
        return Account::where('code', AccountCodeEnum::ORDER_COST_COGS)->value('id');
    }

    /** Pesanan yang boleh menerima biaya: hanya yang confirmed (draft belum pasti, void sudah batal). */
    public function assertOrderAcceptsCost(?int $salesOrderId, int $accountId, string $label): SalesOrder
    {
        $so = $salesOrderId ? SalesOrder::find($salesOrderId) : null;
        if (!$so) {
            throw new DomainException("{$label}: pesanan tidak ditemukan.");
        }
        if ($so->status !== 'confirmed') {
            throw new DomainException("{$label}: pesanan {$so->order_number} berstatus {$so->status} — biaya hanya bisa ditautkan ke SO confirmed.");
        }

        // Tujuan akhirnya beban: aset/hutang di sini berarti salah pilih akun, dan 1204
        // akan tertinggal saldo yang tak pernah dipindah ke mana pun.
        $type = Account::whereKey($accountId)->value('type');
        if ($type !== 'expense') {
            throw new DomainException("{$label}: biaya pesanan wajib memakai akun beban (mis. "
                . AccountCodeEnum::ORDER_COST_COGS . ' HPP Jasa Pihak Ketiga).');
        }

        return $so;
    }

    /**
     * Dipanggil setelah pengeluaran di-post: baris yang pesanannya SUDAH difakturkan
     * langsung dipindah ke beban, bertanggal pengeluaran.
     */
    public function afterDisbursementPosted(CashDisbursement $cd): void
    {
        foreach ($cd->lines()->whereNotNull('sales_order_id')->get() as $line) {
            $invoice = $this->latestPostedInvoice((int) $line->sales_order_id);
            if ($invoice) {
                $this->recognize($line, $invoice, Carbon::parse($cd->date));
            }
        }
    }

    /** Dipanggil di dalam transaksi posting faktur. Tanggal pemindahan = tanggal faktur. */
    public function recognizeForInvoice(SalesInvoice $invoice): void
    {
        if (!$invoice->sales_order_id) {
            return;
        }

        foreach ($this->pendingLines((int) $invoice->sales_order_id) as $line) {
            $this->recognize($line, $invoice, Carbon::parse($invoice->invoice_date));
        }
    }

    /**
     * Dipanggil di dalam transaksi void faktur. Biaya yang diakui di faktur ini
     * dikembalikan ke 1204; kalau SO masih punya faktur posted lain, biayanya langsung
     * pindah ke sana supaya tidak menggantung tanpa faktur.
     */
    public function reverseForInvoice(SalesInvoice $invoice): void
    {
        $lines = CashDisbursementLine::where('cost_invoice_id', $invoice->id)->get();
        if ($lines->isEmpty()) {
            return;
        }

        foreach ($lines as $line) {
            $this->unrecognize($line);
        }

        $pengganti = $invoice->sales_order_id
            ? $this->latestPostedInvoice((int) $invoice->sales_order_id, $invoice->id)
            : null;
        if ($pengganti) {
            foreach ($lines as $line) {
                $this->recognize($line->fresh(), $pengganti, Carbon::today());
            }
        }
    }

    /** Dipanggil di dalam transaksi void pengeluaran. */
    public function reverseForDisbursement(CashDisbursement $cd): void
    {
        foreach ($cd->lines()->whereNotNull('cost_recognition_journal_id')->get() as $line) {
            $this->unrecognize($line);
        }
    }

    /**
     * Baris biaya pesanan yang masih hidup (draft/posted), untuk tampilan SO & penjaga void SO.
     * @return Collection<int, CashDisbursementLine>
     */
    public function linesForOrder(int $salesOrderId): Collection
    {
        return CashDisbursementLine::with(['disbursement', 'account', 'costInvoice'])
            ->where('sales_order_id', $salesOrderId)
            ->whereHas('disbursement', fn ($q) => $q->whereIn('status', ['draft', 'posted']))
            ->orderBy('id')
            ->get();
    }

    /* ------------------------------------------------------------------ */

    /** @return Collection<int, CashDisbursementLine> */
    private function pendingLines(int $salesOrderId): Collection
    {
        return CashDisbursementLine::where('sales_order_id', $salesOrderId)
            ->whereNull('cost_recognition_journal_id')
            ->whereHas('disbursement', fn ($q) => $q->where('status', 'posted'))
            ->get();
    }

    private function latestPostedInvoice(int $salesOrderId, ?int $exceptId = null): ?SalesInvoice
    {
        return SalesInvoice::where('sales_order_id', $salesOrderId)
            ->where('status', 'posted')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->first();
    }

    private function recognize(CashDisbursementLine $line, SalesInvoice $invoice, Carbon $date): void
    {
        if ($line->cost_recognition_journal_id) {
            return;
        }

        $period = AccountingPeriod::where('year', $date->year)->where('month', $date->month)->first();
        if (!$period) {
            throw new DomainException('Periode akuntansi ' . $date->format('m/Y') . ' tidak ditemukan.');
        }

        $line->loadMissing('disbursement', 'salesOrder');
        $cdNumber = $line->disbursement->number ?? ('#' . $line->cash_disbursement_id);
        $soNumber = $line->salesOrder->order_number ?? ('#' . $line->sales_order_id);

        // Nomor unik per percobaan: baris yang pernah diakui lalu dibatalkan (faktur di-void)
        // akan diakui ulang dan butuh nomor baru.
        $ke = Journal::where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $line->id)->count() + 1;

        $journal = Journal::create([
            'journal_number'   => 'SOC-' . $line->id . '-' . $ke,
            'date'             => $date->toDateString(),
            'period_id'        => $period->id,
            'reference_type'   => self::REFERENCE_TYPE,
            'reference_id'     => $line->id,
            'reference_number' => $invoice->invoice_number,
            'description'      => "Biaya pesanan {$soNumber} ({$cdNumber}) diakui di {$invoice->invoice_number}",
            'status'           => 'posted',
            'posted_at'        => now(),
        ]);

        $desc = trim(($line->description ?: 'Biaya pesanan') . ' — ' . $soNumber);
        foreach ([
            [$line->account_id, (float) $line->amount, 0],
            [$this->deferredAccountId(), 0, (float) $line->amount],
        ] as [$accountId, $debit, $credit]) {
            JournalLine::create([
                'journal_id'       => $journal->id,
                'account_id'       => $accountId,
                'debit'            => $debit,
                'credit'           => $credit,
                'description'      => $desc,
                'reference_type'   => self::REFERENCE_TYPE,
                'reference_id'     => $line->id,
                'reference_number' => $invoice->invoice_number,
            ]);
        }

        $line->forceFill([
            'cost_invoice_id'             => $invoice->id,
            'cost_recognition_journal_id' => $journal->id,
        ])->save();
    }

    private function unrecognize(CashDisbursementLine $line): void
    {
        if ($line->cost_recognition_journal_id) {
            Journal::whereKey($line->cost_recognition_journal_id)
                ->update(['status' => 'void', 'voided_at' => now()]);
        }

        $line->forceFill([
            'cost_invoice_id'             => null,
            'cost_recognition_journal_id' => null,
        ])->save();
    }
}
