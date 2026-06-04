<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

class TaskSubtask extends Model
{
    protected $fillable = [
        'task_id', 'title', 'is_done', 'done_at', 'sort_order',
    ];

    protected $casts = [
        'is_done' => 'boolean',
        'done_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
