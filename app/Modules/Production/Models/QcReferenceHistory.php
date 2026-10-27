<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/** Arsip acuan lama — dibuat setiap kali acuan diambil ulang. */
class QcReferenceHistory extends Model
{
    protected $table = 'qc_reference_history';

    protected $fillable = ['qc_profile_id', 'snapshot', 'reason', 'user_id'];

    protected $casts = ['snapshot' => 'array'];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
