<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAdjustment extends Model
{
    protected $fillable = [
        'number',
        'title',
        'type',
        'purpose',
        'direction',
        'income_account_id',
        'expense_account_id',
        'warehouse_id',
        'responsible',
        'date',
        'notes',
        'status'
    ];

    public function items()
    {
        return $this->hasMany(InventoryAdjustmentItem::class, 'adjustment_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(\App\Core\Inventory\Warehouse::class);
    }
}
