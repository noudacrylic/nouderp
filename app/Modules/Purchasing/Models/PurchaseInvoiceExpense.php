<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;

class PurchaseInvoiceExpense extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'account_id',
        'description',
        'amount',
        'mode',
    ];

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
