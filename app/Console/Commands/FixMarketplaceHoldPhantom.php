<?php

namespace App\Console\Commands;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Models\MarketplaceConfig;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Services\MarketplaceEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Perbaiki "saldo ditahan siluman" marketplace: selisih gross↔net biaya admin yang
 * nyangkut di akun Hold (& Uang Muka) untuk order yang DP-nya masih GROSS (grand_total
 * SO belum dipotong biaya marketplace) sementara invoice-nya sudah NET.
 *
 *  - Order BELUM settle  → dijalankan lewat MarketplaceEngineService::handle() versi baru
 *                          (Dr Wallet net + Dr Uang Muka selisih / Cr Hold sebesar DP).
 *  - Order SUDAH settle  → reklas Dr Uang Muka (2105) / Cr Hold sebesar selisih (biaya
 *                          admin sudah dibebankan saat invoice → murni balance-sheet).
 *
 * Idempoten: skip bila jurnal koreksi (marketplace_hold_correction) / settlement sudah ada.
 * Mendeteksi order otomatis (bukan ID hardcode) → aman dijalankan di server mana pun.
 * Pakai --dry-run untuk pratinjau tanpa memposting apa pun.
 */
class FixMarketplaceHoldPhantom extends Command
{
    protected $signature = 'marketplace:fix-hold-phantom {--dry-run : Hanya tampilkan yang akan dikoreksi, tanpa posting}';
    protected $description = 'Bersihkan selisih biaya admin marketplace yang nyangkut di akun Saldo Ditahan (& Uang Muka)';

