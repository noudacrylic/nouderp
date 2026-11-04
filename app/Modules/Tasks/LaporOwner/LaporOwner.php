<?php

namespace App\Modules\Tasks\LaporOwner;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LaporOwner extends Model
{
    protected $table = 'lapor_owner';

    protected $fillable = ['pelapor_id', 'isi', 'penting', 'foto_path', 'balasan', 'dibalas_oleh', 'dibalas_at'];

    protected $casts = [
        'penting'    => 'boolean',
        'dibalas_at' => 'datetime',
    ];

    public function pelapor()
    {
        return $this->belongsTo(User::class, 'pelapor_id');
    }
}
