<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderStep extends Model
{
    protected $fillable = [
        'production_order_id', 'bom_step_id', 'step_number', 'department_id',
        'name', 'description', 'status', 'executor_id',
        'started_at', 'started_effective_at', 'paused_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'step_number'           => 'integer',
        'started_at'            => 'datetime',
        'started_effective_at'  => 'datetime',
        'paused_at'             => 'datetime',
        'completed_at'          => 'datetime',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function executor()
    {
        return $this->belongsTo(DepartmentExecutor::class);
    }

    public function executors()
    {
        return $this->belongsToMany(
            DepartmentExecutor::class,
            'production_order_step_executors',
            'step_id',
            'executor_id'
        )->withPivot('joined_at')->orderByPivot('joined_at');
    }

    public function bomStep()
    {
        return $this->belongsTo(BomStep::class);
    }

    public function timeLogs()
    {
        return $this->hasMany(ProductionStepTimeLog::class, 'production_order_step_id')
                    ->orderBy('occurred_at');
    }

    public function executorStatuses()
    {
        return $this->hasMany(ProductionStepExecutorStatus::class, 'step_id');
    }

    public function getElapsedWorkingSecondsAttribute(): int
    {
        $logs  = $this->relationLoaded('timeLogs') ? $this->timeLogs : $this->timeLogs()->get();
        $total = 0;
        $start = null;

        foreach ($logs as $log) {
            if (in_array($log->event_type, ['started', 'resumed', 'auto_resumed'])) {
                // Honor started_effective_at on the very first 'started' event of the day
                if ($log->event_type === 'started' && $this->started_effective_at) {
                    $start = $this->started_effective_at;
                } else {
                    $start = $log->occurred_at;
                }
            } elseif ($start && in_array($log->event_type, ['paused', 'auto_paused', 'completed'])) {
                $total += (int) $start->diffInSeconds($log->occurred_at);
                $start  = null;
            }
        }

        if ($start && $this->status === 'in_progress') {
            $total += (int) $start->diffInSeconds(now());
        }

        return $total;
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending'     => 'Antre',
            'in_progress' => 'Sedang Dikerjakan',
            'paused'      => 'Pending',
            'completed'   => 'Selesai',
            default       => ucfirst($this->status),
        };
    }
}
