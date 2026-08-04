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

    /**
     * Alasan tautan ini TIDAK boleh dipakai membayar karena dokumennya sudah mati
     * (di-void atau dihapus), atau null bila dokumennya masih sah.
     *
     * Dicek ulang di setiap permintaan — bukan sekadar mengandalkan status transaksi —
     * supaya tautan lama yang sudah terlanjur beredar (termasuk yang dibuat sebelum
     * penonaktifan otomatis ada) tetap mati begitu dokumennya dibatalkan.
     */
    public function documentBlockedReason(): ?string
    {
        if ($this->sales_invoice_id) {
            $invoice = $this->invoice;
            if (! $invoice) {
                return 'Faktur untuk tautan ini sudah dihapus.';
            }
            if (in_array(self::statusValue($invoice->status), ['void', 'cancelled', 'canceled'], true)) {
                return 'Faktur ' . $invoice->invoice_number . ' sudah dibatalkan.';
            }

            return null;
        }

        if ($this->sales_order_id) {
            $so = $this->salesOrder;
            if (! $so) {
                return 'Pesanan untuk tautan ini sudah dihapus.';
            }
            if (in_array(self::statusValue($so->status), ['void', 'cancelled', 'canceled'], true)) {
                return 'Pesanan ' . $so->order_number . ' sudah dibatalkan.';
            }

            return null;
        }

        // Tanpa rujukan dokumen sama sekali: FK-nya `nullOnDelete`, jadi ini justru jejak
        // dokumen yang sudah dihapus. Tidak ada yang bisa ditagih — tautan harus mati.
        return 'Dokumen untuk tautan ini sudah dihapus.';
    }

    /** Nilai status apa adanya, baik yang di-cast enum (invoice) maupun string biasa (SO). */
    protected static function statusValue($status): string
    {
        return strtolower((string) ($status instanceof \BackedEnum ? $status->value : $status));
    }

    public static function cashAccountCodeFor(string $channel): string
    {
        // Akun tunggal: semua channel masuk ke "Saldo Midtrans" supaya cocok dgn
        // saldo gabungan di dashboard Midtrans. Channel tetap terekam di kolom `channel`.
        return '1111';
    }
}
