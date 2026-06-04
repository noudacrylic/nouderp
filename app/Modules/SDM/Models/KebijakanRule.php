<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class KebijakanRule extends Model
{
    protected $table = 'sdm_kebijakan_rule';

    protected $fillable = ['nama', 'deskripsi', 'priority', 'is_active', 'conditions', 'effects'];

    protected $casts = [
        'is_active'  => 'boolean',
        'priority'   => 'integer',
        'conditions' => 'array',
        'effects'    => 'array',
    ];

    public const FIELDS = [
        'status'              => ['label' => 'Status Absensi',           'tipe' => 'enum_status'],
        'scan_masuk_menit'    => ['label' => 'Jam Scan Masuk',           'tipe' => 'time'],
        'scan_pulang_menit'   => ['label' => 'Jam Scan Pulang',          'tipe' => 'time'],
        'sanksi'              => ['label' => 'Status SP Karyawan',       'tipe' => 'enum_sp'],
        'lembur_jam'          => ['label' => 'Jam Lembur',               'tipe' => 'number'],
        'is_libur_nasional'   => ['label' => 'Hari Libur Nasional',      'tipe' => 'bool'],
        'is_libur_mingguan'   => ['label' => 'Hari Libur Mingguan',      'tipe' => 'bool'],
    ];

    public const OPERATORS = [
        'eq'  => '= (sama dengan)',
        'neq' => '≠ (tidak sama)',
        'gt'  => '> (lebih dari)',
        'gte' => '≥ (lebih dari sama)',
        'lt'  => '< (kurang dari)',
        'lte' => '≤ (kurang dari sama)',
        'in'  => 'in (salah satu dari)',
    ];

    public const EFFECT_KINDS = [
        'nominal'             => 'Nominal Rupiah',
        'persen_gaji_hari'    => '% × Gaji per Hari',
        'flag'                => 'Set Flag (untuk kolom tipe flag)',
    ];

    public function scopeAktif($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('priority')->orderBy('id');
    }
}
