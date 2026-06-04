<?php

namespace App\Core\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Core\Journal\JournalLine;

class Account extends Model
{
    use SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_id',
        'normal_balance',
        'is_control_account',
        'is_system_account',
        'is_system',
        'is_active',
        'is_cash_account',
        'account_category',
    ];

    public function isCash()
    {
        return in_array($this->account_category, ['cash', 'cash_equivalent']);
    }

    public function isReceivable()
    {
        return $this->account_category === 'receivable';
    }

    public function isInventory()
    {
        return $this->account_category === 'inventory';
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines()
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
