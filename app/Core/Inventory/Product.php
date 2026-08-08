<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'sale_type',
        'base_unit',
        'preorder_stock',
        'lead_time_days',
        'made_to_order',
        'income_account_id', // keep legacy field if still used elsewhere
        'expense_account_id',
        'revenue_account_id',
        'base_price',
        'cost_price',
        'stock',
        'min_stock',
        'last_cost',
        'is_active',
        'is_sellable',
        'type',
        'unit',
        'inventory_account_id',
        'needs_printing',
        'weight_gram',
        'length_cm',
        'width_cm',
        'height_cm',
        'sync_to_jubelio',
        'jubelio_item_id',
        'jubelio_item_group_id',
        'jubelio_location_id',
        'jubelio_sync_pending',
        'jubelio_synced_qty',
        'jubelio_price_pending',
    ];

    protected $casts = [
        'needs_printing' => 'boolean',
        'is_active' => 'boolean',
        'is_sellable' => 'boolean',
        'made_to_order' => 'boolean',
        'sync_to_jubelio' => 'boolean',
        'jubelio_sync_pending' => 'boolean',
        'jubelio_price_pending' => 'boolean',
        'weight_gram' => 'integer',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
    ];
    
    protected $appends = ['display_price'];

    public function getTypeAttribute()
    {
        return $this->sale_type;
    }

    public function setTypeAttribute($value)
    {
        $this->attributes['sale_type'] = $value;
    }

    public function getUnitAttribute()
    {
        return $this->base_unit;
    }

    public function setUnitAttribute($value)
    {
        $this->attributes['base_unit'] = $value;
    }

    /**
     * Produk preorder yang satu unitnya bisa menggantikan unit lain — penanda "dibuat khusus
     * per pesanan" dilepas. Hanya untuk produk INI yang boleh:
     *   - stok yang sudah ada menutup pesanan baru (tak perlu order produksi lagi), dan
     *   - sisa produksi pesanan batal dipakai pesanan berikutnya.
     *
     * Produk yang dibuat mengikuti permintaan pembeli (CS1, CS2, …) tidak pernah begitu:
     * SKU-nya cuma wadah, dua unit di bawahnya bisa berbeda barang.
     */
    public function sharesStockAcrossOrders(): bool
    {
        return $this->sale_type === 'preorder' && ! $this->made_to_order;
    }

    public function bundleComponents()
    {
        return $this->hasMany(\App\Core\Inventory\BundleComponent::class, 'bundle_product_id');
    }

    public function bundleItems()
    {
        return $this->hasMany(\App\Core\Inventory\ProductBundle::class, 'bundle_product_id');
    }

    public function quotationItems()
    {
        return $this->hasMany(\App\Models\SalesQuotationItem::class);
    }

    public function costs()
    {
        return $this->hasMany(\App\Models\ProductCost::class);
    }

    public function stocks()
    {
        return $this->hasMany(\App\Core\Inventory\ProductStock::class);
    }

    public function units()
    {
        return $this->hasMany(\App\Core\Inventory\ProductUnit::class);
    }

    public function isPreorder()
    {
        return $this->sale_type === 'preorder';
    }

    public function prices()
    {
        return $this->hasMany(\App\Models\ProductPrice::class);
    }

    public function getDisplayPriceAttribute()
    {
        // SERVICE
        if ($this->type === 'service') {
            return $this->base_price ?? 0;
        }

        // NON STOCK
        if ($this->type === 'non_stock') {
            // Kita gunakan cost_price karena sebelumnya disimpan di cost_price
            return $this->cost_price ?? 0;
        }

        // READY / PREORDER / BUNDLE
        $price = $this->prices()->orderBy('id')->first();

        return $price ? $price->price : 0;
    }

    public function costLayers()
    {
        return $this->hasMany(\App\Models\InventoryCostLayer::class);
    }
}
