<?php

namespace App\Modules\Tasks\MejaKerja\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan satu Skill Admin: peran pelaksana, bobot prioritas (1–10), SLA menit, dan opsi
 * khusus skill (mis. pola SKU Desain pesanan). Nyala/mati = mode skill karyawan virtual
 * Manajer Admin ('admin.<skill>').
 */
class MejaKerjaSkill extends Model
{
    protected $table = 'meja_kerja_skill';

    protected $fillable = ['skill', 'peran', 'bobot', 'sla_menit', 'opsi'];

    protected $casts = [
        'bobot'     => 'integer',
        'sla_menit' => 'integer',
        'opsi'      => 'array',
    ];
}
