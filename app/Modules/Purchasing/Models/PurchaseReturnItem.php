<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;

class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'purchase_invoice_item_id',
        'product_id',
        'qty',
        'unit_cost',
        'subtotal',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseInvoiceItem()
    {
        return $this->belongsTo(PurchaseInvoiceItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
