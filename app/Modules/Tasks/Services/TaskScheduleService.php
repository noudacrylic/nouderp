<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskSchedule;
use App\Modules\Tasks\Models\TaskSubtask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskScheduleService
{
    public function __construct(private TaskPositionService $positions) {}

    /**
     * Cek semua schedule aktif yang sudah due (next_run_at <= now),
     * buat task baru untuk masing-masing, lalu hitung next_run_at berikutnya.
     */
    public function runScheduled(): int
    {
        $count = 0;
        $now = now();

        $schedules = TaskSchedule::active()
            ->where(function ($q) use ($now) {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->get();

        foreach ($schedules as $schedule) {
            try {
                DB::transaction(function () use ($schedule, $now, &$count) {
                    $this->instantiateTask($schedule);
                    $schedule->last_run_at = $now;
                    $schedule->next_run_at = $schedule->computeNextRunAt($now);
                    $schedule->save();
                    $count++;
                });
            } catch (\Throwable $e) {
                Log::warning('TaskScheduleService.runScheduled failed', [
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function instantiateTask(TaskSchedule $schedule): Task
    {
        $creatorId = $schedule->created_by_user_id ?? $schedule->assignee_user_id;

        $task = Task::create([
            'title'            => $schedule->title,
            'description'      => $schedule->description,
            'category_id'      => $schedule->category_id,
            'creator_user_id'  => $creatorId,
            'assignee_user_id' => $schedule->assignee_user_id,
            'status'           => 'open',
            'priority'         => $schedule->priority ?? 'normal',
            'source'           => 'scheduled',
            'schedule_id'      => $schedule->id,
            'position'         => $this->positions->nextPosition($schedule->category_id),
        ]);

        $task->ensureVisibleForAssignee();

        $template = is_array($schedule->subtasks_template) ? $schedule->subtasks_template : [];
        foreach ($template as $idx => $title) {
            $title = trim((string) $title);
            if ($title === '') continue;
            TaskSubtask::create([
                'task_id'    => $task->id,
                'title'      => $title,
                'sort_order' => $idx + 1,
            ]);
        }

        return $task;
    }
}
