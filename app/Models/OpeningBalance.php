<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalance extends Model
{
    protected $fillable = ['number', 'date', 'status'];

    public function items()
    {
        return $this->hasMany(OpeningBalanceItem::class);
    }
}
