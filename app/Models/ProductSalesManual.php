<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;

class ProductSalesManual extends Model
{
    protected $table = 'product_sales_manual';

    protected $fillable = ['product_id', 'year', 'month', 'qty', 'notes'];

    protected $casts = [
        'qty'   => 'decimal:4',
        'year'  => 'integer',
        'month' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getMonthLabelAttribute(): string
    {
        $months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        return ($months[$this->month - 1] ?? $this->month) . ' ' . $this->year;
    }
}
