<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionStepExecutorStatus extends Model
{
    protected $table = 'production_step_executor_status';

    protected $fillable = [
        'step_id', 'executor_id', 'karyawan_id',
        'is_active', 'paused_at', 'paused_reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'paused_at' => 'datetime',
    ];

    public function step()
    {
        return $this->belongsTo(ProductionOrderStep::class, 'step_id');
    }

    public function executor()
    {
        return $this->belongsTo(DepartmentExecutor::class, 'executor_id');
    }

    public function karyawan()
    {
        return $this->belongsTo(\App\Modules\SDM\Models\Karyawan::class, 'karyawan_id');
    }
}
