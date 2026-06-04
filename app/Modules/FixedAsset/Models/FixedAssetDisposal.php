<?php

namespace App\Modules\FixedAsset\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;
use App\Core\Journal\Journal;

class FixedAssetDisposal extends Model
{
    protected $fillable = [
        'disposal_number',
        'fixed_asset_id',
        'disposal_date',
        'disposal_type',
        'proceeds_amount',
        'proceeds_account_id',
        'book_value_at_disposal',
        'gain_loss_amount',
        'notes',
        'status',
        'journal_id',
        'posted_at',
        'posted_by',
        'voided_at',
        'created_by',
    ];

    protected $casts = [
        'disposal_date' => 'date',
        'proceeds_amount' => 'decimal:2',
        'book_value_at_disposal' => 'decimal:2',
        'gain_loss_amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function proceedsAccount()
    {
        return $this->belongsTo(Account::class, 'proceeds_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function canBeVoided(): bool
    {
        return $this->status === 'posted';
    }
}
