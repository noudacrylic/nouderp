<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class Task extends Model
{
    protected $fillable = [
        'title', 'description',
        'category_id', 'creator_user_id', 'assignee_user_id',
        'status', 'priority',
        'due_date', 'done_at',
        'taskable_type', 'taskable_id',
        'source', 'schedule_id',
        'position',
    ];

    protected $casts = [
        'due_date' => 'date',
        'done_at'  => 'datetime',
        'position' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function subtasks()
    {
        return $this->hasMany(TaskSubtask::class)->orderBy('sort_order');
    }

    public function links()
    {
        return $this->hasMany(TaskLink::class)->orderBy('id');
    }

    public function histories()
    {
        return $this->hasMany(TaskHistory::class)->orderByDesc('created_at');
    }

    public function schedule()
    {
        return $this->belongsTo(TaskSchedule::class, 'schedule_id');
    }

    public function taskable()
    {
        return $this->morphTo();
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeForUser($q, int $userId)
    {
        return $q->where(function ($qq) use ($userId) {
            $qq->where('creator_user_id', $userId)
               ->orWhere('assignee_user_id', $userId);
        });
    }

    public function scopeNotHiddenBy($q, int $userId)
    {
        return $q->whereNotIn('id', function ($sub) use ($userId) {
            $sub->select('task_id')
                ->from('user_hidden_tasks')
                ->where('user_id', $userId);
        });
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    /**
     * Pastikan kategori task ini tidak disembunyikan di preferensi assignee, supaya
     * assignee aware ada task baru. Skip bila assignee = creator (user atur sendiri).
     * Sekaligus auto-unhide task ini bila sebelumnya pernah disembunyikan assignee.
     */
    public function ensureVisibleForAssignee(): void
    {
        if (!$this->assignee_user_id) return;
        if ((int) $this->assignee_user_id === (int) $this->creator_user_id) return;

        $categoryKey = (int) ($this->category_id ?? 0);

        DB::table('user_hidden_task_categories')
            ->where('user_id', $this->assignee_user_id)
            ->where('task_category_id', $categoryKey)
            ->delete();

        DB::table('user_hidden_tasks')
            ->where('user_id', $this->assignee_user_id)
            ->where('task_id', $this->id)
            ->delete();
    }

    /**
     * Boleh ubah/hapus task: admin/super_admin, assignee, atau creator selama belum
     * di-assign ke user lain. Begitu assignee diisi user lain, task jadi read-only
     * untuk creator (hanya bisa hide/lihat).
     */
    public function canBeModifiedBy(?\App\Models\User $user): bool
    {
        if (!$user) return false;
        if (in_array($user->role, ['admin', 'super_admin'], true)) return true;
        if ($this->assignee_user_id && (int) $this->assignee_user_id === (int) $user->id) return true;
        if (!$this->assignee_user_id && (int) $this->creator_user_id === (int) $user->id) return true;
        return false;
    }

    public function priorityLabel(): array
    {
        return match ($this->priority) {
            'high'   => ['label' => 'High',   'class' => 'bg-red-100 text-red-700'],
            'normal' => ['label' => 'Normal', 'class' => 'bg-blue-100 text-blue-700'],
            'low'    => ['label' => 'Low',    'class' => 'bg-gray-100 text-gray-600'],
            default  => ['label' => ucfirst($this->priority), 'class' => 'bg-gray-100 text-gray-600'],
        };
    }

    public function statusLabel(): array
    {
        return match ($this->status) {
            'open'        => ['label' => 'Open',         'class' => 'bg-yellow-100 text-yellow-700'],
            'in_progress' => ['label' => 'In Progress',  'class' => 'bg-indigo-100 text-indigo-700'],
            'done'        => ['label' => 'Selesai',      'class' => 'bg-green-100 text-green-700'],
            'cancelled'   => ['label' => 'Dibatalkan',   'class' => 'bg-gray-200 text-gray-600'],
            default       => ['label' => ucfirst($this->status), 'class' => 'bg-gray-100 text-gray-600'],
        };
    }

    public function taskableLink(): ?array
    {
        if (!$this->taskable_type || !$this->taskable_id) return null;

        $map = config('tasks.taskable_types', []);
        $type = $this->taskable_type;
        if (!isset($map[$type])) return null;

        try {
            $instance = (new $type)->find($this->taskable_id);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$instance) return null;

        return [
            'type'  => $map[$type]['label'],
            'label' => $instance->{$map[$type]['label_field']} ?? '#' . $this->taskable_id,
            'url'   => route($map[$type]['route'], $instance->id),
        ];
    }
}
