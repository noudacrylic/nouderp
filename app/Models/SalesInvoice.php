<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Core\Inventory\Warehouse;
use App\Models\Concerns\PunyaTujuanKirim;


class SalesInvoice extends Model
{
    use PunyaTujuanKirim;

    /** Cabang ikut pesanannya bila faktur ini belum menyalinnya. */
    protected function cabangWarisan(): ?\App\Models\CustomerBranch
    {
        return $this->salesOrder?->customerBranch;
    }

    protected $fillable = [
        'invoice_number',
        'sales_order_id',
        'customer_id',
        'customer_branch_id',
        'warehouse_id',
        'delivery_method',
        'invoice_date',
        'status',
        'global_discount_type',
        'global_discount_value',
        'ppn_percent',
        'pph_percent',
        'shipping_cost',
        'shipping_gross',
        'shipping_discount_type',
        'shipping_discount_value',
        'shipping_courier_code',
        'shipping_service_code',
        'shipping_service_name',
        'shipping_provider',
        'package_length',
        'package_width',
        'package_height',
        'additional_fee',
        'marketplace_fee',
        'fee_at_settlement',
        'subtotal',
        'discount_total',
        'dpp',
        'ppn_amount',
        'pph_amount',
        'grand_total',
        'unique_code',
        'paid_amount',
        'advance_applied',
        'hpp_total',
        'notes',
        'marketplace_processed',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'status' => \App\Enums\InvoiceStatusEnum::class,
        'unique_code' => 'integer',
        'fee_at_settlement' => 'boolean',
    ];

    protected static function booted()
    {
        static::saving(function ($invoice) {
            $type = strtolower(trim((string) $invoice->global_discount_type));
            $value = (float) $invoice->global_discount_value;

            if ($value > 0) {
                if ($type === 'percent') {
                    $invoice->global_discount_amount = round($invoice->subtotal * ($value / 100), 2);
                } else {
                    // Treats 'fixed', 'nominal', or any other value correctly
                    $invoice->global_discount_amount = round($value, 2);
                }
            } else {
                $invoice->global_discount_amount = 0;
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    public function delivery()
    {
        return $this->hasOne(\App\Modules\Sales\Models\SalesDelivery::class, 'invoice_id');
    }

    public function returns()
    {
        return $this->hasMany(\App\Modules\Sales\Models\SalesReturn::class, 'invoice_id');
    }

    public function warrantyOrders()
    {
        return $this->hasMany(\App\Models\WarrantyOrder::class, 'invoice_id');
    }

    public function getRemainingAmountAttribute()
    {
        return round(
            (float) ($this->grand_total) 
            - (float) ($this->advance_applied ?? 0) 
            - (float) ($this->paid_amount ?? 0), 
            2
        );
    }

    public function billingItems()
    {
        return $this->hasMany(CustomerBillingItem::class, 'invoice_id');
    }

    /** Link pembayaran Midtrans yang masih aktif, atau null. Dipakai di Print Invoice (QR). */
    public function activePaymentLink(): ?MidtransTransaction
    {
        return MidtransTransaction::where('sales_invoice_id', $this->id)
            ->where('source', 'link')
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    /**
     * Status PEMBAYARAN faktur untuk ditampilkan — satu sumber untuk semua layar.
     *
     * Kenapa ada "BELUM CAIR" dan bukan cukup "Belum Lunas": faktur marketplace gaya baru
     * terbit saat pengiriman dengan piutang terbuka, padahal pembelinya SUDAH membayar —
     * uangnya hanya masih ditahan marketplace sampai pesanan tuntas. Menyebutnya "Belum
     * Lunas" membuat orang mengejar tagihan yang tidak perlu ditagih, dan mencampurnya ke
     * umur piutang membuat laporan itu kehilangan arti.
     *
     * "Cair" di sini berhenti di dompet marketplace. Penarikan dompet → bank urusan Kas Bank,
     * bukan status faktur.
     *
     * @return array{key:string,label:string,cls:string}
     */
    public function paymentState(): array
    {
        // Toleransi Rp1: sisa di bawah 1 rupiah = debu rekonsiliasi (mis. DP marketplace
        // dibukukan sebesar grand_total SO yang beda pecahan dengan grand_total faktur).
        if ($this->remaining_amount < 1) {
            return ['key' => 'lunas', 'label' => 'Lunas', 'cls' => 'bg-green-100 text-green-700'];
        }

        if ($this->fee_at_settlement) {
            return ['key' => 'belum_cair', 'label' => 'Belum Cair', 'cls' => 'bg-sky-100 text-sky-700'];
        }

        return ['key' => 'belum_lunas', 'label' => 'Belum Lunas', 'cls' => 'bg-orange-100 text-orange-700'];
    }

    /** Dana pembeli masih ditahan marketplace — bukan tagihan yang perlu dikejar. */
    public function isBelumCair(): bool
    {
        return $this->paymentState()['key'] === 'belum_cair';
    }

    public function getStatusLabelAttribute()
    {
        $status = $this->status;
        if ($status instanceof \App\Enums\InvoiceStatusEnum) {
            return strtoupper($status->value);
        }
        return strtoupper($status ?? 'DRAFT');
    }

    public function getStatusClassAttribute()
    {
        $val = $this->status instanceof \App\Enums\InvoiceStatusEnum ? $this->status->value : $this->status;

        return match($val) {
            'posted', 'paid', 'partial' => 'success',
            'void' => 'danger',
            default => 'secondary'
        };
    }

    public function canBeVoided(): bool
    {
        $val = $this->status instanceof \App\Enums\InvoiceStatusEnum ? $this->status->value : $this->status;
        if (!in_array($val, ['posted', 'paid'], true)) return false;

        if (\App\Models\CustomerPaymentAllocation::where('invoice_id', $this->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))->exists()) return false;

        if (\App\Modules\Sales\Models\SalesReturn::where('invoice_id', $this->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])->exists()) return false;

        if (\App\Models\WarrantyOrder::where('invoice_id', $this->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])->exists()) return false;

        if (\App\Models\CustomerBillingItem::where('invoice_id', $this->id)
            ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'draft']))->exists()) return false;

        return true;
    }
}
