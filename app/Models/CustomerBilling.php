<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerBilling extends Model
{
    protected $fillable = [
        'billing_number',
        'billing_type',
        'customer_id',
        'date',
        'due_date',
        'total_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'status' => \App\Enums\BillingStatusEnum::class,
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(CustomerBillingItem::class);
    }

    public function allocations()
    {
        return $this->hasMany(CustomerPaymentAllocation::class, 'billing_id');
    }

    public function getStatusLabelAttribute()
    {
        $status = $this->status;
        if ($status instanceof \App\Enums\BillingStatusEnum) {
            return strtoupper($status->value);
        }
        return strtoupper($status ?? 'DRAFT');
    }

    public function getStatusClassAttribute()
    {
        $val = $this->status instanceof \App\Enums\BillingStatusEnum ? $this->status->value : $this->status;

        return match($val) {
            'paid', 'partial', 'open' => 'success',
            'void' => 'danger',
            default => 'secondary'
        };
    }

    public function canBeVoided(): bool
    {
        $val = $this->status instanceof \App\Enums\BillingStatusEnum ? $this->status->value : $this->status;
        if (in_array($val, ['void', 'draft'], true)) return false;

        if (CustomerPaymentAllocation::where('billing_id', $this->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))->exists()) return false;

        return true;
    }
}
