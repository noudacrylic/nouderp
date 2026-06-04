<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;

class PurchaseOrderExpense extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'account_id',
        'description',
        'amount',
        'mode',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
