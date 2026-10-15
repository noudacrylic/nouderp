<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntercomCall extends Model
{
    public const STATUS = ['tersambung', 'tak_terjawab', 'ditolak', 'sibuk', 'offline', 'gagal'];

    protected $fillable = ['dari', 'ke', 'user_id', 'status', 'mulai_at', 'durasi_detik'];

    protected $casts = [
        'mulai_at'     => 'datetime',
        'durasi_detik' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
