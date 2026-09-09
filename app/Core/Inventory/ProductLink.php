<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu tautan luar milik sebuah SKU (lihat migrasi product_links).
 */
class ProductLink extends Model
{
    protected $fillable = ['product_id', 'judul', 'url', 'urutan', 'created_by'];

    protected $casts = ['urutan' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
