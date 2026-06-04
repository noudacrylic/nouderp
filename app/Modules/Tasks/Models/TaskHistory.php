<?php

namespace App\Modules\Tasks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaskHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id', 'user_id', 'action', 'summary', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Catat entri history. Helper supaya pemanggil ringkas. */
    public static function log(int $taskId, ?int $userId, string $action, string $summary): self
    {
        return self::create([
            'task_id'    => $taskId,
            'user_id'    => $userId,
            'action'     => $action,
            'summary'    => mb_substr($summary, 0, 255),
            'created_at' => now(),
        ]);
    }
}
