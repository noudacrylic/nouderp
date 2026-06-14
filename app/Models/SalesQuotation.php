<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuotation extends Model
{
    protected $fillable = [
        'quotation_number',
        'perihal',
        'lampiran',
        'customer_id',
        'shipping_address',
        'notes',
        'opening_text',
        'payment_terms',
        'show_bank_account',
        'warehouse_id',
        'delivery_method',
        'quotation_date',
        'valid_until',
        'subtotal_product',
        'subtotal',
        'global_discount_type',
        'global_discount_value',
        'discount_global',
        'ppn_percent',
        'pph_percent',
        'shipping_charge',
        'shipping_gross',
        'shipping_discount_type',
        'shipping_discount_value',
        'shipping_courier_code',
        'shipping_service_code',
        'shipping_service_name',
        'package_length',
        'package_width',
        'package_height',
        'pickup_date',
        'service_charge',
        'other_expense',
        'grand_total',
        'status',
        'sales_order_id',
        'created_by',
    ];

    protected $casts = [
        'show_bank_account' => 'boolean',
        'pickup_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(SalesQuotationItem::class, 'quotation_id');
    }

    public function attachments()
    {
        return $this->hasMany(SalesQuotationAttachment::class, 'quotation_id')->orderBy('sort_order')->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(\App\Core\Inventory\Warehouse::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder()
    {
        return $this->hasOne(\App\Modules\Sales\Models\SalesOrder::class, 'quotation_id');
    }
}
