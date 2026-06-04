<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySetting extends Model
{
    protected $fillable = [
        'stock_loss_account_id',
        'stock_gain_account_id'
    ];
}
