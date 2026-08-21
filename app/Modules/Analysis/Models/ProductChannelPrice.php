<?php

namespace App\Modules\Analysis\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Harga sebuah produk di sebuah kanal: satuan, grosir (+ minimum beli), dan % afiliasi.
 *
 * Kanal `website` tidak memakai kolom `price` — harga satuannya harga jual asli di master
 * produk (product_prices), supaya web, ERP, dan harga dasar Jubelio tidak pernah berbeda.
 * Kolom grosir & afiliasi tetap dipakai kanal manapun, karena keduanya murni alat hitung.
 */
class ProductChannelPrice extends Model
{
    protected $fillable = [
        'product_id',
        'channel',
        'price',
        'wholesale_price',
        'wholesale_min_qty',
        'affiliate_percent',
        'pushed_price',
        'pushed_at',
    ];

    protected $casts = [
        'price'             => 'float',
        'wholesale_price'   => 'float',
        'wholesale_min_qty' => 'integer',
        'affiliate_percent' => 'float',
        'pushed_price'      => 'float',
        'pushed_at'         => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Harga di kanal ini sudah pernah benar-benar mendarat di tokonya? */
    public function isPushed(): bool
    {
        return $this->pushed_at !== null
            && $this->price !== null
            && round((float) $this->pushed_price) === round((float) $this->price);
    }
}
