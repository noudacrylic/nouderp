<?php

namespace App\Modules\Sales\Services;

use App\Models\MarketplaceConfig;
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
            // Menggunakan flag di database untuk mencegah double-post dari engine
            if ($invoice->marketplace_processed) {
                return;
            }

            // 🔒 2a. GUARD AKUN — butuh hold/fee/wallet lengkap, jika tidak jurnal
            //        akan ber-account_id null (korup). Lewati + log.
            if (!$config->account_receivable_hold_id || !$config->account_fee_id || !$config->account_wallet_id) {
                \Illuminate\Support\Facades\Log::warning('MarketplaceEngine: akun hold/fee/wallet belum lengkap — settlement dilewati', [
                    'invoice' => $invoice->id, 'customer' => $invoice->customer_id,
                ]);
                return;
            }

            // 🔒 2b. GUARD ANTI-KORUP — settlement memindahkan saldo dari akun HOLD, yang
            //        hanya terisi bila DP marketplace diposting ke Hold (alur Jubelio).
            //        Tanpa DP-ke-Hold, mengkredit Hold di sini tak punya debit penyeimbang
            //        (AR 1120 menggantung). Maka lewati; AR ditagih/diselesaikan normal.
            $hasHoldDeposit = $invoice->sales_order_id
                && \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $invoice->sales_order_id)
                    ->where('status', 'posted')
                    ->where('bank_account_id', $config->account_receivable_hold_id)
                    ->exists();
            if (!$hasHoldDeposit) {
                \Illuminate\Support\Facades\Log::warning('MarketplaceEngine: tidak ada DP ke akun Hold untuk SO ini — settlement Hold→Wallet dilewati (AR ditagih biasa)', [
                    'invoice' => $invoice->id, 'sales_order_id' => $invoice->sales_order_id,
                ]);
                return;
            }

            // 🔢 3. HITUNG FEE (Rumus sesuai blueprint)
            $percentFee = (float) $invoice->grand_total * ($config->admin_fee_percent / 100);
            $fixedFee   = (float) $config->admin_fee_fixed;

            $fee = round($percentFee + $fixedFee, 2);

            // 🎯 ambil akun dari config (DINAMIS - TIDAK HARDCODE)
            $holdAccount   = $config->account_receivable_hold_id;
            $feeAccount    = $config->account_fee_id;
            $walletAccount = $config->account_wallet_id;

            // =========================================================================
            // NB: Menggunakan reference_type yang unik agar tidak menabrak journal utama 
            // di JournalPostingService yang memblokir reference_id/type ganda.
            // =========================================================================

            // 🧾 JURNAL 1: FEE MARKETPLACE
            $dto1 = new JournalEntryDTO(
                date: $invoice->invoice_date,
                reference_type: 'sales_invoice_fee', // Unik agar tidak bentrok dengan journal invoice
                reference_id: $invoice->id,
                description: 'Marketplace Fee - Invoice ' . $invoice->invoice_number,
                lines: [
                    new JournalLineDTO(
                        account_id: $feeAccount,
                        debit: $fee,
                        credit: 0
                    ),
                    new JournalLineDTO(
                        account_id: $holdAccount,
                        debit: 0,
                        credit: $fee
                    ),
                ]
            );

            app(JournalPostingService::class)->post($dto1);

            // 🧾 JURNAL 2: PINDAH KE WALLET (SETTLEMENT)
            $net = (float) $invoice->grand_total - $fee;

            $dto2 = new JournalEntryDTO(
                date: $invoice->invoice_date,
                reference_type: 'sales_invoice_settlement', // Unik
                reference_id: $invoice->id,
                description: 'Marketplace Settlement - ' . $invoice->invoice_number,
                lines: [
                    new JournalLineDTO(
                        account_id: $walletAccount,
                        debit: $net,
                        credit: 0
                    ),
                    new JournalLineDTO(
                        account_id: $holdAccount,
                        debit: 0,
                        credit: $net
                    ),
                ]
            );

            app(JournalPostingService::class)->post($dto2);

            // 🔒 4. TANDAI SUDAH DIPROSES
            $invoice->update([
                'marketplace_processed' => true
            ]);
        });
    }
}
