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
        $raw   = null; // waktu log apa adanya, cadangan bila started_effective_at tidak masuk akal

        foreach ($logs as $log) {
            if (in_array($log->event_type, ['started', 'resumed', 'auto_resumed'])) {
                $raw = $log->occurred_at;
                // Honor started_effective_at on the very first 'started' event of the day
                $start = ($log->event_type === 'started' && $this->started_effective_at)
                    ? $this->started_effective_at
                    : $log->occurred_at;
            } elseif ($start && in_array($log->event_type, ['paused', 'auto_paused', 'completed'])) {
                // ProductionTimerSync::onFingerprintScan() menimpa started_effective_at dengan
                // jam masuk hari scan tanpa memeriksa apakah langkahnya sudah selesai, sehingga
                // langkah lama bisa punya started_effective_at SETELAH log penutupnya. Kalau
                // dipakai apa adanya, durasinya jadi negatif — jatuh balik ke waktu log asli.
                if ($log->occurred_at->lt($start)) {
                    $start = $raw;
                }
                $total += max(0, (int) $start->diffInSeconds($log->occurred_at));
                $start  = null;
                $raw    = null;
            }
        }

        if ($start && $this->status === 'in_progress') {
            $total += max(0, (int) $start->diffInSeconds(now()));
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
