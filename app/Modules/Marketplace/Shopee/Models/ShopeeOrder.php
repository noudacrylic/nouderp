<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Salinan pesanan Shopee hasil tarikan langsung (tahap 2, BACA SAJA) dan hasil bandingnya
 * dengan SO yang masuk lewat Jubelio.
 */
class ShopeeOrder extends Model
{
    /** Hasil banding — urutan = urutan tampil (yang perlu perhatian dulu). */
    public const HASIL = [
        'so_tidak_ada'     => 'SO tidak ada',
        'beda_status'      => 'Status beda',
        'beda_barang'      => 'Barang/qty beda',
        'sku_belum_dipeta' => 'SKU belum dipetakan',
        'beda_harga'       => 'Harga beda',
        'belum_dibayar'    => 'Belum dibayar',
        'cocok'            => 'Cocok',
    ];

    protected $fillable = [
        'env', 'shop_id', 'order_sn', 'status', 'dibuat_shopee_at', 'diperbarui_shopee_at', 'total', 'kurir',
        'sales_order_id', 'hasil_banding', 'catatan_banding', 'raw', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'              => 'integer',
        'dibuat_shopee_at'     => 'datetime',
        'diperbarui_shopee_at' => 'datetime',
        'total'                => 'decimal:2',
        'raw'                  => 'array',
        'disinkron_at'         => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopeeOrderItem::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function batal(): bool
    {
        return in_array($this->status, ['CANCELLED', 'IN_CANCEL'], true);
    }
}
