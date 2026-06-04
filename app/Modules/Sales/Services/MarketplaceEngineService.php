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
