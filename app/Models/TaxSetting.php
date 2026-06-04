<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxSetting extends Model
{
    protected $fillable = [
        'code',
        'name',
        'default_percent',
        'account_id',
        'is_withholding',
        'is_active'
    ];
}
