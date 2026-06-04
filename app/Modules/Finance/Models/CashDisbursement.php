<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;
use App\Models\Customer;

class CashDisbursement extends Model
{
    protected $fillable = [
        'number',
        'date',
        'type',
        'cash_account_id',
        'customer_id',
        'payee',
        'reference',
        'total',
        'status',
        'journal_id',
        'posted_at',
        'voided_at',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'total' => 'decimal:2',
    ];

    public function lines()
    {
        return $this->hasMany(CashDisbursementLine::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function canBeVoided(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /**
     * Map tipe ke nama route index sub-menu Pembayaran.
     * Dipakai untuk back-button & redirect setelah save/destroy
     * supaya kembali ke index sesuai sub-menu asalnya.
     */
    public static function listRouteForType(?string $type): string
    {
        return match ($type) {
            'freight'         => 'finance.cash-bank.disbursements.freight',
            'customer_refund' => 'finance.cash-bank.disbursements.refund',
            default           => 'finance.cash-bank.disbursements.general',
        };
    }

    public function listRouteName(): string
    {
        return static::listRouteForType($this->type);
    }
}
