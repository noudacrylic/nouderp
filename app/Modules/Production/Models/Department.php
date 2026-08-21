<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'production_departments';

    protected $fillable = ['code', 'name', 'description', 'type', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeProduksi($q)
    {
        return $q->where('type', 'produksi');
    }

    public function karyawans()
    {
        return $this->hasMany(\App\Modules\SDM\Models\Karyawan::class);
    }

    public function executors()
    {
        return $this->hasMany(DepartmentExecutor::class);
    }

    public function activeExecutors()
    {
        return $this->hasMany(DepartmentExecutor::class)->where('is_active', true);
    }

    /**
     * Yang muncul di pilihan "Mulai" — hanya pelaku sebenarnya, tanpa operator penaung.
     * Lihat DepartmentExecutor::scopeSelectable().
     */
    public function selectableExecutors()
    {
        return $this->hasMany(DepartmentExecutor::class)->selectable();
    }

    public function steps()
    {
        return $this->hasMany(BomStep::class);
    }

    public function orderSteps()
    {
        return $this->hasMany(ProductionOrderStep::class);
    }
}
