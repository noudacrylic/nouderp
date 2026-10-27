<?php

namespace App\Modules\Tasks\MejaKerja\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan satu Skill Admin: aktif, peran pelaksana, bobot prioritas (1–10), SLA menit. */
class MejaKerjaSkill extends Model
{
    protected $table = 'meja_kerja_skill';

    protected $fillable = ['skill', 'aktif', 'peran', 'bobot', 'sla_menit', 'aktif_sejak'];

    protected $casts = [
        'aktif'       => 'boolean',
        'bobot'       => 'integer',
        'sla_menit'   => 'integer',
        'aktif_sejak' => 'datetime',
    ];
}
