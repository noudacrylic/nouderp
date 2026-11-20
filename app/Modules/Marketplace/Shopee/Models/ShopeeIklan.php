<?php

namespace App\Modules\Marketplace\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu iklan produk Shopee (campaign) — salinan dari Ads API, baca saja. */
class ShopeeIklan extends Model
{
    protected $table = 'shopee_iklan';

    /** Status yang masih bisa tayang lagi (yang lain sudah selesai/ditutup). */
    public const STATUS_HIDUP = ['ongoing', 'paused', 'scheduled'];

    public const LABEL_STATUS = [
        'ongoing'   => 'Berjalan',
        'paused'    => 'Dijeda',
        'scheduled' => 'Terjadwal',
        'ended'     => 'Selesai',
        'closed'    => 'Ditutup',
    ];

    protected $fillable = [
        'env', 'shop_id', 'campaign_id', 'ad_type', 'nama', 'status', 'bidding_method', 'placement',
        'modal_harian', 'roas_target', 'item_ids', 'mulai_at', 'selesai_at', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'      => 'integer',
        'campaign_id'  => 'integer',
        'modal_harian' => 'decimal:2',
        'roas_target'  => 'decimal:2',
        'item_ids'     => 'array',
        'mulai_at'     => 'datetime',
        'selesai_at'   => 'datetime',
        'disinkron_at' => 'datetime',
    ];

    public function labelStatus(): string
    {
        return self::LABEL_STATUS[$this->status] ?? (string) $this->status;
    }
}
