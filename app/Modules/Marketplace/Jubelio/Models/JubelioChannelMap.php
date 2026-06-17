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
     * Urutan: store_id (paling spesifik) → channel → nama toko.
     *
     * Pencocokan channel (token `channel:<channel_id>`) sengaja diutamakan di atas
     * nama toko supaya beberapa toko dari satu channel — mis. TikTok & Tokopedia yang
     * kini satu sumber tapi prefix store_name berbeda — selalu ter-route ke satu customer,
     * dan tidak tertukar dgn channel lain yang kebetulan store_name-nya sama
     * (mis. Shopee & Tokopedia sama-sama "Noud Acrylic Shop").
     * Kolom `store` menyimpan store_id (sbg string), `channel:<id>`, atau nama toko.
     */
    public static function resolveCustomerId(?string $store, ?int $storeId = null, ?int $channelId = null): ?int
    {
        $q = static::where('is_active', true);

        if ($storeId) {
            $id = (clone $q)->where('store', (string) $storeId)->value('customer_id');
            if ($id) {
                return $id;
            }
        }

        if ($channelId) {
            $id = (clone $q)->where('store', 'channel:' . $channelId)->value('customer_id');
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
