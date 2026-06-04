<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAccountSetting extends Model
{
    protected $fillable = [
        'inventory_asset_account_id',
        'inventory_gain_account_id',
        'inventory_loss_account_id',
        'repair_account_id',
        'opening_balance_account_id',
    ];
}
