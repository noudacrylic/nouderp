<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Sales\Models\SalesDelivery;

class WarrantyOrder extends Model
{
    protected $fillable = [
        'warranty_number', 'customer_id', 'invoice_id', 'sales_order_id', 'warehouse_id',
        'warranty_date', 'type', 'repair_cost', 'status', 'issue_description', 'notes',
        'service_fee', 'shipping_cost', 'shipping_paid_by', 'fee_cash_account_id',
    ];

    protected $casts = [
        'warranty_date' => 'date',
        'repair_cost'   => 'decimal:2',
        'service_fee'   => 'decimal:2',
        'shipping_cost' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(\App\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(\App\Core\Inventory\Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(WarrantyOrderItem::class);
    }

    public function delivery()
    {
        return $this->hasOne(SalesDelivery::class, 'reference_id')
            ->where('reference_type', 'warranty_order');
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'repair' => 'Perbaikan',
            default  => $this->type,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft'    => 'Draft',
            'received' => 'Diterima',
            'posted'   => 'Diposting',
            'repaired' => 'Selesai Diperbaiki',
            'shipped'  => 'Dikirim',
            default    => ucfirst($this->status),
        };
    }

    public function canBeVoided(): bool
    {
        if (in_array($this->status, ['draft', 'void', 'cancelled', 'shipped'], true)) return false;

        $delivery = $this->delivery ?: SalesDelivery::where('reference_type', 'warranty_order')
            ->where('reference_id', $this->id)->first();
        if ($delivery && $delivery->status === 'posted') return false;

        return true;
    }
}
