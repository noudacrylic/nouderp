<?php

namespace App\Modules\SDM\Models;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use Illuminate\Database\Eloquent\Model;

class KasbonPembayaran extends Model
{
    protected $table = 'sdm_kasbon_pembayaran';

    protected $fillable = [
        'code', 'kasbon_id', 'tanggal_bayar', 'jumlah',
        'source', 'slip_gaji_id', 'cash_account_id',
        'status', 'journal_id',
        'posted_at', 'voided_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'tanggal_bayar' => 'date',
        'jumlah'        => 'decimal:2',
        'posted_at'     => 'datetime',
        'voided_at'     => 'datetime',
    ];

    public function kasbon()
    {
        return $this->belongsTo(Kasbon::class);
    }

    public function slip()
    {
        return $this->belongsTo(SlipGaji::class, 'slip_gaji_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function isDraft(): bool  { return $this->status === 'draft'; }
    public function isPosted(): bool { return $this->status === 'posted'; }
    public function isVoid(): bool   { return $this->status === 'void'; }
    public function isManual(): bool { return $this->source === 'manual'; }
    public function isFromGaji(): bool { return $this->source === 'gaji'; }

    public function canBeEdited(): bool { return $this->status === 'draft' && $this->source === 'manual'; }
    public function canBePosted(): bool { return $this->status === 'draft'; }
    public function canBeVoided(): bool { return $this->status === 'posted'; }
}
