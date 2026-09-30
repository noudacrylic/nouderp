<?php

namespace App\Modules\Sales\Services;

use App\Enums\AccountCodeEnum;
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
     * @param string|null $tanggal Tanggal jurnal pencairan. NULL = tanggal faktur. Retur yang
     *   menuntaskan faktur memakai tanggal returnya (lihat SalesReturnService::post()).
     * @param float|null $pajak Pajak yang dipotong marketplace (PPh final UMKM). NULL = hitung dari
     *   `tax_percent` config atas sisa saldo ditahan (nilai kotor). Hanya faktur gaya baru.
     * @param bool $feeTermasukPajak TRUE bila $feeAktual adalah TOTAL potongan yang dilaporkan
     *   marketplace (sudah memuat pajak) — pajaknya lalu dipisah dari biaya admin, bukan ditambah.
     * @return Journal|null jurnal pencairan yang diposting; null bila tak ada yang dijurnal.
     */
    public function handle($invoice, ?float $feeAktual = null, ?string $tanggal = null, ?float $pajak = null, bool $feeTermasukPajak = false): ?Journal
    {
        $journal = DB::transaction(function () use ($invoice, $feeAktual, $tanggal, $pajak, $feeTermasukPajak) {

            // 🔒 1. DETEKSI MARKETPLACE
            $config = MarketplaceConfig::where('customer_id', $invoice->customer_id)
                ->where('is_active', true)
                ->first();

            if (!$config) {
                return null; // bukan marketplace
            }

            // 🔒 2. IDEMPOTENT (WAJIB)
            // Flag DB mencegah double-post. Namun flag bisa ter-reset (mis. invoice
            // di-save ulang), jadi cek juga keberadaan jurnal settlement: kalau sudah
            // ada, cukup rapikan flag & keluar — jangan posting dobel.
            if ($invoice->marketplace_processed) {
                return null;
            }
            $alreadySettled = Journal::where('reference_type', 'sales_invoice_settlement')
                ->where('reference_id', $invoice->id)
                ->where('status', '!=', 'void')
                ->exists();
            if ($alreadySettled) {
                $invoice->update(['marketplace_processed' => true]);
                return null;
            }

            // 🔒 2a. GUARD AKUN — wallet wajib ada (tujuan pelepasan saldo ditahan).
            if (!$config->account_wallet_id) {
                \Illuminate\Support\Facades\Log::warning('MarketplaceEngine: akun wallet belum diset — settlement dilewati', [
                    'invoice' => $invoice->id, 'customer' => $invoice->customer_id,
                ]);
                return null;
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
                return null;
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

            // Retur sudah melepas SELURUH titipan (semua barang kembali & dananya dikembalikan
            // ke pembeli): tak ada yang tersisa untuk dicairkan. Cukup tandai tuntas supaya
            // status "selesai" dari Jubelio nanti tidak mencoba mencairkannya lagi.
            if ($holdSisa <= 0.005) {
                $invoice->update(['marketplace_processed' => true]);
                return null;
            }

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
            //
            // Pajak (PPh final UMKM yang dipotong marketplace) dipisah ke akunnya sendiri supaya
            // setoran pajak bulanan terlihat, bukan tenggelam di Beban Admin. Rekonsiliasi tetap
            // membandingkan TOTAL potongan (admin + pajak), jadi salah taksir pembagiannya
            // tak membuat saldo dompet meleset — selisihnya dibukukan di sana.
            $feeBaru = 0.0;
            $pajakBaru = 0.0;
            if ($invoice->fee_at_settlement) {
                $feeBaru = round($feeAktual ?? (float) ($invoice->marketplace_fee ?? 0), 2);
                if ($feeBaru < 0) {
                    $feeBaru = 0.0;
                }
                $pajakBaru = max(0.0, round($pajak ?? $config->pajakDari($holdSisa), 2));
                if ($pajakBaru > 0 && !$config->account_tax_id) {
                    // Tanpa akun pajak, pajaknya tetap potongan — dilebur ke biaya admin.
                    $feeBaru   = $feeTermasukPajak ? $feeBaru : round($feeBaru + $pajakBaru, 2);
                    $pajakBaru = 0.0;
                } elseif ($feeTermasukPajak) {
                    $feeBaru = max(0.0, round($feeBaru - $pajakBaru, 2));
                }
                $payout = round($holdSisa - $feeBaru - $pajakBaru, 2);
            } else {
                $payout = round((float) $invoice->grand_total, 2);
            }

            $gap = round($holdSisa - $payout - $feeBaru - $pajakBaru, 2);
            $advanceAccountId = (int) DB::table('accounts')->where('code', '2105')->value('id');

            if ($feeBaru > 0 && !$config->account_fee_id) {
                Log::warning('MarketplaceEngine: akun fee belum diset - fee dilebur ke reklas uang muka', [
                    'invoice' => $invoice->id, 'fee' => $feeBaru,
                ]);
                $gap     = round($gap + $feeBaru, 2);
                $feeBaru = 0.0;
            }

            $lines = [];

            // PELUNASAN FAKTUR — hanya untuk faktur gaya baru, yang piutangnya sengaja
            // dibiarkan terbuka saat pengiriman (lihat InvoicePostingService::applyAdvance).
            // Di sinilah pesanan benar-benar tuntas: uang muka pembeli menutup tagihannya,
            // dan fakturnya baru berubah dari "Belum Cair" menjadi "Lunas".
            $arApply = $invoice->fee_at_settlement ? $this->uangMukaTerpakai($invoice) : 0.0;
            if ($arApply > 0) {
                $arAccountId = (int) DB::table('accounts')->where('code', AccountCodeEnum::AR_RECEIVABLE)->value('id');
                if (!$arAccountId || !$advanceAccountId) {
                    Log::warning('MarketplaceEngine: akun piutang/uang muka tak ditemukan — pelunasan faktur dilewati', [
                        'invoice' => $invoice->id,
                    ]);
                    $arApply = 0.0;
                } else {
                    $lines[] = new JournalLineDTO(
                        account_id: $advanceAccountId,
                        debit: $arApply, credit: 0,
                        description: 'Pelunasan faktur dari uang muka marketplace'
                    );
                    $lines[] = new JournalLineDTO(
                        account_id: $arAccountId,
                        debit: 0, credit: $arApply,
                        description: 'Piutang lunas - ' . $invoice->invoice_number
                    );
                }
            }

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
            if ($pajakBaru > 0) {
                $lines[] = new JournalLineDTO(
                    account_id: (int) $config->account_tax_id,
                    debit: $pajakBaru, credit: 0,
                    description: 'Pajak dipotong marketplace'
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
                date: $tanggal ?? $invoice->invoice_date,
                reference_type: 'sales_invoice_settlement', // Unik agar tak bentrok journal invoice utama
                reference_id: $invoice->id,
                description: 'Marketplace Settlement - ' . $invoice->invoice_number,
                lines: $lines
            );

            $journal = app(JournalPostingService::class)->post($dto);

            // 4. TANDAI SUDAH DIPROSES. Untuk faktur gaya baru, fee yang baru saja dibebankan
            //    dicatat di faktur sbg "sudah dibukukan" — dipakai rekonsiliasi sebagai
            //    `prebooked`. grand_total SENGAJA tidak diturunkan.
            $isi = ['marketplace_processed' => true];
            if ($invoice->fee_at_settlement) {
                // TOTAL potongan yang sudah dibukukan — dibaca rekonsiliasi sebagai `prebooked`.
                $isi['marketplace_fee'] = round($feeBaru + $pajakBaru, 2);
            }
            if ($arApply > 0) {
                $isi['advance_applied'] = round((float) $invoice->advance_applied + $arApply, 2);
            }
            $invoice->update($isi);

            return $journal;
        });

        $this->cocokkanUlangRekonsiliasi($invoice);

        return $journal;
    }

    /**
     * Baris rekonsiliasi yang MENUNGGU pesanan ini tuntas di ERP (lihat
     * MarketplaceSettlementService::menungguPenyelesaian) dicocokkan sekarang. Rekonsiliasi
     * mencocokkan lewat nomor pesanan, bukan tanggal — jadi retur yang diselesaikan belakangan
     * tetap ketemu barisnya, dan selisih potongannya dihitung terhadap angka yang sudah final.
     */
    public function cocokkanUlangRekonsiliasi($invoice): void
    {
        if (!$invoice->marketplace_processed || !$invoice->sales_order_id) {
            return;
        }

        try {
            $ref = \App\Modules\Sales\Models\SalesOrder::whereKey($invoice->sales_order_id)->value('customer_po_number');
            if ($ref) {
                app(\App\Modules\Finance\Services\MarketplaceSettlementService::class)
                    ->autoRematchForOrderRef((int) $invoice->customer_id, $ref);
            }
        } catch (\Throwable $e) {
            Log::warning('MarketplaceEngine: cocokkan ulang rekonsiliasi gagal', [
                'invoice' => $invoice->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uang muka pembeli yang masih bisa dipakai faktur ini — tanpa dibatasi sisa tagihan.
     * Dipakai retur jurnal tiga blok untuk mengisi & menjaga baris Uang Muka.
     */
    public function uangMukaTersedia($invoice): float
    {
        if (!$invoice->sales_order_id) {
            return 0.0;
        }

        $posted = (float) SalesAdvance::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->sum(DB::raw('amount + credit_used'));

        $used = (float) \App\Models\SalesInvoice::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->sum('advance_applied');

        return round(max(0, $posted - $used - $this->returDebitUangMuka($invoice)), 2);
    }

    /**
     * Berapa uang muka yang boleh dipakai menutup faktur ini.
     *
     * Rumusnya sengaja sama persis dengan InvoicePostingService::applyAdvance — dibatasi oleh
     * DP yang benar-benar diposting untuk SO-nya, dikurangi yang sudah terpakai faktur lain,
     * dan tidak melebihi sisa tagihan faktur ini. Pengiriman bertahap menghasilkan beberapa
     * faktur atas satu DP, jadi pembatas itu bukan formalitas.
     */
    private function uangMukaTerpakai($invoice): float
    {
        if (!$invoice->sales_order_id) {
            return 0.0;
        }

        $posted = (float) SalesAdvance::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->sum(DB::raw('amount + credit_used'));

        $used = (float) \App\Models\SalesInvoice::where('sales_order_id', $invoice->sales_order_id)
            ->where('status', 'posted')
            ->where('id', '!=', $invoice->id)
            ->sum('advance_applied');

        // Retur yang diposting lebih dulu sudah menghapus sebagian piutang (returned_amount)
        // dan mengembalikan sebagian titipan pembeli (Dr Uang Muka). Tanpa dikurangkan,
        // pelunasan di sini menutup piutang yang sudah nol dan memakai uang muka yang sudah
        // habis — keduanya jadi MINUS sebesar nilai returnya.
        $sisaTagihan = round((float) $invoice->grand_total
            - (float) ($invoice->paid_amount ?? 0)
            - (float) ($invoice->advance_applied ?? 0)
            - (float) ($invoice->returned_amount ?? 0), 2);

        $uangMukaRetur = $this->returDebitUangMuka($invoice);

        return round(max(0, min($sisaTagihan, $posted - $used - $uangMukaRetur)), 2);
    }

    /** Uang muka pembeli yang sudah dikembalikan lewat retur posted (Dr 2105 di jurnal retur). */
    private function returDebitUangMuka($invoice): float
    {
        $akun = (int) DB::table('accounts')->where('code', AccountCodeEnum::SALES_ADVANCE)->value('id');
        $returIds = $this->returPostedIds($invoice);
        if (!$akun || $returIds->isEmpty()) {
            return 0.0;
        }

        return max(0.0, round((float) DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', '!=', 'void')
            ->where('j.reference_type', 'sales_return')
            ->whereIn('j.reference_id', $returIds)
            ->where('jl.account_id', $akun)
            ->selectRaw('SUM(jl.debit) - SUM(jl.credit) v')->value('v'), 2));
    }

    private function returPostedIds($invoice): \Illuminate\Support\Collection
    {
        return \App\Modules\Sales\Models\SalesReturn::query()
            ->where('status', 'posted')
            ->where(function ($w) use ($invoice) {
                $w->where('invoice_id', $invoice->id);
                if ($invoice->sales_order_id) {
                    $w->orWhere('sales_order_id', $invoice->sales_order_id);
                }
            })
            ->pluck('id');
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

        $returIds = $this->returPostedIds($invoice);

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
