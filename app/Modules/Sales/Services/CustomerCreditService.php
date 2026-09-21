<?php

namespace App\Modules\Sales\Services;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Enums\AccountCodeEnum;
use App\Models\CustomerOverpayment;
use App\Modules\Sales\Models\CustomerCredit;
use App\Services\NumberGeneratorService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pemberian & pengurangan saldo kredit pelanggan secara MANUAL.
 *
 * KENAPA ADA. Saldo kredit selama ini hanya bisa lahir dari pelanggan yang kelebihan bayar
 * atau dari refund Kas Bank. Tidak ada jalan untuk kasus yang justru paling sering: barang
 * kembali tanpa uang keluar — mis. pembeli marketplace menukar ukuran atas penjualan lama
 * yang fakturnya bahkan tidak ada di ERP. Sebelumnya itu hanya bisa diakali dengan jurnal
 * tangan, dan saldonya tidak akan pernah terbaca oleh pembayaran faktur.
 *
 * DI MANA SALDONYA HIDUP. Bukan di tabel dokumen ini, melainkan di kolam yang sudah dipakai
 * seluruh ERP: `customer_overpayments` — baris bertanda (positif menambah, negatif memakai).
 * CustomerPaymentService sudah membaca & memotong kolam itu saat faktur dibayar, jadi kredit
 * yang diterbitkan di sini langsung bisa dipakai tanpa menyentuh alur pembayaran sama sekali.
 *
 * CATATAN akun: `journal_lines` TIDAK punya kolom customer_id (lihat JournalPostingService),
 * jadi saldo per pelanggan memang tidak bisa dibaca dari jurnal — kolam itulah buku besar
 * pembantunya. Jurnal tetap diposting agar neraca benar, tapi bukan sumber saldo per pelanggan.
 */
class CustomerCreditService
{
    /** Saldo kredit pelanggan saat ini. Satu-satunya pembaca yang sah. */
    public function balanceFor(?int $customerId): float
    {
        if (!$customerId) {
            return 0.0;
        }

        return round((float) CustomerOverpayment::where('customer_id', $customerId)->sum('amount'), 2);
    }

    /**
     * @param array{customer_id:int, credit_date:string, direction:string, amount:float,
     *              counter_account_id:int, reason:?string, created_by:?int} $data
     */
    public function create(array $data): CustomerCredit
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new DomainException('Nominal harus lebih besar dari 0.');
        }

        $direction = $data['direction'] ?? 'tambah';
        if (!array_key_exists($direction, CustomerCredit::DIRECTIONS)) {
            throw new DomainException('Jenis kredit tidak dikenal.');
        }

        $customerId = (int) $data['customer_id'];

        // Mengurangi saldo lebih besar dari yang ada = mengarang utang pelanggan. Tolak.
        if ($direction === 'kurang' && $this->balanceFor($customerId) + 0.005 < $amount) {
            throw new DomainException(
                'Saldo kredit pelanggan hanya ' . rupiah($this->balanceFor($customerId)) . ', tidak bisa dikurangi ' . rupiah($amount) . '.'
            );
        }

        $counterAccount = Account::findOrFail((int) $data['counter_account_id']);
        if ($counterAccount->children()->exists()) {
            throw new DomainException('Akun lawan tidak boleh akun induk.');
        }

        $overpayAccountId = Account::where('code', AccountCodeEnum::CUSTOMER_OVERPAY)->value('id');
        if (!$overpayAccountId) {
            throw new DomainException('Akun ' . AccountCodeEnum::CUSTOMER_OVERPAY . ' (Kelebihan Bayar Customer) belum ada di COA.');
        }

        return DB::transaction(function () use ($data, $amount, $direction, $customerId, $counterAccount, $overpayAccountId) {
            $credit = CustomerCredit::create([
                'credit_number'      => NumberGeneratorService::generate('KP'),
                'customer_id'        => $customerId,
                'credit_date'        => $data['credit_date'],
                'direction'          => $direction,
                'amount'             => $amount,
                'counter_account_id' => $counterAccount->id,
                'reason'             => $data['reason'] ?? null,
                'status'             => 'posted',
                'created_by'         => $data['created_by'] ?? null,
            ]);

            //  tambah : Dr akun lawan        / Cr 2106 Kelebihan Bayar
            //  kurang : Dr 2106 Kelebihan Bayar / Cr akun lawan
            $naik  = $direction === 'tambah';
            $lines = [
                new JournalLineDTO(
                    account_id: $naik ? $counterAccount->id : (int) $overpayAccountId,
                    debit: $amount,
                    credit: 0,
                    description: $naik ? 'Sumber kredit pelanggan' : 'Pengurangan kredit pelanggan',
                ),
                new JournalLineDTO(
                    account_id: $naik ? (int) $overpayAccountId : $counterAccount->id,
                    debit: 0,
                    credit: $amount,
                    description: $naik ? 'Kredit pelanggan diterbitkan' : 'Pembalikan sumber kredit',
                ),
            ];

            $journal = app(JournalPostingService::class)->post(new JournalEntryDTO(
                date: (string) $data['credit_date'],
                reference_type: 'customer_credit',
                reference_id: $credit->id,
                description: 'Kredit Pelanggan ' . $credit->credit_number,
                lines: $lines,
                reference_number: $credit->credit_number,
            ));

            // Kolam saldo: inilah yang dibaca & dipotong saat faktur dibayar.
            CustomerOverpayment::create([
                'customer_id' => $customerId,
                'amount'      => $credit->signedAmount(),
                'reference'   => $credit->credit_number,
                'note'        => trim('Kredit Pelanggan ' . $credit->credit_number . ' — ' . (string) ($data['reason'] ?? '')),
            ]);

            $credit->forceFill(['journal_id' => $journal->id])->save();

            return $credit->fresh();
        });
    }

    public function void(CustomerCredit $credit): CustomerCredit
    {
        if (!$credit->canBeVoided()) {
            throw new DomainException($credit->voidBlocker() ?? 'Dokumen tidak bisa di-void.');
        }

        return DB::transaction(function () use ($credit) {
            CustomerOverpayment::where('customer_id', $credit->customer_id)
                ->where('reference', $credit->credit_number)
                ->delete();

            if ($credit->journal_id) {
                Journal::where('id', $credit->journal_id)->update(['status' => 'void', 'voided_at' => now()]);
            }

            $credit->forceFill(['status' => 'void', 'voided_at' => now()])->save();

            return $credit->fresh();
        });
    }
}
