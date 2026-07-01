<?php

namespace App\Modules\Sales\Services;

use App\Models\MarketplaceConfig;
use App\Modules\Sales\Models\SalesAdvance;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use Illuminate\Support\Facades\DB;

class MarketplaceEngineService
{
    public function handle($invoice)
    {
        DB::transaction(function () use ($invoice) {

            // 🔒 1. DETEKSI MARKETPLACE
            $config = MarketplaceConfig::where('customer_id', $invoice->customer_id)
                ->where('is_active', true)
                ->first();

            if (!$config) {
                return; // bukan marketplace
            }

            // 🔒 2. IDEMPOTENT (WAJIB)
            // Flag DB mencegah double-post. Namun flag bisa ter-reset (mis. invoice
            // di-save ulang), jadi cek juga keberadaan jurnal settlement: kalau sudah
            // ada, cukup rapikan flag & keluar — jangan posting dobel.
            if ($invoice->marketplace_processed) {
                return;
            }
            $alreadySettled = Journal::where('reference_type', 'sales_invoice_settlement')
                ->where('reference_id', $invoice->id)
                ->where('status', '!=', 'void')
                ->exists();
            if ($alreadySettled) {
                $invoice->update(['marketplace_processed' => true]);
                return;
            }

            // 🔒 2a. GUARD AKUN — wallet wajib ada (tujuan pelepasan saldo ditahan).
            if (!$config->account_wallet_id) {
                \Illuminate\Support\Facades\Log::warning('MarketplaceEngine: akun wallet belum diset — settlement dilewati', [
                    'invoice' => $invoice->id, 'customer' => $invoice->customer_id,
                ]);
                return;
            }

            // 🔒 2b. AMBIL SALDO YANG BENAR-BENAR DITAHAN (DEPOSITED) dari DP.
            //        Settlement memindahkan saldo dari akun HOLD, yang hanya terisi bila
            //        DP marketplace diposting ke Hold (alur Jubelio). Kita baca langsung
            //        dari SalesAdvance posted → akun & nominal AKTUAL yang masuk hold,
            //        dibatasi ke akun-akun hold marketplace agar tak salah tarik DP kas biasa.
            //        Sumber dari DP aktual (bukan config hold) = self-healing bila akun hold
            //        di config berubah setelah DP terlanjur diposting ke akun lama.
            $holdAccountIds = MarketplaceConfig::where('is_active', true)
                ->whereNotNull('account_receivable_hold_id')
                ->pluck('account_receivable_hold_id')->unique()->all();

            $deposits = $invoice->sales_order_id
                ? SalesAdvance::where('sales_order_id', $invoice->sales_order_id)
                    ->where('status', 'posted')
                    ->whereIn('bank_account_id', $holdAccountIds)
                    ->get()
                : collect();

            $deposited = round((float) $deposits->sum('amount'), 2);
            if ($deposited <= 0) {
                \Illuminate\Support\Facades\Log::warning('MarketplaceEngine: tidak ada DP ke akun Hold untuk SO ini — settlement Hold→Wallet dilewati (AR ditagih biasa)', [
                    'invoice' => $invoice->id, 'sales_order_id' => $invoice->sales_order_id,
                ]);
                return;
            }

            // 🧾 SETTLEMENT: PELEPASAN SALDO DITAHAN → WALLET.
            // payout = grand_total invoice = NET yang benar-benar diterima marketplace di wallet.
            // Biaya admin marketplace sudah dibukukan sbg beban saat posting invoice
            // (revenue di-gross-up), jadi TIDAK dibebankan lagi di sini.
            //
            // PENTING (fix "saldo siluman"): akun hold di-KREDIT sebesar DEPOSITED (nominal DP
            // yang benar-benar masuk hold), BUKAN sebesar payout. Bila DP di-post saat
            // grand_total SO masih GROSS (potongan marketplace belum ketahuan) sementara invoice
            // sudah NET, selisih (deposited − payout = biaya admin) akan nyangkut selamanya di
            // akun hold. Selisih itu direklas ke Uang Muka Customer (2105) — akun yang sama yang
            // di-kredit DP secara gross & di-debit invoice secara net — sehingga hold & uang muka
            // sama-sama bersih tanpa dampak laba-rugi (biaya admin tetap dibebankan sekali).
            $payout = round((float) $invoice->grand_total, 2);
            $gap    = round($deposited - $payout, 2);
            $advanceAccountId = (int) DB::table('accounts')->where('code', '2105')->value('id');

            $lines = [];
            // Dr Wallet marketplace (net diterima)
            $lines[] = new JournalLineDTO(
                account_id: (int) $config->account_wallet_id,
                debit: $payout, credit: 0,
                description: 'Net masuk wallet marketplace'
            );
            // Dr/Cr Uang Muka Customer utk selisih gross↔net (agar hold & uang muka bersih)
            if (abs($gap) > 0.005 && $advanceAccountId) {
                $lines[] = new JournalLineDTO(
                    account_id: $advanceAccountId,
                    debit: $gap > 0 ? $gap : 0,
                    credit: $gap < 0 ? -$gap : 0,
                    description: 'Reklas selisih biaya admin marketplace (gross↔net)'
                );
            }
            // Cr akun Hold sebesar DEPOSITED — per akun hold aktual (umumnya satu).
            foreach ($deposits->groupBy('bank_account_id') as $acctId => $grp) {
                $lines[] = new JournalLineDTO(
                    account_id: (int) $acctId,
                    debit: 0, credit: round((float) $grp->sum('amount'), 2),
                    description: 'Pelepasan saldo ditahan marketplace'
                );
            }

            $dto = new JournalEntryDTO(
                date: $invoice->invoice_date,
                reference_type: 'sales_invoice_settlement', // Unik agar tak bentrok journal invoice utama
                reference_id: $invoice->id,
                description: 'Marketplace Settlement - ' . $invoice->invoice_number,
                lines: $lines
            );

            app(JournalPostingService::class)->post($dto);

            // 🔒 4. TANDAI SUDAH DIPROSES
            $invoice->update([
                'marketplace_processed' => true
            ]);
        });
    }
}
