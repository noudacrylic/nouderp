<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;
use App\Modules\Tasks\Models\TaskSubtask;
use Illuminate\Http\Request;

class TaskSubtaskController extends Controller
{
    private function authorizeModify(Task $task): void
    {
        if (!$task->canBeModifiedBy(auth()->user())) {
            abort(403, 'Hanya assignee atau admin yang dapat memodifikasi task ini.');
        }
    }

    public function store(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $data = $request->validate([
            'title' => 'required|string|max:200',
        ]);

        $sub = TaskSubtask::create([
            'task_id'    => $task->id,
            'title'      => $data['title'],
            'sort_order' => (int) TaskSubtask::where('task_id', $task->id)->max('sort_order') + 1,
        ]);

        TaskHistory::log($task->id, auth()->id(), 'subtask_added', 'Subtask ditambah: "' . $sub->title . '"');

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'subtask' => [
                    'id'      => $sub->id,
                    'title'   => $sub->title,
                    'is_done' => $sub->is_done,
                ],
            ]);
        }
        return back()->with('success', 'Subtask ditambahkan.');
    }

    public function toggle(Request $request, TaskSubtask $subtask)
    {
        $task = Task::find($subtask->task_id);
        if ($task) $this->authorizeModify($task);
        $subtask->is_done = !$subtask->is_done;
        $subtask->done_at = $subtask->is_done ? now() : null;
        $subtask->save();

        TaskHistory::log(
            $subtask->task_id,
            auth()->id(),
            'subtask_toggle',
            ($subtask->is_done ? 'Subtask selesai: "' : 'Subtask dibuka kembali: "') . $subtask->title . '"'
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'is_done' => $subtask->is_done]);
        }
        return back();
    }

    public function destroy(Request $request, TaskSubtask $subtask)
    {
        $task = Task::find($subtask->task_id);
        if ($task) $this->authorizeModify($task);
        $taskId = $subtask->task_id;
        $title = $subtask->title;
        $subtask->delete();

        TaskHistory::log($taskId, auth()->id(), 'subtask_removed', 'Subtask dihapus: "' . $title . '"');

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Subtask dihapus.');
    }
}
