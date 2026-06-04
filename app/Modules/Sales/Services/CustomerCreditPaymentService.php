<?php

namespace App\Modules\Sales\Services;

use App\Models\SalesInvoice;
use App\Models\CustomerPayment;
use App\Core\Accounting\Account;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Core\Journal\JournalPostingService;
use App\Core\Journal\JournalLine;
use App\Enums\AccountCodeEnum;
use Illuminate\Support\Facades\DB;
use Exception;

class CustomerCreditPaymentService
{
    public function pay($invoiceId, $amount)
    {
        $invoice = SalesInvoice::findOrFail($invoiceId);

        return DB::transaction(function () use ($invoice, $amount) {

            // 1. VALIDASI SALDO
            $balance = $this->getCustomerCreditBalance($invoice->customer_id);

            if ($amount > $balance) {
                throw new Exception("Saldo customer tidak cukup. Saldo saat ini: " . number_format($balance, 2));
            }

            // 2. POST JOURNAL
            $journal = new JournalEntryDTO(
                date: now()->format('Y-m-d'),
                reference_type: 'payment',
                reference_id: $invoice->id,
                description: "Payment via Customer Credit (Inv: {$invoice->invoice_number})",
                lines: [
                    new JournalLineDTO(
                        account_id: $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY),
                        debit: $amount,
                        credit: 0,
                        customer_id: $invoice->customer_id
                    ),
                    new JournalLineDTO(
                        account_id: $this->getAccountId(AccountCodeEnum::AR_RECEIVABLE),
                        debit: 0,
                        credit: $amount,
                        customer_id: $invoice->customer_id
                    ),
                ]
            );

            app(JournalPostingService::class)->post($journal);

            // 3. SIMPAN PAYMENT
            // Note: Depending on your CustomerPayment structure, you might need to adjust these fields.
            return CustomerPayment::create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'payment_number' => 'PAY-CR-' . uniqid(),
                'payment_date' => now(),
                'amount' => $amount,
                'payment_method' => 'customer_credit',
                'status' => 'posted',
            ]);
        });
    }

    public function refund($customerId, $amount)
    {
        return DB::transaction(function () use ($customerId, $amount) {

            // 1. VALIDASI SALDO
            $balance = $this->getCustomerCreditBalance($customerId);
            if ($amount > $balance) {
                throw new Exception("Saldo customer tidak cukup untuk refund. Saldo saat ini: " . number_format($balance, 2));
            }

            // 2. POST JOURNAL
            $journal = new JournalEntryDTO(
                date: now()->format('Y-m-d'),
                reference_type: 'refund',
                reference_id: $customerId,
                description: "Refund Customer Credit to Cash",
                lines: [
                    new JournalLineDTO(
                        account_id: $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY),
                        debit: $amount,
                        credit: 0,
                        customer_id: $customerId
                    ),
                    new JournalLineDTO(
                        account_id: $this->getAccountId(AccountCodeEnum::CASH),
                        debit: 0,
                        credit: $amount
                    ),
                ]
            );

            app(JournalPostingService::class)->post($journal);
        });
    }

    public function getCustomerCreditBalance($customerId)
    {
        return JournalLine::where('account_id', $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY))
            ->where('customer_id', $customerId)
            ->selectRaw('SUM(credit - debit) as balance')
            ->value('balance') ?? 0;
    }

    private function getAccountId($code)
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) {
            throw new Exception("Account with code {$code} not found.");
        }
        return $id;
    }
}
