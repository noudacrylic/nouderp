<?php

namespace App\Modules\Analysis\Models;

use App\Modules\Production\Models\DepartmentExecutor;
use Illuminate\Database\Eloquent\Model;

/**
 * Asumsi jam untuk satu slot kapasitas.
 *
 * `executor_id` null = slot yang BELUM ADA — mesin atau orang yang sedang diandaikan. Itulah cara
 * menjawab "kalau beli mesin keempat" tanpa mendaftarkan mesin fiktif ke data produksi.
 */
class ProductionQuotaSlot extends Model
{
    protected $table = 'production_quota_slots';

    protected $fillable = [
        'executor_id', 'department_id', 'label',
        'assumed_hours_per_day', 'assumed_working_days', 'use_assumption',
    ];

    protected $casts = [
        'assumed_hours_per_day' => 'float',
        'assumed_working_days'  => 'float',
        'use_assumption'        => 'boolean',
    ];

    public function executor()
    {
        return $this->belongsTo(DepartmentExecutor::class, 'executor_id');
    }

    /** Slot pengandaian tidak menunjuk eksekutor mana pun. */
    public function isVirtual(): bool
    {
        return $this->executor_id === null;
    }
}
