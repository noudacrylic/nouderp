<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;

class ProductionOrderCost extends Model
{
    protected $fillable = [
        'production_order_id', 'material_addition_id', 'description', 'amount', 'cash_account_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function materialAddition()
    {
        return $this->belongsTo(ProductionMaterialAddition::class, 'material_addition_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }
}
