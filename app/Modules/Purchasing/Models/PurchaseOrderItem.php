<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;
use App\Modules\FixedAsset\Models\AssetCategory;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'is_asset',
        'asset_category_id',
        'asset_name',
        'description',
        'qty',
        'unit',
        'price',
        'discount',
        'discount_type',
        'discount_value',
        'subtotal',
        'qty_invoiced',
    ];

    protected $casts = [
        'is_asset' => 'boolean',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function assetCategory()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function getQtyRemainingAttribute()
    {
        return (float) $this->qty - (float) $this->qty_invoiced;
    }
}
