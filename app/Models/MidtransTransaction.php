<?php

namespace App\Models;

use App\Core\Accounting\Account;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

class MidtransTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'sales_invoice_id',
        'sales_order_id',
        'customer_id',
        'source',
        'link_token',
        'channel',
        'bank',
        'gross_amount',
        'base_amount',
        'min_dp_amount',
        'customer_admin_fee',
        'midtrans_fee',
        'snap_token',
        'snap_redirect_url',
        'qris_payload',
        'va_number',
        'status',
        'fraud_status',
        'transaction_time',
        'expired_at',
        'settled_at',
        'customer_payment_id',
        'raw_response',
        'created_by',
    ];

    protected $casts = [
        'transaction_time' => 'datetime',
        'expired_at' => 'datetime',
        'settled_at' => 'datetime',
        'gross_amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'min_dp_amount' => 'decimal:2',
        'customer_admin_fee' => 'decimal:2',
        'midtrans_fee' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function customerPayment()
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['settlement', 'expire', 'cancel', 'deny', 'failure', 'refund'], true);
    }

    public function isExpired(): bool
    {
        return $this->status === 'expire' || ($this->status === 'pending' && $this->expired_at?->isPast());
    }

    public function isPaid(): bool
    {
        return $this->status === 'settlement' && $this->fraud_status !== 'deny';
    }

    public static function cashAccountCodeFor(string $channel): string
    {
        // Akun tunggal: semua channel masuk ke "Saldo Midtrans" supaya cocok dgn
        // saldo gabungan di dashboard Midtrans. Channel tetap terekam di kolom `channel`.
        return '1111';
    }
}
