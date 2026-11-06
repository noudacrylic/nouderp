<?php

namespace App\Modules\Tasks\TugasOwner\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TugasOwner extends Model
{
    public const DRAF = 'draf';
    public const RINGKASAN = 'ringkasan';
    public const CEK_RINGKASAN = 'cek_ringkasan';
    public const DIKERJAKAN = 'dikerjakan';
    public const PERIKSA = 'periksa';
    public const SELESAI = 'selesai';
    public const BATAL = 'batal';

    public const LABEL_TAHAP = [
        self::DRAF          => 'Draf brief',
        self::RINGKASAN     => 'Admin menulis pemahaman',
        self::CEK_RINGKASAN => 'Owner cek pemahaman',
        self::DIKERJAKAN    => 'Dikerjakan',
        self::PERIKSA       => 'Owner periksa hasil',
        self::SELESAI       => 'Selesai',
        self::BATAL         => 'Batal',
    ];

    protected $table = 'tugas_owner';

    protected $fillable = ['judul', 'jenis', 'dibuat_oleh', 'pengerja_id', 'tenggat', 'prioritas', 'wajib_periksa', 'tahap', 'jumlah_revisi', 'selesai_at'];

    protected $casts = ['tenggat' => 'date', 'wajib_periksa' => 'boolean', 'selesai_at' => 'datetime'];

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function pengerja(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengerja_id');
    }

    public function pesan(): HasMany
    {
        return $this->hasMany(TugasOwnerPesan::class, 'tugas_id')->orderBy('id');
    }

    public function lewatTenggat(): bool
    {
        return $this->tenggat && $this->tenggat->lt(today()) && !in_array($this->tahap, [self::SELESAI, self::BATAL], true);
    }
}
