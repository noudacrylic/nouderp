<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreProduct extends Model
{
    protected $fillable = [
        'store_category_id',
        'slug',
        'name',
        'description',
        'short_description',
        'meta_title',
        'meta_description',
        'status',
        'is_featured',
        'sort_order',
        'variant_axes',
    ];

    protected $casts = [
        'is_featured'  => 'boolean',
        'sort_order'   => 'integer',
        'variant_axes' => 'array',
    ];

    /** True bila produk memakai variasi (punya sumbu). */
    public function hasVariants(): bool
    {
        return !empty($this->variant_axes);
    }

    public function category()
    {
        return $this->belongsTo(StoreCategory::class, 'store_category_id');
    }

    public function variants()
    {
        return $this->hasMany(StoreProductVariant::class)->orderBy('sort_order');
    }

    /** Galeri aktif (foto + video), foto lama yang menunggu GC tersembunyi via softdelete. */
    public function media()
    {
        return $this->hasMany(StoreProductMedia::class)->orderBy('sort_order');
    }

    public function images()
    {
        return $this->media()->where('kind', 'image');
    }

    public function videos()
    {
        return $this->media()->where('kind', 'video');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
