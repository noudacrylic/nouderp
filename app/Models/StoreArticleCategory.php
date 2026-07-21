<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreArticleCategory extends Model
{
    protected $fillable = ['slug', 'name', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function articles()
    {
        return $this->hasMany(StoreArticle::class);
    }
}
