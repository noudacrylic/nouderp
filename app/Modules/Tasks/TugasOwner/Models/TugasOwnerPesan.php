<?php

namespace App\Modules\Tasks\TugasOwner\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TugasOwnerPesan extends Model
{
    protected $table = 'tugas_owner_pesan';

    protected $fillable = ['tugas_id', 'user_id', 'jenis', 'isi'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lampiran(): HasMany
    {
        return $this->hasMany(TugasOwnerLampiran::class, 'pesan_id');
    }
}
