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
        'env', 'shop_id', 'kelompok_id', 'campaign_id', 'ad_type', 'nama', 'status', 'bidding_method', 'placement',
        'modal_harian', 'modal_erp', 'roas_target', 'item_ids', 'mulai_at', 'selesai_at', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'      => 'integer',
        'campaign_id'  => 'integer',
        'modal_harian' => 'decimal:2',
        'modal_erp'    => 'decimal:2',
        'roas_target'  => 'decimal:2',
        'item_ids'     => 'array',
        'mulai_at'     => 'datetime',
        'selesai_at'   => 'datetime',
        'disinkron_at' => 'datetime',
    ];

    public function kelompok(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ShopeeIklanKelompok::class, 'kelompok_id');
    }

    /** Hanya iklan yang berjalan/dijeda yang boleh diatur jadwal (selesai/ditutup tak bisa dilanjutkan). */
    public function bisaDiatur(): bool
    {
        return in_array($this->status, ['ongoing', 'paused'], true);
    }

    /**
     * SKU varian listing tiap iklan (dari Peta SKU / shopee_items), urut alami, dipisah koma.
     * Iklan tanpa SKU tercatat tidak muncul di hasil — pakai nama iklannya.
     *
     * @param  iterable<ShopeeIklan>  $iklan
     * @return array<int, string> campaign_id => "TBKD-A4-T1-M3, TBKD-A5-T1-M3"
     */
    public static function petaSku(iterable $iklan): array
    {
        $iklan = collect($iklan);
        $items = $iklan->flatMap(fn ($i) => $i->item_ids ?? [])->unique()->values();
        if ($items->isEmpty()) {
            return [];
        }
        $skuPerItem = ShopeeItem::whereIn('item_id', $items)->where('env', $iklan->first()->env)
            ->get(['item_id', 'model_id', 'item_sku', 'model_sku'])
            ->groupBy('item_id')
            ->map(fn ($v) => $v->map(fn ($x) => $x->sku())->filter()->unique()->values()->all());

        $hasil = [];
        foreach ($iklan as $i) {
            $sku = collect($i->item_ids ?? [])->flatMap(fn ($id) => $skuPerItem[$id] ?? [])->unique()->all();
            if ($sku) {
                natsort($sku);
                $hasil[$i->campaign_id] = implode(', ', $sku);
            }
        }

        return $hasil;
    }

    public function labelStatus(): string
    {
        return self::LABEL_STATUS[$this->status] ?? (string) $this->status;
    }
}
