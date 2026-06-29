<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreProductMedia extends Model
{
    use SoftDeletes;

    protected $table = 'store_product_media';

    protected $fillable = [
        'store_product_id',
        'kind',
        'source',
        'url',
        'r2_key',
        'alt_text',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Perubahan media menyentuh updated_at induk → sync cache katalog jalan. */
    protected $touches = ['storeProduct'];

    public function storeProduct()
    {
        return $this->belongsTo(StoreProduct::class);
    }
}
