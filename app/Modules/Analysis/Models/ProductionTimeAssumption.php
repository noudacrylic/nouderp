<?php

namespace App\Modules\Analysis\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Asumsi waktu per unit sebuah produk di sebuah divisi — PEMBILANG model HPP.
 *
 * Dua gunanya: menambal produk yang sampelnya belum layak, dan mengandaikan perubahan cara kerja
 * ("kalau assembling dipercepat jadi 2 jam, HPP-nya berapa"). Angka terukur tidak pernah ditimpa;
 * yang menentukan hanya `use_assumption`.
 */
class ProductionTimeAssumption extends Model
{
    protected $table = 'production_time_assumptions';

    protected $fillable = [
        'product_id', 'department_id', 'assumed_seconds_per_unit', 'use_assumption', 'notes',
    ];

    protected $casts = [
        'assumed_seconds_per_unit' => 'float',
        'use_assumption'           => 'boolean',
    ];
}
