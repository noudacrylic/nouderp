<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaskLinkController extends Controller
{
    private function authorizeModify(Task $task): void
    {
        if (!$task->canBeModifiedBy(auth()->user())) {
            abort(403, 'Hanya assignee atau admin yang dapat memodifikasi task ini.');
        }
    }

    /**
     * Terima nomor/identifier dokumen tunggal (mis. "SQ/2026/05/00001"),
     * cari ke SEMUA tipe dokumen di config tasks.taskable_types. Setiap tipe yg
     * dokumen-nya match dgn nomor tsb akan dibuat 1 TaskLink — supaya kasus marketplace
     * (SO dan Invoice pakai nomor pesanan yg sama) menghasilkan 2 link ke view masing-masing.
     */
    public function store(Request $request, Task $task)
    {
        $this->authorizeModify($task);

        $data = $request->validate([
            'linked_number' => 'required|string|max:200',
        ]);

        $needle = trim($data['linked_number']);
        if ($needle === '') {
            throw ValidationException::withMessages([
                'linked_number' => 'Nomor dokumen wajib diisi.',
            ]);
        }

        $map = config('tasks.taskable_types', []);
        $created = [];
        $skippedDup = [];

        foreach ($map as $cls => $cfg) {
            $matchField = $cfg['match_field'] ?? $cfg['label_field'];
            try {
                $instance = (new $cls)->where($matchField, $needle)->first();
            } catch (\Throwable $e) {
                continue;
            }
            if (!$instance) continue;

            $alreadyLinked = TaskLink::where('task_id', $task->id)
                ->where('linked_type', $cls)
                ->where('linked_id', $instance->id)
                ->exists();
            if ($alreadyLinked) {
                $skippedDup[] = $cfg['label'];
                continue;
            }

            $label = $instance->{$cfg['label_field']} ?? ('#' . $instance->id);

            $link = TaskLink::create([
                'task_id'            => $task->id,
                'linked_type'        => $cls,
                'linked_id'          => $instance->id,
                'label_snapshot'     => $label,
                'created_by_user_id' => auth()->id(),
            ]);

            TaskHistory::log($task->id, auth()->id(), 'link_added', "Link ditambah: {$cfg['label']} {$label}");

            $created[] = [
                'id'    => $link->id,
                'type'  => $cfg['label'],
                'label' => $label,
                'url'   => route($cfg['route'], $instance->id),
            ];
        }

        if (empty($created)) {
            if (!empty($skippedDup)) {
                throw ValidationException::withMessages([
                    'linked_number' => 'Dokumen ini sudah dilink ke task (' . implode(', ', $skippedDup) . ').',
                ]);
            }
            throw ValidationException::withMessages([
                'linked_number' => "Dokumen dengan nomor \"{$needle}\" tidak ditemukan.",
            ]);
        }

        return response()->json([
            'ok'    => true,
            'links' => $created,
            'count' => count($created),
        ]);
    }

    public function destroy(TaskLink $link)
    {
        $task = Task::find($link->task_id);
        if ($task) $this->authorizeModify($task);
        $taskId = $link->task_id;
        $summary = "Link dihapus: {$link->linked_type} #{$link->linked_id}";
        if ($link->label_snapshot) {
            $summary = "Link dihapus: {$link->label_snapshot}";
        }
        $link->delete();
        TaskHistory::log($taskId, auth()->id(), 'link_removed', $summary);

        return response()->json(['ok' => true]);
    }
}
