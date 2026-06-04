<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Customer;
use App\Core\Inventory\Warehouse;

class SalesOrder extends Model
{
    protected $fillable = [
        'order_number',
        'customer_id',
        'quotation_id',
        'customer_po_number',
        'warehouse_id',
        'delivery_method',
        'pickup_code',
        'pickup_status',
        'picked_up_at',
        'pickup_date',
        'order_date',
        'notes',
        'seller_notes',

        'subtotal',
        'discount_total',

        'global_discount_type',
        'global_discount_value',
        'global_discount_amount',

        'ppn_percent',
        'pph_percent',
        'ppn_amount',
        'pph_amount',

        'shipping_cost',
        'shipping_gross',
        'shipping_discount_type',
        'shipping_discount_value',
        'shipping_courier_code',
        'shipping_service_code',
        'shipping_service_name',
        'package_length',
        'package_width',
        'package_height',
        'shipping_settled',
        'additional_fee',

        'grand_total',
        'paid_amount',
        'status',
    ];

    protected $casts = [
        'picked_up_at' => 'datetime',
        'pickup_date'  => 'date',
    ];

    public const DELIVERY_METHODS = [
        'kurir'      => 'Kurir',
        'instant'    => 'Instant (Same Day)',
        'ambil_toko' => 'Ambil di Toko',
    ];

    public function isPickup(): bool
    {
        return $this->delivery_method === 'ambil_toko';
    }

    public function deliveryMethodLabel(): string
    {
        return self::DELIVERY_METHODS[$this->delivery_method] ?? 'Kurir';
    }

    /** Link pembayaran Midtrans (DP/SO) yang masih aktif, atau null. Dipakai di Print SO (QR). */
    public function activePaymentLink(): ?\App\Models\MidtransTransaction
    {
        return \App\Models\MidtransTransaction::where('sales_order_id', $this->id)
            ->whereNull('sales_invoice_id')
            ->where('source', 'link')
            ->where('status', 'pending')
            ->where('expired_at', '>', now())
            ->latest()
            ->first();
    }

    public function items()
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function advances()
    {
        return $this->hasMany(SalesAdvance::class);
    }

    public function deliveries()
    {
        return $this->hasMany(SalesDelivery::class, 'sales_order_id');
    }

    public function returns()
    {
        return $this->hasMany(SalesReturn::class, 'sales_order_id');
    }

    /**
     * Relasi ke alokasi pembayaran (untuk uang muka via modul Payment)
     */
    public function payments()
    {
        return $this->hasMany(\App\Models\CustomerPaymentAllocation::class, 'sales_order_id');
    }

    public function invoices()
    {
        return $this->hasMany(\App\Models\SalesInvoice::class, 'sales_order_id');
    }

    public function billingItems()
    {
        return $this->hasMany(\App\Models\CustomerBillingItem::class, 'sales_order_id');
    }

    public function isFullyInvoiced()
    {
        return $this->getInvoiceStatus() === 'invoiced';
    }

    public function getInvoiceStatus()
    {
        $totalSoQty = $this->items()->sum('qty');
        if ($totalSoQty <= 0) return 'not_invoiced';

        // Draft invoice tetap dianggap "punya invoice" — ikut diperhitungkan ke partial/full
        $totalInvoicedQty = \App\Models\SalesInvoiceItem::whereHas('invoice', function ($q) {
            $q->where('sales_order_id', $this->id)
              ->whereIn('status', ['posted', 'draft']);
        })->sum('qty');

        if ($totalInvoicedQty >= $totalSoQty && $totalSoQty > 0) return 'invoiced';
        if ($totalInvoicedQty > 0 && $totalInvoicedQty < $totalSoQty) return 'partial';

        return 'not_invoiced';
    }

    public function getInvoiceStatusLabel()
    {
        $status = $this->getInvoiceStatus();
        return match($status) {
            'not_invoiced' => ['label' => 'NOT INVOICED', 'class' => 'bg-red-100 text-red-700'],
            'partial' => ['label' => 'PARTIAL', 'class' => 'bg-amber-100 text-amber-700'],
            'invoiced' => ['label' => 'FULL', 'class' => 'bg-green-100 text-green-700'],
            default => ['label' => 'UNKNOWN', 'class' => 'bg-gray-100 text-gray-700']
        };
    }

