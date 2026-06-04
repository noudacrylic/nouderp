<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class TaskCategory extends Model
{
    protected $fillable = [
        'name', 'color', 'sort_order', 'is_active', 'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tasks()
    {
        return $this->hasMany(Task::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
