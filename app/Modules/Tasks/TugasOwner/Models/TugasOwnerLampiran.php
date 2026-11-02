<?php

namespace App\Modules\Tasks\TugasOwner\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TugasOwnerLampiran extends Model
{
    protected $table = 'tugas_owner_lampiran';

    protected $fillable = ['tugas_id', 'pesan_id', 'user_id', 'path', 'nama', 'jenis', 'ukuran'];

    public function tugas(): BelongsTo
    {
        return $this->belongsTo(TugasOwner::class, 'tugas_id');
    }
}
