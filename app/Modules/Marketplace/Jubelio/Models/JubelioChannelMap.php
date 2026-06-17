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

    /**
     * Cari customer_id untuk sebuah toko Jubelio.
     * Utamakan store_id (kunci unik & stabil — store_name bisa bentrok antar channel,
     * mis. Shopee & Tokopedia sama-sama "Noud Acrylic Shop"); fallback ke nama toko.
     * Kolom `store` menyimpan store_id (sbg string) atau nama; cocokkan keduanya.
     */
    public static function resolveCustomerId(?string $store, ?int $storeId = null): ?int
    {
        $q = static::where('is_active', true);

        if ($storeId) {
            $id = (clone $q)->where('store', (string) $storeId)->value('customer_id');
            if ($id) {
                return $id;
            }
        }

        if (!empty($store)) {
            return $q->whereRaw('LOWER(store) = ?', [mb_strtolower(trim($store))])->value('customer_id');
        }

        return null;
    }
}
