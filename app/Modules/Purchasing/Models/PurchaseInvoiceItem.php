<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;
use App\Modules\FixedAsset\Models\AssetCategory;
use App\Modules\FixedAsset\Models\FixedAsset;

class PurchaseInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'purchase_order_item_id',
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
        'landed_cost_share',
        'final_unit_cost',
        'qty_returned',
    ];

    protected $casts = [
        'is_asset' => 'boolean',
    ];

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function assetCategory()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function generatedAssets()
    {
        return $this->hasMany(FixedAsset::class, 'source_invoice_item_id');
    }
}
