<?php

namespace App\Modules\Tasks\Models;

use App\Core\Inventory\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaskAutomationRule extends Model
{
    public const TYPE_STOCK = 'stock';
    public const TYPE_ORDER = 'order';

    protected $fillable = [
        'type',
        'product_id',
        'assignee_user_id',
        'category_id',
        'priority',
        'title_template',
        'description_template',
        'is_active',
        'last_triggered_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function category()
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeStock($q)
    {
        return $q->where('type', self::TYPE_STOCK);
    }

    public function scopeOrderType($q)
    {
        return $q->where('type', self::TYPE_ORDER);
    }
}
