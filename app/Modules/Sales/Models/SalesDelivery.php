<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class SalesDelivery extends Model
{
    protected $fillable = [
        'delivery_number',
        'sales_order_id',
        'invoice_id',       // ✅ Tambahkan ini
        'reference_type',
        'reference_id',
        'warehouse_id',
        'delivery_method',
        'shipping_provider',
        'shipping_courier_code',
        'shipping_service_code',
        'provider_order_id',
        'collection_method',
        'shipping_status',
        'shipping_cost',
        'shipping_raw',
        'delivery_date',
        'courier_name',
        'tracking_number',
        'status'
    ];

    protected $casts = [
        'shipping_raw' => 'array',
    ];

    public function isBooked(): bool
    {
        return !empty($this->provider_order_id) && $this->shipping_status === 'booked';
    }

    public function items()
    {
        return $this->hasMany(SalesDeliveryItem::class, 'sales_delivery_id');
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\SalesInvoice::class, 'invoice_id');
    }

    public function canBeVoided(): bool
    {
        if ($this->status !== 'posted') return false;

        if ($this->invoice_id) {
            $inv = $this->invoice ?: \App\Models\SalesInvoice::find($this->invoice_id);
            if ($inv) {
                $val = $inv->status instanceof \App\Enums\InvoiceStatusEnum ? $inv->status->value : $inv->status;
                if (!in_array($val, ['void', 'cancelled', 'draft'], true)) return false;
            }
        }

        if (($this->reference_type ?? null) === 'warranty_order' && $this->reference_id) {
            $war = \App\Models\WarrantyOrder::find($this->reference_id);
            if ($war && !in_array($war->status, ['void', 'cancelled', 'draft'], true)) return false;
        }

        return true;
    }
}
