<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Models\TaskCategory;
use Illuminate\Http\Request;

class TaskCategoryController extends Controller
{
    public function index()
    {
        $categories = TaskCategory::orderBy('sort_order')->orderBy('id')->get();
        return view('erp.tasks.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('erp.tasks.categories.form', [
            'category' => new TaskCategory(['color' => '#94a3b8', 'is_active' => true]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by_user_id'] = auth()->id();
        $data['sort_order'] = (int) TaskCategory::max('sort_order') + 1;
        $category = TaskCategory::create($data);

        if ($request->wantsJson()) {
            return response()->json([
                'ok'       => true,
                'category' => [
                    'id'    => $category->id,
                    'name'  => $category->name,
                    'color' => $category->color,
                ],
                'message'  => 'Kategori dibuat.',
            ]);
        }

        return redirect()->route('tasks.categories.index')->with('success', 'Kategori dibuat.');
    }

    public function edit(TaskCategory $category)
    {
        $this->authorizeEdit($category);

        return view('erp.tasks.categories.form', [
            'category' => $category,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, TaskCategory $category)
    {
        $this->authorizeEdit($category);

        $data = $this->validateData($request);
        $category->fill($data)->save();

        if ($request->wantsJson()) {
            return response()->json([
                'ok'       => true,
                'category' => [
                    'id'    => $category->id,
                    'name'  => $category->name,
                    'color' => $category->color,
                ],
                'message'  => 'Kategori diperbarui.',
            ]);
        }

        return redirect()->route('tasks.categories.index')->with('success', 'Kategori diperbarui.');
    }

    private function authorizeEdit(TaskCategory $category): void
    {
        $user = auth()->user();
        if ($user->isAdmin() || $user->isSuperAdmin()) return;
        if ((int) $category->created_by_user_id === (int) $user->id) return;
        abort(403, 'Anda hanya bisa mengubah kategori yang Anda buat sendiri.');
    }

    public function destroy(TaskCategory $category)
    {
        $category->delete();
        return back()->with('success', 'Kategori dihapus.');
    }

    public function reorder(Request $request)
    {
        $ids = $request->input('ids', []);
        foreach ($ids as $idx => $id) {
            TaskCategory::where('id', (int) $id)->update(['sort_order' => $idx + 1]);
        }
        return response()->json(['ok' => true]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'      => 'required|string|max:100',
            'color'     => 'nullable|string|max:9',
            'is_active' => 'sometimes|boolean',
        ]) + ['is_active' => $request->boolean('is_active', true), 'color' => $request->input('color') ?: '#94a3b8'];
    }
}
