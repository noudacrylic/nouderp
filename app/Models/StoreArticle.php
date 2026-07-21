<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreArticle extends Model
{
    protected $fillable = [
        'store_article_category_id',
        'slug',
        'title',
        'excerpt',
        'content',
        'cover_url',
        'cover_key',
        'author',
        'status',
        'published_at',
        'is_featured',
        'sort_order',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_featured'  => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(StoreArticleCategory::class, 'store_article_category_id');
    }

    /**
     * Terbit = status 'published' DAN jadwal terbit sudah lewat (atau kosong).
     * Ini sekaligus jadi penjadwalan: set published_at ke masa depan = tampil otomatis nanti.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function isLive(): bool
    {
        return $this->status === 'published'
            && (is_null($this->published_at) || $this->published_at->lte(now()));
    }
}
