<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;
use App\Models\SalesInvoice;
use App\Models\CustomerOverpayment;

class CashDisbursementLine extends Model
{
    protected $fillable = [
        'cash_disbursement_id',
        'account_id',
        'sales_invoice_id',
        'customer_overpayment_id',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function disbursement()
    {
        return $this->belongsTo(CashDisbursement::class, 'cash_disbursement_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function customerOverpayment()
    {
        return $this->belongsTo(CustomerOverpayment::class);
    }
}
