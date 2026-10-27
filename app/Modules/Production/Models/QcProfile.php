<?php

namespace App\Modules\Production\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profil QC timbang satu SKU knockdown: berat acuan paket utuh + daftar komponen
 * per bungkus. Adanya profil AKTIF = hasil produksi SKU ini wajib lolos QC sebelum
 * difinalisasi (lihat QcService::assertBolehFinalisasi).
 */
class QcProfile extends Model
{
    protected $fillable = [
        'product_id', 'packaging_gram', 'target_gram', 'samples', 'tolerance_mode', 'tolerance_value',
        'batch_note', 'sampled_at', 'sampled_by', 'is_active',
    ];

    protected $casts = [
        'samples'         => 'array',
        'packaging_gram'  => 'float',
        'target_gram'     => 'float',
        'tolerance_value' => 'float',
        'sampled_at'      => 'datetime',
        'is_active'       => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(QcProfileComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(QcReferenceHistory::class)->latest('id');
    }

    public function sampler(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'sampled_by');
    }
}
