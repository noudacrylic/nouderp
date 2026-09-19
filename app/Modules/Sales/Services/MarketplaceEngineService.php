<?php

namespace App\Modules\Sales\Services;

use App\Models\MarketplaceConfig;
use App\Modules\Sales\Models\SalesAdvance;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketplaceEngineService
{
    /**
     * @param float|null $feeAktual Biaya admin marketplace yang dibebankan SEKARANG. Hanya
     *   dipakai faktur gaya baru (`fee_at_settlement`), yang terbit saat pengiriman tanpa fee
     *   — fee-nya baru diketahui & dibukukan di sini, saat pesanan selesai. Faktur lama
     *   mengabaikannya: fee-nya sudah masuk jurnal faktur. NULL → pakai yang tersimpan di
     *   faktur (dipakai command perbaikan yang memanggil ulang engine).
     */
    public function handle($invoice, ?float $feeAktual = null)
    {
        DB::transaction(function () use ($invoice, $feeAktual) {

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

            // Akun hold tempat DP berada. Marketplace = selalu satu akun; bila ternyata lebih
            // dari satu, ambil yang terbesar & catat peringatan agar ketahuan.
            $holdAcctId = (int) $deposits->groupBy('bank_account_id')
                ->map(fn ($g) => (float) $g->sum('amount'))->sortDesc()->keys()->first();
            if ($deposits->pluck('bank_account_id')->unique()->count() > 1) {
                Log::warning('MarketplaceEngine: DP tersebar di lebih dari satu akun hold', [
                    'invoice' => $invoice->id, 'sales_order_id' => $invoice->sales_order_id,
                ]);
            }

            // Retur yang diposting LEBIH DULU sudah mengkredit (mengurangi) akun hold sendiri.
            // Tanpa dikurangkan di sini, akun hold dikredit dua kali dan jadi MINUS sebesar
            // nilai returnya.
            $returCredit = $this->returKreditKeHold($invoice, $holdAcctId);
            $holdSisa    = round($deposited - $returCredit, 2);

            // SETTLEMENT: PELEPASAN SALDO DITAHAN -> WALLET.
            //
            // Faktur GAYA BARU (fee_at_settlement): terbit saat pengiriman mengikuti SO persis,
            // jadi grand_total-nya KOTOR & fee belum pernah dibebankan. Di sinilah fee dicatat:
            //   Dr Wallet (sisa hold - fee) + Dr Beban Admin (fee) / Cr Hold (sisa hold)
            //
            // Faktur LAMA: fee sudah dibebankan di jurnal faktur (revenue di-gross-up) dan
            // grand_total-nya sudah bersih, jadi TIDAK dibebankan lagi di sini.
            //
            // Sisa yang tak terjelaskan (mis. DP kotor vs faktur lama yang bersih) direklas ke
            // Uang Muka Customer (2105) supaya hold & uang muka sama-sama bersih tanpa dampak
            // laba-rugi — biaya admin tetap dibebankan tepat sekali.
            $feeBaru = 0.0;
            if ($invoice->fee_at_settlement) {
                $feeBaru = round($feeAktual ?? (float) ($invoice->marketplace_fee ?? 0), 2);
                if ($feeBaru < 0) {
                    $feeBaru = 0.0;
                }
                $payout = round($holdSisa - $feeBaru, 2);
            } else {
                $payout = round((float) $invoice->grand_total, 2);
            }

            $gap = round($holdSisa - $payout - $feeBaru, 2);
            $advanceAccountId = (int) DB::table('accounts')->where('code', '2105')->value('id');

            if ($feeBaru > 0 && !$config->account_fee_id) {
                Log::warning('MarketplaceEngine: akun fee belum diset - fee dilebur ke reklas uang muka', [
                    'invoice' => $invoice->id, 'fee' => $feeBaru,
                ]);
                $gap     = round($gap + $feeBaru, 2);
                $feeBaru = 0.0;
            }

            $lines = [];
            // Dr Wallet marketplace (dana bersih yang benar-benar diterima)
            $lines[] = new JournalLineDTO(
                account_id: (int) $config->account_wallet_id,
                debit: $payout, credit: 0,
                description: 'Net masuk wallet marketplace'
            );
            if ($feeBaru > 0) {
                $lines[] = new JournalLineDTO(
                    account_id: (int) $config->account_fee_id,
                    debit: $feeBaru, credit: 0,
                    description: 'Biaya admin marketplace'
                );
            }
            // Dr/Cr Uang Muka Customer utk selisih gross↔net (agar hold & uang muka bersih)
            if (abs($gap) > 0.005 && $advanceAccountId) {
                $lines[] = new JournalLineDTO(
                    account_id: $advanceAccountId,
                    debit: $gap > 0 ? $gap : 0,
                    credit: $gap < 0 ? -$gap : 0,
                    description: 'Reklas selisih biaya admin marketplace (gross↔net)'
                );
            }
            // Cr akun Hold sebesar SISA yang masih tertahan (DP dikurangi retur yang sudah
            // mengkreditnya) — bukan sebesar DP penuh, supaya hold tidak jadi minus.
            $lines[] = new JournalLineDTO(
                account_id: $holdAcctId,
                debit: 0, credit: $holdSisa,
                description: 'Pelepasan saldo ditahan marketplace'
            );

            $dto = new JournalEntryDTO(
                date: $invoice->invoice_date,
                reference_type: 'sales_invoice_settlement', // Unik agar tak bentrok journal invoice utama
                reference_id: $invoice->id,
                description: 'Marketplace Settlement - ' . $invoice->invoice_number,
                lines: $lines
            );

            app(JournalPostingService::class)->post($dto);

            // 4. TANDAI SUDAH DIPROSES. Untuk faktur gaya baru, fee yang baru saja dibebankan
            //    dicatat di faktur sbg "sudah dibukukan" — dipakai rekonsiliasi sebagai
            //    `prebooked`. grand_total SENGAJA tidak diturunkan.
            $isi = ['marketplace_processed' => true];
            if ($invoice->fee_at_settlement) {
                $isi['marketplace_fee'] = $feeBaru;
            }
            $invoice->update($isi);
        });
    }

    /**
     * Nilai retur POSTED yang sudah MENGKREDIT akun hold ini untuk dokumen tsb.
     *
     * SalesReturnService menjurnal `Dr Uang Muka / Cr Saldo Ditahan` (retur atas SO) atau
     * `Dr Penjualan / Cr Saldo Ditahan` (retur atas faktur). Jadi sebagian saldo hold sudah
     * dilepas duluan oleh retur, dan settlement hanya boleh melepas sisanya.
     */
    private function returKreditKeHold($invoice, int $holdAcctId): float
    {
        if (!$holdAcctId) {
            return 0.0;
        }

        $returIds = \App\Modules\Sales\Models\SalesReturn::query()
            ->where('status', 'posted')
            ->where(function ($w) use ($invoice) {
                $w->where('invoice_id', $invoice->id);
                if ($invoice->sales_order_id) {
                    $w->orWhere('sales_order_id', $invoice->sales_order_id);
                }
            })
            ->pluck('id');

        if ($returIds->isEmpty()) {
            return 0.0;
        }

        return round((float) DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')
            ->where('j.reference_type', 'sales_return')
            ->whereIn('j.reference_id', $returIds)
            ->where('jl.account_id', $holdAcctId)
            ->selectRaw('SUM(jl.credit) - SUM(jl.debit) v')->value('v'), 2);
    }
}
