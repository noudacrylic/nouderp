<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCost extends Model
{
    protected $fillable = [
        'product_id',
        'unit_name',
        'cost'
    ];
}
