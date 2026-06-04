<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    protected $fillable = [
        'name',
        'type',            // item | shipping | cart_total
        'discount_type',   // nominal | percent
        'discount_value',
        'applies_to_all',
        'is_voucher',
        'voucher_code',
        'is_active',
        'priority',
        'starts_at',
        'ends_at',
        'notes',
    ];

    protected $casts = [
        'applies_to_all' => 'boolean',
        'is_voucher'     => 'boolean',
        'is_active'      => 'boolean',
        'priority'       => 'integer',
        'discount_value' => 'decimal:2',
        'starts_at'      => 'date',
        'ends_at'        => 'date',
    ];

    public function tiers(): HasMany
    {
        return $this->hasMany(PromotionTier::class)->orderBy('min_spend');
    }

    public function products(): HasMany
    {
        return $this->hasMany(PromotionProduct::class);
    }

    /** Daftar product_id sasaran (untuk type item, applies_to_all=false). */
    public function productIds(): array
    {
        return $this->products->pluck('product_id')->all();
    }

    /** Aktif + dalam window tanggal hari ini. */
    public function scopeActive(Builder $q): Builder
    {
        $today = now()->toDateString();
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today));
    }

    /** Promo yang berlaku otomatis (bukan voucher). */
    public function scopeAutoApply(Builder $q): Builder
    {
        return $q->where('is_voucher', false);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }
}
