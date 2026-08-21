<?php

namespace App\Modules\Analysis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Satu baris penyusun potongan sebuah kanal — mis. "Potongan marketplace 14%",
 * "Proses pesanan Rp1.250", "Biaya Jubelio per pesanan Rp250".
 *
 * `include_accounting` memisahkan yang benar-benar dipungut marketplace (dan karena itu
 * sebanding dengan potongan versi akuntansi di MarketplaceConfig) dari biaya lain yang
 * tetap harus ditutup harga jual tapi bukan potongan marketplace.
 */
class PriceChannelFeeComponent extends Model
{
    protected $fillable = [
        'channel',
        'label',
        'percent',
        'fixed',
        'include_accounting',
        'sort_order',
    ];

    protected $casts = [
        'percent'            => 'float',
        'fixed'              => 'float',
        'include_accounting' => 'boolean',
        'sort_order'         => 'integer',
    ];

    /** @return Collection<string,Collection<int,self>> dikunci kanal */
    public static function byChannel(): Collection
    {
        return static::orderBy('sort_order')->orderBy('id')->get()->groupBy('channel');
    }

    /** @param Collection<int,self>|array<int,self> $components */
    public static function totals($components, bool $accountingOnly = false): array
    {
        $rows = collect($components)->when($accountingOnly, fn ($c) => $c->filter->include_accounting);

        return [
            'percent' => (float) $rows->sum('percent'),
            'fixed'   => (float) $rows->sum('fixed'),
        ];
    }
}
