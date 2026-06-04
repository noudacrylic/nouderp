<?php

namespace App\Modules\Tasks\Controllers;

use App\Core\Inventory\Product;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tasks\Models\TaskAutomationRule;
use App\Modules\Tasks\Models\TaskCategory;
use App\Modules\Tasks\Services\TaskAutomationService;
use Illuminate\Http\Request;

class TaskAutomationRuleController extends Controller
{
    private function resolveType(string $automationType): string
    {
        if (!in_array($automationType, ['stock', 'order'], true)) {
            abort(404);
        }
        return $automationType;
    }

    public function index(string $automationType)
    {
        $type = $this->resolveType($automationType);
        $rules = TaskAutomationRule::where('type', $type)
            ->with([
                // Stok dihitung live via SUM(qty_on_hand) dari product_stocks — sama dgn
                // perhitungan halaman Inventory (kolom products.stock statis & tidak akurat).
                'product' => fn($q) => $q->select('id', 'sku', 'name', 'min_stock')
                    ->withSum('stocks as stok_fisik', 'qty_on_hand'),
                'assignee:id,name',
                'category',
            ])
            ->orderByDesc('id')
            ->paginate(25);

        return view('erp.tasks.automation.rules_index', compact('rules', 'type'));
    }

    public function create(string $automationType)
    {
        $type = $this->resolveType($automationType);
        $rule = new TaskAutomationRule([
            'type'      => $type,
            'priority'  => 'normal',
            'is_active' => true,
        ]);
        return $this->renderForm($rule, $type, 'create');
    }

    public function store(Request $request, string $automationType)
    {
        $type = $this->resolveType($automationType);
        $data = $this->validateData($request);
        $data['type'] = $type;
        $data['created_by_user_id'] = auth()->id();
        $data['is_active'] = $request->boolean('is_active', true);

        TaskAutomationRule::create($data);

        return redirect()->route('tasks.automation.rules.index', ['automationType' => $type])
            ->with('success', 'Rule otomasi dibuat.');
    }

    public function edit(string $automationType, TaskAutomationRule $rule)
    {
        $type = $this->resolveType($automationType);
        if ($rule->type !== $type) abort(404);
        return $this->renderForm($rule, $type, 'edit');
    }

    public function update(Request $request, string $automationType, TaskAutomationRule $rule)
    {
        $type = $this->resolveType($automationType);
        if ($rule->type !== $type) abort(404);

        $data = $this->validateData($request);
        $data['is_active'] = $request->boolean('is_active', true);

        $rule->fill($data)->save();

        return redirect()->route('tasks.automation.rules.index', ['automationType' => $type])
            ->with('success', 'Rule diperbarui.');
    }

    public function destroy(string $automationType, TaskAutomationRule $rule)
    {
        $type = $this->resolveType($automationType);
        if ($rule->type !== $type) abort(404);
        $rule->delete();
        return back()->with('success', 'Rule dihapus.');
    }

    public function checkStockNow(string $automationType, TaskAutomationService $svc)
    {
        $type = $this->resolveType($automationType);
        if ($type !== 'stock') abort(404);
        $created = $svc->runStockCheck();
        $msg = $created > 0
            ? "Cek selesai — {$created} task baru dibuat."
            : 'Cek selesai — semua produk masih di atas stok minimum.';
        return back()->with('success', $msg);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'product_id'           => 'required|integer|exists:products,id',
            'assignee_user_id'     => 'required|integer|exists:users,id',
            'category_id'          => 'nullable|integer|exists:task_categories,id',
            'priority'             => 'required|in:low,normal,high',
            'title_template'       => 'nullable|string|max:200',
            'description_template' => 'nullable|string|max:5000',
        ]);
    }

    private function renderForm(TaskAutomationRule $rule, string $type, string $mode)
    {
        $categories = TaskCategory::active()->orderBy('sort_order')->orderBy('id')->get();
        $assignableUsers = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        // Stok dihitung live via SUM(qty_on_hand) — kolom products.stock tidak dipakai.
        $products = Product::withSum('stocks as stok_fisik', 'qty_on_hand')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'min_stock']);

        return view('erp.tasks.automation.rules_form', compact('rule', 'type', 'mode', 'categories', 'assignableUsers', 'products'));
    }
}
