<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreightSetting extends Model
{
    protected $fillable = [
        'gain_account_id',
        'loss_account_id',
        'biteship_saldo_account_id',
        'biteship_fee_account_id',
    ];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }
}