    public function handle(JournalPostingService $poster, MarketplaceEngineService $engine): int
    {
        $dry = (bool) $this->option('dry-run');
        $advAccountId = (int) DB::table('accounts')->where('code', '2105')->value('id');
        if (!$advAccountId) {
            $this->error('Akun Uang Muka Customer (2105) tidak ditemukan.');
            return self::FAILURE;
        }

        $holdIds = MarketplaceConfig::where('is_active', true)
            ->whereNotNull('account_receivable_hold_id')
            ->pluck('account_receivable_hold_id')->unique()->all();

        // Order gap: ada DP ke akun hold, tapi grand_total SO (basis DP) != grand_total invoice (basis settlement).
        $rows = DB::table('customer_payments as cp')
            ->join('sales_orders as so', 'so.id', '=', 'cp.sales_order_id')
            ->join('accounts as cpa', 'cpa.id', '=', 'cp.cash_account_id')
            ->join('sales_invoices as si', function ($j) {
                $j->on('si.sales_order_id', '=', 'so.id')->where('si.status', '!=', 'void');
            })
            ->where('cp.status', 'posted')
            ->where('cpa.name', 'like', '%Ditahan%')
            ->whereRaw('ABS(so.grand_total - si.grand_total) > 0.01')
            ->select('si.id as invoice_id', 'so.order_number')
            ->distinct()
            ->get();

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Ditemukan {$rows->count()} order gap.");

        $settled = 0; $reclassed = 0; $totalGap = 0.0;

        foreach ($rows as $r) {
            $invoice = SalesInvoice::find($r->invoice_id);
            if (!$invoice) continue;

            $deposits = SalesAdvance::where('sales_order_id', $invoice->sales_order_id)
                ->where('status', 'posted')->whereIn('bank_account_id', $holdIds)->get();
            $deposited = round((float) $deposits->sum('amount'), 2);
            $payout    = round((float) $invoice->grand_total, 2);

            // Akun hold tempat DP berada (marketplace = selalu satu akun; ambil yang terbesar bila >1).
            $holdAcctId = (int) $deposits->groupBy('bank_account_id')
                ->map(fn($g) => (float) $g->sum('amount'))->sortDesc()->keys()->first();

            $hasSettlement = Journal::where('reference_type', 'sales_invoice_settlement')
                ->where('reference_id', $invoice->id)->where('status', '!=', 'void')->exists();

            if (!$hasSettlement) {
                // Belum settle → engine versi baru menutup semuanya (Dr wallet net + Dr 2105 selisih / Cr hold DP).
                $this->line("  • {$r->order_number}: belum settle → settle penuh (DP={$deposited}, net={$payout})");
                if (!$dry) $engine->handle($invoice);
                $settled++; $totalGap += round($deposited - $payout, 2);
                continue;
            }

            // Sudah settle: hitung SISA AKTUAL yang masih nyangkut di akun hold untuk order ini —
            // BUKAN dari selisih gross/net, karena settlement gaya-baru sudah menutup selisih itu.
            // residual = Σ debit DP ke hold − Σ kredit (settlement + koreksi) ke hold, utk order ini.
            $paymentIds = DB::table('customer_payments')
                ->where('sales_order_id', $invoice->sales_order_id)->where('status', 'posted')
                ->where('cash_account_id', $holdAcctId)->pluck('id')->all();

            $dpDebit = (float) DB::table('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
                ->where('j.status', '!=', 'void')->where('jl.account_id', $holdAcctId)
                ->where('j.reference_type', 'customer_payment')->whereIn('j.reference_id', $paymentIds ?: [0])
                ->sum('jl.debit');
            $released = (float) DB::table('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
                ->where('j.status', '!=', 'void')->where('jl.account_id', $holdAcctId)
                ->whereIn('j.reference_type', ['sales_invoice_settlement', 'marketplace_hold_correction'])
                ->where('j.reference_id', $invoice->id)
                ->selectRaw('SUM(jl.credit - jl.debit) v')->value('v');
            $residual = round($dpDebit - (float) $released, 2);

            if (abs($residual) < 0.005) { $this->line("  • {$r->order_number}: hold sudah bersih, skip"); continue; }

            $exists = Journal::where('reference_type', 'marketplace_hold_correction')
                ->where('reference_id', $invoice->id)->where('status', '!=', 'void')->exists();
            if ($exists) { $this->line("  • {$r->order_number}: sudah dikoreksi, skip"); continue; }

            $gap = $residual; // reklas persis sebesar sisa yang nyangkut
            $this->line("  • {$r->order_number}: sudah settle → reklas Dr 2105 / Cr Hold sebesar {$gap} (sisa nyangkut)");
            if (!$dry) {
                $lines = [
                    new JournalLineDTO(account_id: $advAccountId,
                        debit: $gap > 0 ? $gap : 0, credit: $gap < 0 ? -$gap : 0,
                        description: 'Koreksi selisih biaya admin marketplace (bersihkan Uang Muka)'),
                    new JournalLineDTO(account_id: $holdAcctId,
                        debit: $gap < 0 ? -$gap : 0, credit: $gap > 0 ? $gap : 0,
                        description: 'Koreksi saldo ditahan nyangkut (biaya admin)'),
                ];
                DB::transaction(function () use ($poster, $invoice, $lines) {
                    $poster->post(new JournalEntryDTO(
                        date: (string) $invoice->invoice_date,
                        reference_type: 'marketplace_hold_correction',
                        reference_id: $invoice->id,
                        description: 'Koreksi saldo ditahan siluman - ' . $invoice->invoice_number,
                        lines: $lines
                    ));
                    $invoice->update(['marketplace_processed' => true]);
                });
            }
            $reclassed++; $totalGap += $gap;
        }

        // ── Reklas DP nyasar akun hold (artefak: DP terposting sebelum config marketplace
        //    di-set, mis. order Tokopedia masuk hold Shopee). Hanya order BELUM settle yang
        //    dipindah (saldo hold-nya utuh) → aman. Pindahkan ke akun hold sesuai config
        //    customer sekarang, plus update SalesAdvance & customer_payment agar settlement
        //    nanti melepas dari akun yang benar. ──
        $reclassAccts = 0;
        $holdIdSet = array_map('intval', $holdIds);
        $orphans = DB::table('customer_payments as cp')
            ->join('marketplace_configs as mc', function ($j) {
                $j->on('mc.customer_id', '=', 'cp.customer_id')->where('mc.is_active', 1);
            })
            ->join('accounts as a', 'a.id', '=', 'cp.cash_account_id')
            ->where('cp.status', 'posted')
            ->whereColumn('cp.cash_account_id', '!=', 'mc.account_receivable_hold_id')
            ->whereIn('cp.cash_account_id', $holdIdSet ?: [0])
            ->whereNotNull('mc.account_receivable_hold_id')
            // hanya yang BELUM ada settlement (saldo hold masih utuh)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('sales_invoices as si')
                  ->join('journals as j', function ($jj) {
                      $jj->on('j.reference_id', '=', 'si.id')
                         ->where('j.reference_type', 'sales_invoice_settlement')->where('j.status', '!=', 'void');
                  })
                  ->whereColumn('si.sales_order_id', 'cp.sales_order_id');
            })
            ->select('cp.id as cp_id', 'cp.sales_order_id', 'cp.cash_account_id as from_acct',
                'mc.account_receivable_hold_id as to_acct', 'cp.amount', 'cp.date', 'cp.payment_number')
            ->get();

        foreach ($orphans as $o) {
            // saldo aktual DP di akun asal untuk pembayaran ini (harusnya = cp.amount)
            $bal = round((float) DB::table('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
                ->where('j.status', '!=', 'void')->where('jl.account_id', $o->from_acct)
                ->where('j.reference_type', 'customer_payment')->where('j.reference_id', $o->cp_id)
                ->selectRaw('SUM(jl.debit - jl.credit) v')->value('v'), 2);
            if ($bal <= 0.005) { continue; }

            $fromCode = DB::table('accounts')->where('id', $o->from_acct)->value('code');
            $toCode   = DB::table('accounts')->where('id', $o->to_acct)->value('code');
            $this->line("  ↪ {$o->payment_number}: reklas hold {$fromCode} → {$toCode} sebesar {$bal}");

            if ($dry) { $reclassAccts++; continue; }

            $exists = Journal::where('reference_type', 'marketplace_hold_reclass')
                ->where('reference_id', $o->cp_id)->where('status', '!=', 'void')->exists();
            if ($exists) { continue; }

            DB::transaction(function () use ($poster, $o, $bal) {
                $poster->post(new JournalEntryDTO(
                    date: (string) $o->date,
                    reference_type: 'marketplace_hold_reclass',
                    reference_id: (int) $o->cp_id,
                    description: 'Reklas saldo ditahan ke akun marketplace benar - ' . $o->payment_number,
                    lines: [
                        new JournalLineDTO(account_id: (int) $o->to_acct, debit: $bal, credit: 0,
                            description: 'Pindah saldo ditahan ke akun marketplace sesuai config'),
                        new JournalLineDTO(account_id: (int) $o->from_acct, debit: 0, credit: $bal,
                            description: 'Keluarkan dari akun hold yang salah'),
                    ]
                ));
                // Selaraskan sumber data agar settlement melepas dari akun yang benar.
                DB::table('sales_advances')->where('sales_order_id', $o->sales_order_id)
                    ->where('bank_account_id', $o->from_acct)->update(['bank_account_id' => $o->to_acct]);
                DB::table('customer_payments')->where('id', $o->cp_id)->update(['cash_account_id' => $o->to_acct]);
            });
            $reclassAccts++;
        }

        // Sinkronkan flag marketplace_processed (dulu tak fillable → tak pernah tersimpan).
        $flagged = 0;
        if (!$dry) {
            $flagged = SalesInvoice::where('marketplace_processed', 0)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))->from('journals as j')
                      ->whereColumn('j.reference_id', 'sales_invoices.id')
                      ->where('j.reference_type', 'sales_invoice_settlement')->where('j.status', '!=', 'void');
                })->update(['marketplace_processed' => 1]);
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Selesai. Settle penuh: {$settled}, reklas selisih admin: {$reclassed}, "
            . "reklas akun hold nyasar: {$reclassAccts}, total selisih dibersihkan: " . number_format($totalGap, 2)
            . ", flag disinkron: {$flagged}.");

        if ($dry) $this->comment('Ini pratinjau. Jalankan tanpa --dry-run untuk memposting koreksi.');

        return self::SUCCESS;
    }
}
