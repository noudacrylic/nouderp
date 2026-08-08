<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskCategory;
use App\Modules\Tasks\Models\TaskHistory;
use App\Modules\Tasks\Models\TaskSubtask;
use App\Modules\Tasks\Services\TaskPositionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function __construct(private TaskPositionService $positions) {}

    /** Board view — kolom per kategori. */
    public function index(Request $request)
    {
        $userId = auth()->id();
        $filterAssignee = $request->get('assignee', 'all');
        $filterPriority = $request->get('priority');
        $search = trim((string) $request->get('search', ''));
        $showDone = $request->boolean('show_done');

        $allCategories = TaskCategory::active()->orderBy('sort_order')->orderBy('id')->get();
        $hiddenIds = DB::table('user_hidden_task_categories')
            ->where('user_id', $userId)
            ->pluck('task_category_id')
            ->map(fn($v) => (int) $v)
            ->all();
        $categories = $allCategories->reject(fn($c) => in_array($c->id, $hiddenIds, true))->values();
        $showUncategorized = !in_array(0, $hiddenIds, true);

        $hiddenTaskIds = DB::table('user_hidden_tasks')
            ->where('user_id', $userId)
            ->pluck('task_id')
            ->map(fn($v) => (int) $v)
            ->all();

        $q = Task::with(['assignee:id,name', 'subtasks:id,task_id,is_done,title,sort_order'])
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderBy('position')
            ->orderBy('id');

        if (!$showDone) {
            $q->whereNotIn('status', ['done', 'cancelled']);
        }

        if (!empty($hiddenTaskIds)) {
            $q->whereNotIn('id', $hiddenTaskIds);
        }

        if ($filterAssignee === 'me') {
            $q->where('assignee_user_id', $userId);
        } elseif ($filterAssignee === 'mine') {
            $q->where('creator_user_id', $userId);
        } elseif (is_numeric($filterAssignee)) {
            $q->where('assignee_user_id', (int) $filterAssignee);
        }

        // Role `user` hanya boleh lihat task yang di-assign ke dirinya atau yang dia buat.
        if (auth()->user()->role === 'user') {
            $q->where(function ($w) use ($userId) {
                $w->where('assignee_user_id', $userId)
                  ->orWhere('creator_user_id', $userId);
            });
        }

        if ($filterPriority) $q->where('priority', $filterPriority);
        if ($search !== '') $q->where('title', 'like', "%{$search}%");

        $tasks = $q->get();
        $tasksByCategory = $tasks->groupBy(fn($t) => $t->category_id ?? 0);

        $assignableUsers = User::assignable()->orderBy('name')->get(['id', 'name']);

        return view('erp.tasks.index', compact(
            'categories', 'allCategories', 'hiddenIds', 'showUncategorized',
            'tasksByCategory', 'assignableUsers',
            'filterAssignee', 'filterPriority', 'search', 'showDone'
        ));
    }

    /** Sembunyikan task dari board user yang sedang login (per-user). */
    public function hide(Request $request, Task $task)
    {
        $userId = auth()->id();
        DB::table('user_hidden_tasks')->updateOrInsert(
            ['user_id' => $userId, 'task_id' => $task->id],
            ['updated_at' => now(), 'created_at' => now()]
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Task disembunyikan dari board.');
    }

    /** Tampilkan kembali task yang sebelumnya disembunyikan. */
    public function unhide(Request $request, Task $task)
    {
        DB::table('user_hidden_tasks')
            ->where('user_id', auth()->id())
            ->where('task_id', $task->id)
            ->delete();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Task ditampilkan kembali.');
    }

    /** Reject request bila user tidak punya wewenang ubah task. */
    private function authorizeModify(Task $task): void
    {
        if (!$task->canBeModifiedBy(auth()->user())) {
            abort(403, 'Hanya assignee atau admin yang dapat memodifikasi task ini.');
        }
    }

    private function forceCategoryVisibleForAssignee(Task $task): void
    {
        $task->ensureVisibleForAssignee();
    }

    /** Simpan preferensi kategori yang ditampilkan untuk user yang login. */
    public function saveVisibleCategories(Request $request)
    {
        $data = $request->validate([
            'visible_category_ids'   => 'nullable|array',
            'visible_category_ids.*' => 'integer|min:0',
            'show_uncategorized'     => 'nullable|boolean',
        ]);

        $userId = auth()->id();
        $visibleIds = collect($data['visible_category_ids'] ?? [])->map(fn($v) => (int) $v);

        $allActiveIds = TaskCategory::active()->pluck('id')->map(fn($v) => (int) $v);
        $hiddenIds = $allActiveIds->reject(fn($id) => $visibleIds->contains($id))->values();

        if (!$request->boolean('show_uncategorized')) {
            $hiddenIds->push(0);
        }

        DB::transaction(function () use ($userId, $hiddenIds) {
            DB::table('user_hidden_task_categories')->where('user_id', $userId)->delete();
            $now = now();
            $rows = $hiddenIds->map(fn($id) => [
                'user_id'          => $userId,
                'task_category_id' => (int) $id,
                'created_at'       => $now,
                'updated_at'       => $now,
            ])->all();
            if (!empty($rows)) {
                DB::table('user_hidden_task_categories')->insert($rows);
            }
        });

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Preferensi kategori disimpan.');
    }

    /** Table list view alternatif. */
    public function list(Request $request)
    {
        $userId = auth()->id();

        $q = Task::with(['category', 'assignee:id,name', 'creator:id,name'])
            ->orderByDesc('id');

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') $q->where('title', 'like', "%{$search}%");

        $status = $request->get('status');
        if ($status) $q->where('status', $status);

        $priority = $request->get('priority');
        if ($priority) $q->where('priority', $priority);

        $assignee = $request->get('assignee');
        if ($assignee === 'me') {
            $q->where('assignee_user_id', $userId);
        } elseif (is_numeric($assignee)) {
            $q->where('assignee_user_id', (int) $assignee);
        }

        // Role `user` hanya boleh lihat task yang di-assign ke dirinya atau yang dia buat.
        // Administrator (admin/super_admin) bisa lihat semua task.
        if (auth()->user()->role === 'user') {
            $q->where(function ($w) use ($userId) {
                $w->where('assignee_user_id', $userId)
                  ->orWhere('creator_user_id', $userId);
            });
        }

        $tasks = $q->paginate(25)->withQueryString();
        $assignableUsers = User::assignable()->orderBy('name')->get(['id', 'name']);

        // Task yang user current sembunyikan — supaya tampil sebagai indicator di list dan
        // bisa di-unhide langsung dari halaman ini.
        $hiddenTaskIds = DB::table('user_hidden_tasks')
            ->where('user_id', $userId)
            ->pluck('task_id')
            ->map(fn($v) => (int) $v)
            ->all();

        return view('erp.tasks.list', compact(
            'tasks', 'assignableUsers', 'search', 'status', 'priority', 'assignee', 'hiddenTaskIds'
        ));
    }

    public function create(Request $request)
    {
        $task = new Task([
            'category_id' => $request->get('category_id'),
            'priority' => 'normal',
            'status' => 'open',
        ]);
        return $this->renderForm($task, 'create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['creator_user_id'] = auth()->id();
        $data['position'] = $this->positions->nextPosition($data['category_id'] ?? null);

        DB::transaction(function () use ($data, $request, &$task) {
            $task = Task::create($data);
            $this->syncSubtasks($task, $request->input('subtasks', []));
        });

        $this->forceCategoryVisibleForAssignee($task);

        TaskHistory::log($task->id, auth()->id(), 'created', 'Task dibuat: "' . $task->title . '"');

        if ($request->wantsJson()) {
            return response()->json([
                'ok'      => true,
                'task_id' => $task->id,
                'message' => 'Task berhasil dibuat.',
            ]);
        }

        return redirect()->route('tasks.show', $task)->with('success', 'Task berhasil dibuat.');
    }

    public function show(Task $task)
    {
        $task->load(['category', 'assignee', 'creator', 'subtasks', 'schedule']);
        return view('erp.tasks.show', compact('task'));
    }

    public function edit(Task $task)
    {
        $task->load('subtasks');
        return $this->renderForm($task, 'edit');
    }

    public function update(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $data = $this->validateData($request);

        DB::transaction(function () use ($task, $data, $request) {
            // Bila category_id berubah, beri posisi terakhir di kategori baru
            if (($data['category_id'] ?? null) != $task->category_id) {
                $data['position'] = $this->positions->nextPosition($data['category_id'] ?? null);
            }
            $task->fill($data)->save();
            $this->syncSubtasks($task, $request->input('subtasks', []));
        });

        $this->forceCategoryVisibleForAssignee($task);

        TaskHistory::log($task->id, auth()->id(), 'updated', 'Task diperbarui');

        return redirect()->route('tasks.show', $task)->with('success', 'Task diperbarui.');
    }

    public function destroy(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $title = $task->title;
        $task->delete();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => 'Task "' . $title . '" dihapus.']);
        }
        return redirect()->route('tasks.index')->with('success', 'Task dihapus.');
    }

    /** Drag-drop endpoint: ubah category & position. */
    public function move(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $data = $request->validate([
            'category_id' => 'nullable|integer|exists:task_categories,id',
            'position'    => 'required|integer|min:0',
        ]);

        $oldCatId = $task->category_id;
        $newCatId = $data['category_id'] ?? null;

        $this->positions->moveTask($task, $newCatId, (int) $data['position']);

        if ($oldCatId !== $newCatId) {
            $oldName = $oldCatId ? optional(TaskCategory::find($oldCatId))->name : 'Tanpa Kategori';
            $newName = $newCatId ? optional(TaskCategory::find($newCatId))->name : 'Tanpa Kategori';
            TaskHistory::log($task->id, auth()->id(), 'category', "Kategori: {$oldName} → {$newName}");

            $task->refresh();
            $this->forceCategoryVisibleForAssignee($task);
        }

        return response()->json(['ok' => true]);
    }

    /** Quick toggle status open ↔ done (cycle). */
    public function toggleStatus(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $data = $request->validate([
            'status' => 'required|in:open,in_progress,done,cancelled',
        ]);

        $old = $task->status;
        $task->status = $data['status'];
        $task->done_at = $data['status'] === 'done' ? now() : null;
        $task->save();

        if ($old !== $task->status) {
            TaskHistory::log($task->id, auth()->id(), 'status', "Status: {$old} → {$task->status}");
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'status' => $task->status]);
        }
        return back()->with('success', 'Status diperbarui.');
    }

    /** Ubah assignee inline dari card. */
    public function updateAssignee(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $data = $request->validate([
            'assignee_user_id' => ['nullable', 'integer', User::assignableExistsRule()],
        ]);

        $oldId = $task->assignee_user_id;
        $newId = $data['assignee_user_id'] ?? null;

        if ($oldId == $newId) {
            return response()->json(['ok' => true, 'unchanged' => true]);
        }

        $task->assignee_user_id = $newId;
        $task->save();

        $this->forceCategoryVisibleForAssignee($task);

        $oldName = $oldId ? optional(User::find($oldId))->name : 'Tidak ditentukan';
        $newName = $newId ? optional(User::find($newId))->name : 'Tidak ditentukan';
        TaskHistory::log($task->id, auth()->id(), 'assignee', "Ditugaskan: {$oldName} → {$newName}");

        return response()->json([
            'ok' => true,
            'assignee' => $newId ? ['id' => $newId, 'name' => $newName] : null,
        ]);
    }

    /** Ubah priority inline dari card. */
    public function updatePriority(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $data = $request->validate([
            'priority' => 'required|in:low,normal,high',
        ]);

        $old = $task->priority;
        if ($old === $data['priority']) {
            return response()->json(['ok' => true, 'unchanged' => true]);
        }

        $task->priority = $data['priority'];
        $task->save();

        TaskHistory::log($task->id, auth()->id(), 'priority', "Prioritas: {$old} → {$task->priority}");

        return response()->json(['ok' => true, 'priority' => $task->priority]);
    }

    /** Ubah deskripsi inline (dari modal detail). */
    public function updateDescription(Request $request, Task $task)
    {
        $this->authorizeModify($task);
        $data = $request->validate([
            'description' => 'nullable|string|max:5000',
        ]);

        $task->description = $data['description'] ?? null;
        $task->save();

        TaskHistory::log($task->id, auth()->id(), 'description', 'Deskripsi diperbarui');

        return response()->json(['ok' => true]);
    }

    /** Detail task dalam bentuk JSON untuk modal popup. */
    public function showJson(Task $task)
    {
        $task->load(['assignee:id,name', 'creator:id,name', 'category', 'subtasks', 'links', 'histories.user:id,name']);

        $links = collect();

        // Polymorphic taskable (mis. SO/Product yang men-trigger auto-task) ditampilkan
        // sebagai "auto-link" non-deletable di paling atas — supaya dokumen sumber langsung
        // terlihat & clickable tanpa user perlu link manual.
        $taskableLink = $task->taskableLink();
        if ($taskableLink) {
            $links->push([
                'id'    => null,
                'type'  => $taskableLink['type'],
                'label' => $taskableLink['label'],
                'url'   => $taskableLink['url'],
                'gone'  => false,
                'auto'  => true,
            ]);
        }

        $links = $links->concat($task->links->map(function ($lnk) {
            $r = $lnk->resolve();
            return [
                'id'    => $lnk->id,
                'type'  => $r['type']  ?? $lnk->linked_type,
                'label' => $r['label'] ?? ('#' . $lnk->linked_id),
                'url'   => $r['url']   ?? null,
                'gone'  => $r['gone']  ?? false,
                'auto'  => false,
            ];
        }));

        $histories = $task->histories->take(50)->map(fn($h) => [
            'id'      => $h->id,
            'action'  => $h->action,
            'summary' => $h->summary,
            'user'    => $h->user?->name,
            'at'      => $h->created_at?->format('d M Y H:i'),
        ]);

        return response()->json([
            'ok'   => true,
            'task' => [
                'id'          => $task->id,
                'title'       => $task->title,
                'description' => $task->description,
                'status'      => $task->status,
                'priority'    => $task->priority,
                'category'    => $task->category ? ['id' => $task->category->id, 'name' => $task->category->name, 'color' => $task->category->color] : null,
                'assignee'    => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
                'creator'     => $task->creator?->name,
                'due_date'    => optional($task->due_date)->format('Y-m-d'),
                'created_at'  => $task->created_at?->format('d M Y H:i'),
            ],
            'links'     => $links,
            'histories' => $histories,
        ]);
    }

    private function validateData(Request $request): array
    {
        $taskableTypes = array_keys(config('tasks.taskable_types', []));

        return $request->validate([
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string|max:5000',
            'category_id'      => 'nullable|integer|exists:task_categories,id',
            'assignee_user_id' => ['nullable', 'integer', User::assignableExistsRule()],
            'priority'         => 'required|in:low,normal,high',
            'status'           => 'required|in:open,in_progress,done,cancelled',
            'due_date'         => 'nullable|date',
            'taskable_type'    => ['nullable', 'string', function ($attr, $val, $fail) use ($taskableTypes) {
                if ($val && !in_array($val, $taskableTypes, true)) {
                    $fail('Tipe dokumen tidak dikenal.');
                }
            }],
            'taskable_id'      => 'nullable|integer|min:1|required_with:taskable_type',
        ]);
    }

    private function syncSubtasks(Task $task, array $rows): void
    {
        $keepIds = [];
        foreach (array_values($rows) as $idx => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') continue;

            $payload = [
                'task_id'    => $task->id,
                'title'      => $title,
                'is_done'    => !empty($row['is_done']),
                'sort_order' => $idx + 1,
            ];
            $payload['done_at'] = $payload['is_done'] ? ($row['done_at'] ?? now()) : null;

            if (!empty($row['id']) && is_numeric($row['id'])) {
                $sub = TaskSubtask::where('task_id', $task->id)->find((int) $row['id']);
                if ($sub) {
                    $sub->fill($payload)->save();
                    $keepIds[] = $sub->id;
                    continue;
                }
            }
            $new = TaskSubtask::create($payload);
            $keepIds[] = $new->id;
        }
        TaskSubtask::where('task_id', $task->id)
            ->whereNotIn('id', $keepIds ?: [0])
            ->delete();
    }

    private function renderForm(Task $task, string $mode)
    {
        $categories = TaskCategory::active()->orderBy('sort_order')->orderBy('id')->get();
        $assignableUsers = User::assignable()->orderBy('name')->get(['id', 'name']);
        $taskableTypes = config('tasks.taskable_types', []);
        return view('erp.tasks.form', compact('task', 'categories', 'assignableUsers', 'taskableTypes', 'mode'));
    }
}
