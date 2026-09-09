<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris daftar tautan marketplace bebas (lihat migrasi
 * crm_marketplace_links). Namanya diketik, bukan diambil dari SKU.
 */
class CrmMarketplaceLink extends Model
{
    protected $fillable = ['nama', 'url', 'urutan', 'created_by'];

    protected $casts = ['urutan' => 'integer'];
}
