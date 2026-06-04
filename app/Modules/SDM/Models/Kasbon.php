<?php

namespace App\Modules\SDM\Models;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use Illuminate\Database\Eloquent\Model;

class Kasbon extends Model
{
    protected $table = 'sdm_kasbon';

    protected $fillable = [
        'code', 'karyawan_id', 'tanggal_pengajuan',
        'jumlah_pinjaman', 'cicilan_per_bulan', 'tanggal_mulai_potong',
        'cash_account_id', 'sisa_terhutang',
        'status', 'journal_id',
        'posted_at', 'voided_at', 'void_reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'tanggal_pengajuan'    => 'date',
        'tanggal_mulai_potong' => 'date',
        'jumlah_pinjaman'      => 'decimal:2',
        'cicilan_per_bulan'    => 'decimal:2',
        'sisa_terhutang'       => 'decimal:2',
        'posted_at'            => 'datetime',
        'voided_at'            => 'datetime',
    ];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function pembayaran()
    {
        return $this->hasMany(KasbonPembayaran::class, 'kasbon_id');
    }

    public function isDraft(): bool    { return $this->status === 'draft'; }
    public function isPosted(): bool   { return $this->status === 'posted'; }
    public function isLunas(): bool    { return $this->status === 'lunas'; }
    public function isVoid(): bool     { return $this->status === 'void'; }

    public function canBeEdited(): bool { return $this->status === 'draft'; }
    public function canBePosted(): bool { return $this->status === 'draft'; }
    public function canBeVoided(): bool { return in_array($this->status, ['posted', 'lunas'], true); }

    public function scopeAktif($q)
    {
        return $q->whereIn('status', ['posted'])->where('sisa_terhutang', '>', 0);
    }
}
