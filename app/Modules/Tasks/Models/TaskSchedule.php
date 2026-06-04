<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Carbon\Carbon;

class TaskSchedule extends Model
{
    protected $fillable = [
        'title', 'description',
        'category_id', 'assignee_user_id', 'priority',
        'frequency', 'time_of_day', 'day_of_week', 'day_of_month', 'cron_expression',
        'subtasks_template',
        'is_active', 'last_run_at', 'next_run_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'subtasks_template' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'schedule_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /**
     * Hitung next_run_at berdasarkan frequency + last_run_at (atau now() jika belum pernah jalan).
     */
    public function computeNextRunAt(?Carbon $from = null): ?Carbon
    {
        $from = $from ?: ($this->last_run_at ? Carbon::parse($this->last_run_at) : now());
        $time = $this->time_of_day ?: '09:00:00';

        return match ($this->frequency) {
            'daily'   => $this->nextDaily($from, $time),
            'weekly'  => $this->nextWeekly($from, $time),
            'monthly' => $this->nextMonthly($from, $time),
            'custom_cron' => $this->nextFromCron($from),
            default => null,
        };
    }

    private function nextDaily(Carbon $from, string $time): Carbon
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '00');
        $next = $from->copy()->setTime((int) $h, (int) $m, (int) $s);
        if ($next->lessThanOrEqualTo($from)) $next->addDay();
        return $next;
    }

    private function nextWeekly(Carbon $from, string $time): Carbon
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '00');
        $dow = $this->day_of_week ?? 1;
        $next = $from->copy()->setTime((int) $h, (int) $m, (int) $s);
        $diff = ($dow - $next->dayOfWeek + 7) % 7;
        if ($diff === 0 && $next->lessThanOrEqualTo($from)) $diff = 7;
        return $next->addDays($diff);
    }

    private function nextMonthly(Carbon $from, string $time): Carbon
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '00');
        $dom = $this->day_of_month ?? 1;
        $next = $from->copy()->setTime((int) $h, (int) $m, (int) $s)->day(min($dom, $from->daysInMonth));
        if ($next->lessThanOrEqualTo($from)) {
            $next->addMonthNoOverflow()->day(min($dom, $next->copy()->endOfMonth()->day));
        }
        return $next;
    }

    private function nextFromCron(Carbon $from): ?Carbon
    {
        if (!$this->cron_expression) return null;
        try {
            $cron = new \Cron\CronExpression($this->cron_expression);
            return Carbon::instance($cron->getNextRunDate($from));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function frequencyLabel(): string
    {
        return match ($this->frequency) {
            'daily'   => 'Setiap hari',
            'weekly'  => 'Mingguan',
            'monthly' => 'Bulanan',
            'custom_cron' => 'Custom cron',
            default => ucfirst($this->frequency),
        };
    }
}
