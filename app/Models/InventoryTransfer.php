<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransfer extends Model
{
    protected $table = 'inventory_transfers';

    protected $fillable = [
        'number',
        'date',
        'from_warehouse_id',
        'to_warehouse_id',
        'status',
        'created_by',
    ];

    public function items()
    {
        return $this->hasMany(InventoryTransferItem::class, 'transfer_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(\App\Core\Inventory\Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(\App\Core\Inventory\Warehouse::class, 'to_warehouse_id');
    }
}
