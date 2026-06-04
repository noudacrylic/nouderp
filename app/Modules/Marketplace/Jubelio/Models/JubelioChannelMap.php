<?php

namespace App\Modules\Marketplace\Jubelio\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan nama toko/channel Jubelio → customer marketplace ERP.
 */
class JubelioChannelMap extends Model
{
    protected $fillable = [
        'store',
        'customer_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** Cari customer_id untuk sebuah nama toko (case-insensitive), null bila tak ada. */
    public static function resolveCustomerId(?string $store): ?int
    {
        if (empty($store)) {
            return null;
        }
        return static::where('is_active', true)
            ->whereRaw('LOWER(store) = ?', [mb_strtolower(trim($store))])
            ->value('customer_id');
    }
}
