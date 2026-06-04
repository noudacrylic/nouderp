<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\MarketplaceConfig;

class MarketplaceSettlement extends Model
{
    protected $fillable = [
        'number',
        'date',
        'marketplace_config_id',
        'source_filename',
        'total_gross',
        'total_fee_actual',
        'total_fee_config',
        'total_fee_diff',
        'total_net',
        'status',
        'journal_id',
        'posted_at',
        'voided_at',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'total_gross' => 'decimal:2',
        'total_fee_actual' => 'decimal:2',
        'total_fee_config' => 'decimal:2',
        'total_fee_diff' => 'decimal:2',
        'total_net' => 'decimal:2',
    ];

    public function marketplaceConfig()
    {
        return $this->belongsTo(MarketplaceConfig::class, 'marketplace_config_id');
    }

    public function lines()
    {
        return $this->hasMany(MarketplaceSettlementLine::class);
    }

    public function canBeVoided(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
