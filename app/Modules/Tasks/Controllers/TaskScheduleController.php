<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Models\TaskCategory;
use App\Modules\Tasks\Models\TaskSchedule;
use App\Modules\Tasks\Services\TaskScheduleService;
use Illuminate\Http\Request;

class TaskScheduleController extends Controller
{
    public function index()
    {
        $uid = auth()->id();
        $schedules = TaskSchedule::with(['category', 'assignee:id,name'])
            ->where(fn ($q) => $q->where('assignee_user_id', $uid)->orWhere('created_by_user_id', $uid))
            ->orderByDesc('id')
            ->paginate(25);
        return view('erp.tasks.schedules.index', compact('schedules'));
    }

    public function create()
    {
        return $this->renderForm(new TaskSchedule([
            'priority' => 'normal', 'frequency' => 'daily', 'time_of_day' => '09:00',
            'is_active' => true,
        ]), 'create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by_user_id'] = auth()->id();
        $data['assignee_user_id'] = auth()->id();
        $data['subtasks_template'] = $this->parseSubtasksTemplate($request);

        $schedule = TaskSchedule::create($data);
        $schedule->next_run_at = $schedule->computeNextRunAt();
        $schedule->save();

        return redirect()->route('tasks.schedules.index')->with('success', 'Jadwal otomatis dibuat.');
    }

    public function show(TaskSchedule $schedule)
    {
        return redirect()->route('tasks.schedules.edit', $schedule);
    }

    public function edit(TaskSchedule $schedule)
    {
        $this->authorizeModify($schedule);
        return $this->renderForm($schedule, 'edit');
    }

    public function update(Request $request, TaskSchedule $schedule)
    {
        $this->authorizeModify($schedule);
        $data = $this->validateData($request);
        $data['subtasks_template'] = $this->parseSubtasksTemplate($request);

        $schedule->fill($data);
        $schedule->next_run_at = $schedule->computeNextRunAt();
        $schedule->save();

        return redirect()->route('tasks.schedules.index')->with('success', 'Jadwal diperbarui.');
    }

    public function destroy(TaskSchedule $schedule)
    {
        $this->authorizeModify($schedule);
        $schedule->delete();
        return back()->with('success', 'Jadwal dihapus.');
    }

    public function runNow(TaskSchedule $schedule, TaskScheduleService $svc)
    {
        $this->authorizeModify($schedule);
        $task = $svc->instantiateTask($schedule);
        $schedule->last_run_at = now();
        $schedule->next_run_at = $schedule->computeNextRunAt(now());
        $schedule->save();

        return redirect()->route('tasks.show', $task)->with('success', 'Task baru dibuat dari jadwal.');
    }

    /** Pemilik jadwal (penerima tugas atau pembuat), atau admin. */
    private function authorizeModify(TaskSchedule $schedule): void
    {
        $u = auth()->user();
        abort_unless(
            in_array($u->role, ['admin', 'super_admin'], true)
                || (int) $schedule->assignee_user_id === (int) $u->id
                || (int) $schedule->created_by_user_id === (int) $u->id,
            403,
            'Jadwal ini milik orang lain.'
        );
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string|max:5000',
            'category_id'      => 'nullable|integer|exists:task_categories,id',
            'priority'         => 'required|in:low,normal,high',
            'frequency'        => 'required|in:daily,weekly,monthly,custom_cron',
            'time_of_day'      => 'nullable|date_format:H:i',
            'day_of_week'      => 'nullable|integer|min:0|max:6',
            'day_of_month'     => 'nullable|integer|min:1|max:31',
            'cron_expression'  => 'nullable|string|max:100',
            'is_active'        => 'sometimes|boolean',
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }

    private function parseSubtasksTemplate(Request $request): array
    {
        $raw = $request->input('subtasks_template', []);
        if (is_string($raw)) {
            $raw = preg_split('/\r?\n/', $raw) ?: [];
        }
        $out = [];
        foreach ((array) $raw as $line) {
            $line = trim((string) $line);
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    private function renderForm(TaskSchedule $schedule, string $mode)
    {
        $categories = TaskCategory::active()->orderBy('sort_order')->orderBy('id')->get();
        return view('erp.tasks.schedules.form', compact('schedule', 'categories', 'mode'));
    }
}
