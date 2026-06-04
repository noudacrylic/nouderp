<?php

namespace App\Modules\FixedAsset\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Period\AccountingPeriod;
use App\Core\Journal\Journal;

class FixedAssetDepreciation extends Model
{
    protected $fillable = [
        'fixed_asset_id',
        'period_id',
        'period_year',
        'period_month',
        'depreciation_date',
        'amount',
        'accumulated_after',
        'book_value_after',
        'journal_id',
        'status',
    ];

    protected $casts = [
        'depreciation_date' => 'date',
        'amount' => 'decimal:2',
        'accumulated_after' => 'decimal:2',
        'book_value_after' => 'decimal:2',
        'period_year' => 'integer',
        'period_month' => 'integer',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function period()
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }
}
