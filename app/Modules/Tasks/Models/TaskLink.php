<?php

namespace App\Modules\Tasks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaskLink extends Model
{
    protected $fillable = [
        'task_id', 'linked_type', 'linked_id', 'label_snapshot', 'created_by_user_id',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Resolve via config('tasks.taskable_types'). Return [label, url] atau null. */
    public function resolve(): ?array
    {
        $map = config('tasks.taskable_types', []);
        if (!isset($map[$this->linked_type])) return null;

        $cfg = $map[$this->linked_type];
        try {
            $instance = (new $this->linked_type)->find($this->linked_id);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$instance) {
            return [
                'type'  => $cfg['label'],
                'label' => $this->label_snapshot ?: ('#' . $this->linked_id),
                'url'   => null,
                'gone'  => true,
            ];
        }

        return [
            'type'  => $cfg['label'],
            'label' => $instance->{$cfg['label_field']} ?? ('#' . $this->linked_id),
            'url'   => route($cfg['route'], $instance->id),
            'gone'  => false,
        ];
    }
}
