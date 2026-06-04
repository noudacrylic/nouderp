<?php

namespace App\Modules\FixedAsset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Core\Accounting\Account;

class AssetCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'code_prefix',
        'name',
        'description',
        'default_useful_life_months',
        'default_salvage_value_percent',
        'is_depreciable_default',
        'fixed_asset_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_depreciable_default' => 'boolean',
        'is_active' => 'boolean',
        'default_useful_life_months' => 'integer',
        'default_salvage_value_percent' => 'decimal:2',
    ];

    public function fixedAssetAccount()
    {
        return $this->belongsTo(Account::class, 'fixed_asset_account_id');
    }

    public function accumulatedDepreciationAccount()
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount()
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function assets()
    {
        return $this->hasMany(FixedAsset::class, 'asset_category_id');
    }

    public function canBeDeleted(): bool
    {
        return !$this->assets()->whereNotIn('status', ['voided'])->exists();
    }
}
