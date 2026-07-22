<?php

namespace App\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Intent pembayaran transfer bank per Sales Order (toko online).
 * Alur status: awaiting → (buyer tap "sudah transfer") claimed →
 * (nominal cocok) matched/confirmed. Jalur alternatif: expired / cancelled.
 */
class WebPayment extends Model
{
    public const STATUS_AWAITING  = 'awaiting';
    public const STATUS_CLAIMED   = 'claimed';
    public const STATUS_MATCHED   = 'matched';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /** Status yang masih menunggu uang masuk (dipakai matching & alokasi kode unik). */
    public const OPEN_STATUSES = [
        self::STATUS_AWAITING,
        self::STATUS_CLAIMED,
        self::STATUS_MATCHED,
    ];

    protected $fillable = [
        'sales_order_id',
        'customer_id',
        'public_token',
        'unique_code',
        'expected_amount',
        'status',
        'buyer_claimed_at',
        'matched_at',
        'confirmed_at',
        'confirmed_via',
        'confirmed_by',
        'matched_reference',
        'escalated_at',
        'telegram_chat_id',
        'telegram_message_id',
        'customer_payment_id',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'unique_code'      => 'integer',
        'expected_amount'  => 'decimal:2',
        'buyer_claimed_at' => 'datetime',
        'matched_at'       => 'datetime',
        'confirmed_at'     => 'datetime',
        'escalated_at'     => 'datetime',
        'expires_at'       => 'datetime',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerPayment()
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function statusLabel(): array
    {
        return match ($this->status) {
            self::STATUS_AWAITING  => ['label' => 'Menunggu Transfer', 'class' => 'bg-slate-100 text-slate-700'],
            self::STATUS_CLAIMED   => ['label' => 'Diklaim Bayar',      'class' => 'bg-amber-100 text-amber-700'],
            self::STATUS_MATCHED   => ['label' => 'Nominal Cocok',      'class' => 'bg-sky-100 text-sky-700'],
            self::STATUS_CONFIRMED => ['label' => 'Lunas',              'class' => 'bg-green-100 text-green-700'],
            self::STATUS_EXPIRED   => ['label' => 'Kedaluwarsa',        'class' => 'bg-rose-100 text-rose-700'],
            self::STATUS_CANCELLED => ['label' => 'Dibatalkan',         'class' => 'bg-rose-100 text-rose-700'],
            default                => ['label' => strtoupper($this->status), 'class' => 'bg-gray-100 text-gray-700'],
        };
    }
}
