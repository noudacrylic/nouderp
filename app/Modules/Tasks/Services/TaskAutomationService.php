<?php

namespace App\Modules\Tasks\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockMovement;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAutomationRule;
use App\Modules\Tasks\Models\TaskSchedule;
use App\Modules\Tasks\Models\TaskSubtask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskAutomationService
{
    public function __construct(private TaskPositionService $positions) {}

    /**
     * Cek semua schedule aktif yang sudah due (next_run_at <= now),
     * buat task baru untuk masing-masing, lalu hitung next_run_at berikutnya.
     */
    public function runScheduled(): int
    {
        $count = 0;
        $now = now();

        $schedules = TaskSchedule::active()
            ->where(function ($q) use ($now) {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->get();

        foreach ($schedules as $schedule) {
            try {
                DB::transaction(function () use ($schedule, $now, &$count) {
                    $this->instantiateTask($schedule);
                    $schedule->last_run_at = $now;
                    $schedule->next_run_at = $schedule->computeNextRunAt($now);
                    $schedule->save();
                    $count++;
                });
            } catch (\Throwable $e) {
                Log::warning('TaskAutomationService.runScheduled failed', [
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function instantiateTask(TaskSchedule $schedule): Task
    {
        $creatorId = $schedule->created_by_user_id ?? $schedule->assignee_user_id;

        $task = Task::create([
            'title'            => $schedule->title,
            'description'      => $schedule->description,
            'category_id'      => $schedule->category_id,
            'creator_user_id'  => $creatorId,
            'assignee_user_id' => $schedule->assignee_user_id,
            'status'           => 'open',
            'priority'         => $schedule->priority ?? 'normal',
            'source'           => 'scheduled',
            'schedule_id'      => $schedule->id,
            'position'         => $this->positions->nextPosition($schedule->category_id),
        ]);

        $task->ensureVisibleForAssignee();

        $template = is_array($schedule->subtasks_template) ? $schedule->subtasks_template : [];
        foreach ($template as $idx => $title) {
            $title = trim((string) $title);
            if ($title === '') continue;
            TaskSubtask::create([
                'task_id'    => $task->id,
                'title'      => $title,
                'sort_order' => $idx + 1,
            ]);
        }

        return $task;
    }

    /**
     * Saat SO dibuat: loop semua rule type=order yang aktif. Untuk tiap rule yang
     * product-nya ada di SO items, bikin satu task ditujukan ke assignee rule.
     * Dedupe via taskable_type + taskable_id + source + rule (via title prefix).
     */
    public function createFromSalesOrder(SalesOrder $so): int
    {
        $so->loadMissing('items.product');
        if ($so->items->isEmpty()) return 0;

        $productIdsInSo = $so->items->pluck('product_id')->filter()->unique()->all();
        if (empty($productIdsInSo)) return 0;

        $rules = TaskAutomationRule::active()
            ->orderType()
            ->whereIn('product_id', $productIdsInSo)
            ->with(['product', 'assignee', 'category'])
            ->get();

        if ($rules->isEmpty()) return 0;

        $created = 0;
        foreach ($rules as $rule) {
            if (!$rule->assignee_user_id) continue;

            $existing = Task::where('taskable_type', SalesOrder::class)
                ->where('taskable_id', $so->id)
                ->where('source', 'event')
                ->where('assignee_user_id', $rule->assignee_user_id)
                ->where('category_id', $rule->category_id)
                ->first();
            if ($existing) continue;

            try {
                DB::transaction(function () use ($rule, $so, &$created) {
                    $matchingItems = $so->items->where('product_id', $rule->product_id);
                    $qty = $matchingItems->sum('qty');
                    $productName = $rule->product->name ?? '-';

                    $title = $this->renderTemplate(
                        $rule->title_template,
                        "Tindak lanjut SO {$so->order_number} — {$productName}",
                        ['so' => $so->order_number, 'product' => $productName, 'qty' => $qty]
                    );
                    $desc = $this->renderTemplate(
                        $rule->description_template,
                        "Pesanan masuk untuk produk: {$productName} (qty: {$qty})\nSO: {$so->order_number}",
                        ['so' => $so->order_number, 'product' => $productName, 'qty' => $qty]
                    );

                    $task = Task::create([
                        'title'            => $title,
                        'description'      => $desc,
                        'category_id'      => $rule->category_id,
                        'creator_user_id'  => $rule->assignee_user_id,
                        'assignee_user_id' => $rule->assignee_user_id,
                        'status'           => 'open',
                        'priority'         => $rule->priority ?? 'normal',
                        'taskable_type'    => SalesOrder::class,
                        'taskable_id'      => $so->id,
                        'source'           => 'event',
                        'position'         => $this->positions->nextPosition($rule->category_id),
                    ]);

                    $task->ensureVisibleForAssignee();

                    $rule->last_triggered_at = now();
                    $rule->save();
                    $created++;
                });
            } catch (\Throwable $e) {
                Log::warning('TaskAutomationService.createFromSalesOrder rule failed', [
                    'rule_id' => $rule->id,
                    'so_id'   => $so->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * Dipanggil StockMovementObserver setelah movement disimpan. Cek apakah produk
     * yang stoknya berubah masuk dalam rule type=stock & stok sekarang ≤ min_stock.
     * Bikin task hanya jika belum ada open task aktif untuk rule+product yang sama
     * (hindari spam tiap movement).
     */
    public function handleStockChange(int $productId): int
    {
        $rules = TaskAutomationRule::active()->stock()->where('product_id', $productId)->get();
        if ($rules->isEmpty()) return 0;

        $product = Product::find($productId);
        if (!$product) return 0;

        $minStock = (float) ($product->min_stock ?? 0);
        if ($minStock <= 0) return 0;

        // Stok fisik live = SUM qty_on_hand dari product_stocks (sumber yg sama dgn
        // halaman Inventory). Kolom products.stock tidak dipakai krn tidak ter-sync.
        $stock = (float) ProductStock::where('product_id', $productId)->sum('qty_on_hand');
        if ($stock > $minStock) return 0;

        return $this->createStockTasks($rules, $product, $stock, $minStock);
    }

    /**
     * Manual trigger: scan semua rule stok aktif, bikin task untuk produk yang
     * stoknya ≤ min_stock. Dipanggil dari tombol "Cek stok sekarang".
     */
    public function runStockCheck(): int
    {
        $rules = TaskAutomationRule::active()->stock()->with('product')->get();
        if ($rules->isEmpty()) return 0;
        $created = 0;

        $byProduct = $rules->groupBy('product_id');

        // Batch-load SUM(qty_on_hand) per produk supaya tidak N+1.
        $stockMap = ProductStock::whereIn('product_id', $byProduct->keys())
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(qty_on_hand) as total'))
            ->pluck('total', 'product_id');

        foreach ($byProduct as $productId => $productRules) {
            $product = $productRules->first()->product;
            if (!$product) continue;
            $minStock = (float) ($product->min_stock ?? 0);
            if ($minStock <= 0) continue;
            $stock = (float) ($stockMap[$productId] ?? 0);
            if ($stock > $minStock) continue;

            $created += $this->createStockTasks($productRules, $product, $stock, $minStock);
        }

        return $created;
    }

    /**
     * @param iterable<TaskAutomationRule> $rules
     */
    private function createStockTasks($rules, Product $product, float $stock, float $minStock): int
    {
        $created = 0;
        foreach ($rules as $rule) {
            if (!$rule->assignee_user_id) continue;

            $existing = Task::where('taskable_type', Product::class)
                ->where('taskable_id', $product->id)
                ->where('source', 'event')
                ->where('assignee_user_id', $rule->assignee_user_id)
                ->whereIn('status', ['open', 'in_progress'])
                ->first();
            if ($existing) continue;

            try {
                DB::transaction(function () use ($rule, $product, $stock, $minStock, &$created) {
                    $title = $this->renderTemplate(
                        $rule->title_template,
                        "Stok menipis — {$product->name}",
                        ['product' => $product->name, 'stock' => $stock, 'min' => $minStock]
                    );
                    $desc = $this->renderTemplate(
                        $rule->description_template,
                        "Stok {$product->name} saat ini {$stock} (minimum {$minStock}). Mohon segera ditindaklanjuti.",
                        ['product' => $product->name, 'stock' => $stock, 'min' => $minStock]
                    );

                    $task = Task::create([
                        'title'            => $title,
                        'description'      => $desc,
                        'category_id'      => $rule->category_id,
                        'creator_user_id'  => $rule->assignee_user_id,
                        'assignee_user_id' => $rule->assignee_user_id,
                        'status'           => 'open',
                        'priority'         => $rule->priority ?? 'normal',
                        'taskable_type'    => Product::class,
                        'taskable_id'      => $product->id,
                        'source'           => 'event',
                        'position'         => $this->positions->nextPosition($rule->category_id),
                    ]);

                    $task->ensureVisibleForAssignee();

                    $rule->last_triggered_at = now();
                    $rule->save();
                    $created++;
                });
            } catch (\Throwable $e) {
                Log::warning('TaskAutomationService.createStockTasks failed', [
                    'rule_id'    => $rule->id,
                    'product_id' => $product->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
        return $created;
    }

    private function renderTemplate(?string $tpl, string $fallback, array $vars): string
    {
        $tpl = trim((string) $tpl);
        if ($tpl === '') return $fallback;
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }
}