    /**
     * Status pengiriman partial (mirror getInvoiceStatus, tapi qty SJ dalam base unit).
     * not_delivered / partial / delivered.
     */
    public function getDeliveryStatus(): string
    {
        $soItems = $this->items()->with('product')->get();
        if ($soItems->isEmpty()) return 'not_delivered';

        $deliveries = $this->deliveries()
            ->where('status', '!=', 'void')
            ->with('items')
            ->get();

        $deliveredByItem = [];
        $totalDelivered = 0.0;
        foreach ($deliveries as $d) {
            foreach ($d->items as $it) {
                $key = $it->sales_order_item_id;
                $deliveredByItem[$key] = ($deliveredByItem[$key] ?? 0) + (float) $it->qty;
                $totalDelivered += (float) $it->qty;
            }
        }

        if ($totalDelivered <= 0) return 'not_delivered';

        $needsDelivery = false;
        $allFull = true;
        foreach ($soItems as $si) {
            $product = $si->product;
            if ($product && in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) {
                continue; // jasa/non-stock tidak dikirim
            }
            $needsDelivery = true;
            $expected = $this->deliveryExpectedBaseQty($si);
            $delivered = $deliveredByItem[$si->id] ?? 0;
            if ($delivered + 0.0001 < $expected) {
                $allFull = false;
            }
        }

        if (!$needsDelivery) return 'delivered';
        return $allFull ? 'delivered' : 'partial';
    }

    public function getDeliveryStatusLabel()
    {
        return match ($this->getDeliveryStatus()) {
            'not_delivered' => ['label' => 'BELUM KIRIM', 'class' => 'bg-red-100 text-red-700'],
            'partial'       => ['label' => 'PARTIAL', 'class' => 'bg-amber-100 text-amber-700'],
            'delivered'     => ['label' => 'TERKIRIM', 'class' => 'bg-green-100 text-green-700'],
            default         => ['label' => 'UNKNOWN', 'class' => 'bg-gray-100 text-gray-700'],
        };
    }

    /**
     * Qty base yang diharapkan terkirim untuk 1 baris SO (bundle = × total qty komponen).
     */
    private function deliveryExpectedBaseQty(SalesOrderItem $si): float
    {
        $base = (float) $si->qty * (float) ($si->conversion_to_base ?? 1);
        $product = $si->product;
        if (!$product) return $base;

        $isBundle = ($product->sale_type === 'bundle') || ($product->type === 'bundle');
        if (!$isBundle) return $base;

        $mult = (float) \App\Core\Inventory\BundleComponent::where('bundle_product_id', $product->id)->sum('qty');
        if ($mult <= 0) {
            $mult = (float) \App\Core\Inventory\ProductBundle::where('bundle_product_id', $product->id)->sum('qty_required');
        }
        return $mult > 0 ? $base * $mult : $base;
    }

    public function getTotalAdvancePaid()
    {
        // Uang Muka dikelola melalui modul Payment yang secara otomatis melakukan
        // sinkronisasi ke tabel sales_advances. Kita hanya perlu sum dari advances()
        // agar tidak terjadi perhitungan ganda.
        return $this->advances()
            ->where('status', 'posted')
            ->sum(\DB::raw('COALESCE(amount, 0) + COALESCE(credit_used, 0)'));
    }

    public function canBeVoided(): bool
    {
        if ($this->status !== 'confirmed') return false;

        if (\App\Models\SalesInvoice::where('sales_order_id', $this->id)
            ->whereNotIn('status', ['void', 'cancelled'])->exists()) return false;

        if (SalesDelivery::where('sales_order_id', $this->id)
            ->whereNotIn('status', ['void', 'cancelled'])->exists()) return false;

        if (\App\Models\CustomerPaymentAllocation::where('sales_order_id', $this->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))->exists()) return false;

        if (SalesReturn::where('sales_order_id', $this->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])->exists()) return false;

        return true;
    }
}
