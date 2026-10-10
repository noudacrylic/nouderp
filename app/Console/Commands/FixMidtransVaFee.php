<?php

namespace App\Console\Commands;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Models\CustomerPayment;
use App\Models\MidtransTransaction;
use App\Modules\Payment\Services\MidtransFeeCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi fee Midtrans Virtual Account / Transfer Bank yang tercatat dgn tarif lama.
 *
 * Tarif VA dulu diatur Rp4.000, padahal Midtrans memotong Rp4.440 (4.000 + PPN 11%),
 * sehingga "Penerimaan Kas (Net)" di Saldo Midtrans kelebihan Rp440 per transaksi dan
 * tak pernah cocok dgn laporan Midtrans saat rekonsiliasi.
 *
 * Fee baru dihitung ulang dgn rumus yang sama dgn MidtransService::postCustomerPayment()
 * memakai tarif di Pengaturan → Midtrans saat ini. Jurnal pembayaran dikoreksi DI TEMPAT
 * (Dr Saldo Midtrans turun, Dr Beban Gateway naik sebesar selisih; Cr piutang/uang muka
 * tetap) supaya baris kas tetap satu dan cocok persis dgn baris koran Midtrans.
 *
 * Idempoten: transaksi yang fee-nya sudah sesuai dilewati. --dry-run untuk pratinjau.
 */
class FixMidtransVaFee extends Command
{
    protected $signature = 'midtrans:fix-va-fee {--dry-run : Hanya tampilkan yang akan dikoreksi, tanpa mengubah apa pun}';
    protected $description = 'Koreksi fee Midtrans VA/Transfer Bank lama ke tarif di Pengaturan → Midtrans';

    public function handle(MidtransFeeCalculator $fees): int
    {
        $dry = (bool) $this->option('dry-run');

        $trxs = MidtransTransaction::whereIn('channel', ['va', 'bank_transfer'])
            ->where('status', 'settlement')
            ->whereNotNull('customer_payment_id')
            ->orderBy('id')->get();

        $rows = [];
        $total = 0;
        foreach ($trxs as $trx) {
            $payment = CustomerPayment::find($trx->customer_payment_id);
            if (!$payment || $payment->status !== 'posted') continue;

            // Rumus = MidtransService::postCustomerPayment()
            $base        = (int) round($trx->base_amount);
            $customerFee = (int) round($trx->customer_admin_fee);
            $gross       = (int) round($trx->gross_amount);
            $mdr         = $fees->mdrFee($gross, $trx->channel);
            $newFee      = min(max(0, $mdr - $customerFee), $base);
            $delta       = $newFee - (int) round($payment->admin_fee);
            if ($delta <= 0) continue;

            $journal = Journal::where('reference_type', 'customer_payment')
                ->where('reference_id', $payment->id)
                ->where('status', 'posted')->first();
            $cashLine = $journal ? JournalLine::where('journal_id', $journal->id)
                ->where('account_id', $payment->cash_account_id)->where('debit', '>', 0)->first() : null;
            if (!$cashLine) {
                $this->warn("Lewati {$payment->payment_number} ({$trx->order_id}): jurnal / baris kas tidak ditemukan.");
                continue;
            }
            $feeAccountId = $payment->fee_account_id ?: DB::table('accounts')->where('code', '5290')->value('id');
            $feeLine = JournalLine::where('journal_id', $journal->id)
                ->where('account_id', $feeAccountId)->where('debit', '>', 0)->first();

            $rows[] = [$payment->payment_number, $payment->date?->format('Y-m-d') ?? (string) $payment->date, $trx->order_id,
                       number_format($payment->admin_fee, 0, ',', '.'), number_format($newFee, 0, ',', '.'),
                       number_format((float) $cashLine->debit - $delta, 0, ',', '.')];
            $total += $delta;
            if ($dry) continue;

            DB::transaction(function () use ($payment, $trx, $journal, $cashLine, $feeLine, $feeAccountId, $newFee, $delta, $mdr) {
                $cashLine->update(['debit' => (float) $cashLine->debit - $delta]);
                if ($feeLine) {
                    $feeLine->update(['debit' => (float) $feeLine->debit + $delta]);
                } else {
                    JournalLine::create([
                        'journal_id'       => $journal->id,
                        'account_id'       => $feeAccountId,
                        'debit'            => $delta,
                        'credit'           => 0,
                        'description'      => 'Biaya Admin/Bank - ' . $payment->payment_number,
                        'reference_type'   => 'customer_payment',
                        'reference_id'     => $payment->id,
                        'reference_number' => $payment->payment_number,
                    ]);
                }
                $payment->update(['admin_fee' => $newFee, 'fee_account_id' => $feeAccountId]);
                $trx->update(['midtrans_fee' => $mdr]);
            });
        }

        if (empty($rows)) {
            $this->info('Tidak ada pembayaran VA/Transfer Bank yang perlu dikoreksi.');
            return self::SUCCESS;
        }

        $this->table(['Pembayaran', 'Tanggal', 'Order Midtrans', 'Fee lama', 'Fee baru', 'Kas bersih baru'], $rows);
        $this->info(($dry ? '[DRY-RUN] Akan dikoreksi: ' : 'Dikoreksi: ') . count($rows)
            . ' pembayaran, total tambahan beban Rp' . number_format($total, 0, ',', '.') . '.');
        return self::SUCCESS;
    }
}
