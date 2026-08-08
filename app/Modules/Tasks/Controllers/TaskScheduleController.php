<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tasks\Models\TaskCategory;
use App\Modules\Tasks\Models\TaskSchedule;
use App\Modules\Tasks\Services\TaskAutomationService;
use Illuminate\Http\Request;

class TaskScheduleController extends Controller
{
    public function index()
    {
        $schedules = TaskSchedule::with(['category', 'assignee:id,name'])
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

    public function store(Request $request, TaskAutomationService $svc)
    {
        $data = $this->validateData($request);
        $data['created_by_user_id'] = auth()->id();
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
        return $this->renderForm($schedule, 'edit');
    }

    public function update(Request $request, TaskSchedule $schedule)
    {
        $data = $this->validateData($request);
        $data['subtasks_template'] = $this->parseSubtasksTemplate($request);

        $schedule->fill($data);
        $schedule->next_run_at = $schedule->computeNextRunAt();
        $schedule->save();

        return redirect()->route('tasks.schedules.index')->with('success', 'Jadwal diperbarui.');
    }

    public function destroy(TaskSchedule $schedule)
    {
        $schedule->delete();
        return back()->with('success', 'Jadwal dihapus.');
    }

    public function runNow(TaskSchedule $schedule, TaskAutomationService $svc)
    {
        $task = $svc->instantiateTask($schedule);
        $schedule->last_run_at = now();
        $schedule->next_run_at = $schedule->computeNextRunAt(now());
        $schedule->save();

        return redirect()->route('tasks.show', $task)->with('success', 'Task baru dibuat dari jadwal.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string|max:5000',
            'category_id'      => 'nullable|integer|exists:task_categories,id',
            'assignee_user_id' => ['nullable', 'integer', User::assignableExistsRule()],
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
        $assignableUsers = User::assignable()->orderBy('name')->get(['id', 'name']);
        return view('erp.tasks.schedules.form', compact('schedule', 'categories', 'assignableUsers', 'mode'));
    }
}
