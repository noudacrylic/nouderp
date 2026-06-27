<?php

namespace App\Modules\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Production\Services\BomScoreService;
use App\Core\Inventory\Warehouse;

class ProductionProcessController extends Controller
{
    public function index(Request $request)
    {
        $departmentId = $request->get('department_id');

        $base = fn() => ProductionOrderStep::with([
            'productionOrder.bom',
            'productionOrder.outputs.product',
            'productionOrder.materials',
            'productionOrder.mergedChildren',
            'productionOrder.salesOrder.items',
            'productionOrder.warehouse',
            'productionOrder.steps',
            'department.executors',
            'executor',
            'executors',
            'timeLogs',
        ])->whereHas('productionOrder', fn($q) =>
            $q->whereIn('status', ['confirmed', 'in_progress'])
        )->when($departmentId, fn($q) => $q->where('department_id', $departmentId));

        // Hanya tampilkan pending step yang step sebelumnya sudah selesai (atau step pertama).
        // Ini mencegah step berikutnya muncul di antrean sebelum gilirannya tiba.
        $pendingSteps = $base()->where('status', 'pending')->get()
            ->filter(function ($step) {
                $prevStep = $step->productionOrder->steps
                    ->where('step_number', $step->step_number - 1)
                    ->first();
                return $prevStep === null || $prevStep->status === 'completed';
            });

        // Recalculate skor BOM secara fresh setiap kali halaman dibuka agar urutan
        // antrean selalu mencerminkan kondisi stok terkini (stok habis → bobot naik).
        $scoreService = app(BomScoreService::class);
        $pendingSteps->pluck('productionOrder.bom')
            ->filter()
            ->unique('id')
            ->each(function ($bom) use ($scoreService) {
                $fresh = $scoreService->calculate($bom);
                $bom->update(['score' => $fresh]);
                $bom->score = $fresh;
            });

        $pendingSteps = $pendingSteps
            ->sort(function ($a, $b) {
                $scoreA = $a->productionOrder->effective_score;
                $scoreB = $b->productionOrder->effective_score;
                if ($scoreA !== $scoreB) {
                    return $scoreB <=> $scoreA; // Skor lebih tinggi di atas
                }
                // Skor sama: order yang lebih lama (created_at lebih awal) mendapat prioritas
                return $a->productionOrder->created_at <=> $b->productionOrder->created_at;
            })
            ->values();

        $inProgressSteps = $base()->where('status', 'in_progress')
            ->orderBy('started_at')->get();

        $pausedSteps = $base()->where('status', 'paused')
            ->orderBy('paused_at')->get();

        // Tandai tiap kartu dengan info penggabungan (checkbox muncul bila eligible).
        $svc = app(ProductionOrderService::class);
        foreach ([$pendingSteps, $inProgressSteps, $pausedSteps] as $coll) {
            foreach ($coll as $step) {
                $o = $step->productionOrder;
                if (!$o) {
                    continue;
                }
                $eligible = $svc->isMergeEligible($o);
                $o->merge_eligible = $eligible;
                $o->merge_sig  = $eligible ? $svc->componentSignature($o) : null;
                $o->merge_step = $eligible ? $svc->activeStepNumber($o) : null;
            }
        }

        $selectedDepartment = $departmentId ? Department::find($departmentId) : null;

        // Executor yang sedang ada di task in_progress (via pivot) tidak boleh ambil task baru.
        $busyExecutorIds = DB::table('production_order_step_executors')
            ->join('production_order_steps', 'production_order_steps.id', '=', 'production_order_step_executors.step_id')
            ->where('production_order_steps.status', 'in_progress')
            ->pluck('production_order_step_executors.executor_id')
            ->unique()
            ->values()
            ->toArray();

        $testingMode = \App\Models\ProductionSetting::isTestingMode();

        // Daftar gudang untuk alokasi hasil produksi saat finalisasi langkah terakhir.
        $warehouses         = Warehouse::orderBy('name')->get(['id', 'name']);
        $defaultWarehouseId = Warehouse::defaultId();

        return view('erp.production.process.index', compact(
            'pendingSteps', 'inProgressSteps', 'pausedSteps', 'selectedDepartment', 'busyExecutorIds', 'testingMode',
            'warehouses', 'defaultWarehouseId'
        ));
    }

    public function queueScores(Request $request)
    {
        $departmentId = $request->get('department_id');
        $scoreService = app(BomScoreService::class);

        $steps = ProductionOrderStep::with([
            'productionOrder.bom',
            'productionOrder.outputs.product',
            'productionOrder.steps',
        ])->whereHas('productionOrder', fn($q) =>
            $q->whereIn('status', ['confirmed', 'in_progress'])
        )->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
         ->where('status', 'pending')
         ->get()
         ->filter(function ($step) {
             $prev = $step->productionOrder->steps
                 ->where('step_number', $step->step_number - 1)->first();
             return $prev === null || $prev->status === 'completed';
         });

        $steps->pluck('productionOrder.bom')->filter()->unique('id')
            ->each(function ($bom) use ($scoreService) {
                $fresh = $scoreService->calculate($bom);
                $bom->update(['score' => $fresh]);
                $bom->score = $fresh;
            });

        $ordered = $steps
            ->sort(function ($a, $b) {
                $scoreA = $a->productionOrder->effective_score;
                $scoreB = $b->productionOrder->effective_score;
                if ($scoreA !== $scoreB) {
                    return $scoreB <=> $scoreA;
                }
                return $a->productionOrder->created_at <=> $b->productionOrder->created_at;
            })
            ->values()
            ->map(fn($s) => [
                'id'    => $s->id,
                'score' => round($s->productionOrder->effective_score, 2),
            ]);

        return response()->json($ordered);
    }

    /**
     * Endpoint untuk resync timer di kartu Proses Produksi.
     * Dipanggil tiap 30 menit oleh JS — JS sendiri tick visual tiap 1 detik,
     * tapi nilai server adalah sumber kebenaran (dihitung dari time logs + now()).
     */
    public function stepTimers(Request $request)
    {
        $departmentId = $request->get('department_id');

        $steps = ProductionOrderStep::with(['timeLogs'])
            ->whereIn('status', ['in_progress', 'paused'])
            ->whereHas('productionOrder', fn($q) => $q->whereIn('status', ['confirmed', 'in_progress']))
            ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
            ->get();

        return response()->json($steps->map(fn($s) => [
            'step_id'         => $s->id,
            'state'           => $s->status,
            'elapsed_seconds' => (int) $s->elapsed_working_seconds,
        ])->values());
    }

    public function startStep(Request $request, int $id, ProductionOrderService $service)
    {
        $request->validate([
            'executor_ids'   => 'required|array|min:1',
            'executor_ids.*' => 'exists:production_department_executors,id',
            'force'          => 'nullable|boolean',
        ]);

        try {
            $force = (bool) $request->input('force', false);
            $service->startStep($id, $request->executor_ids, $force);
            return back()->with('success', $force ? 'Langkah dimulai (override manual).' : 'Langkah dimulai.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function revertStep(int $id, ProductionOrderService $service)
    {
        try {
            $service->revertToPreviousStep($id);
            return back()->with('success', 'Dikembalikan ke langkah sebelumnya. Langkah tersebut kini muncul lagi di antrean divisinya.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function pauseStep(int $id, ProductionOrderService $service)
    {
        try {
            $service->pauseStep($id);
            return back()->with('success', 'Langkah dipending.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function resumeStep(int $id, ProductionOrderService $service)
    {
        try {
            $service->resumeStep($id);
            return back()->with('success', 'Langkah dilanjutkan kembali.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function completeStep(Request $request, int $id, ProductionOrderService $service)
    {
        $request->validate([
            'notes'                       => 'nullable|string|max:1000',
            'outputs'                     => 'nullable|array',
            'outputs.*.output_id'         => 'required_with:outputs|integer',
            'outputs.*.qty_produced'      => 'required_with:outputs|numeric|min:0',
            'outputs.*.percentage'        => 'nullable|numeric|min:0|max:100',
            'outputs.*.variance_notes'    => 'nullable|string|max:500',
            'outputs.*.allocations'                => 'nullable|array',
            'outputs.*.allocations.*.warehouse_id' => 'required_with:outputs.*.allocations|integer|exists:warehouses,id',
            'outputs.*.allocations.*.qty'          => 'required_with:outputs.*.allocations|numeric|min:0',
        ]);

        try {
            $orderCompleted = $service->completeStep(
                $id,
                null,
                $request->notes,
                $request->input('outputs', []),
            );

            if (!$orderCompleted) {
                return back()->with('success', 'Langkah selesai. Langkah berikutnya masuk antre divisi berikutnya.');
            }

            // Order selesai semua langkah → langsung auto-finalize:
            // konsumsi material yang tertunda, output masuk stok via FIFO, jurnal Dr Persediaan / Cr WIP.
            // Operator tidak perlu menunggu admin konfirmasi terpisah.
            $step    = \App\Modules\Production\Models\ProductionOrderStep::findOrFail($id);
            $orderId = (int) $step->production_order_id;

            try {
                $service->finalize($orderId, $request->input('outputs', []));

                return redirect(list_url('production.process.index'))
                    ->with('success', 'Produksi selesai & output masuk stok. Jurnal WIP → Persediaan dicatat.');
            } catch (\Throwable $e) {
                // Finalize gagal (mis. stok material kurang). Status order sudah otomatis 'pending'
                // (di-set di pre-flight finalize). Admin/owner bisa retry via Finalisasi → Coba Lagi.
                return redirect(list_url('production.process.index'))
                    ->with('error', 'Hasil produksi tercatat, tapi belum bisa masuk stok: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Update catatan order produksi langsung dari kartu Proses Produksi.
     * Catatan ini dipakai untuk info penting eksekusi (mis. "diambil Sabtu sore"),
     * sehingga sengaja tetap bisa diedit walau order sudah diposting/dikerjakan.
     */
    public function updateNotes(Request $request, int $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $order = \App\Modules\Production\Models\ProductionOrder::findOrFail($id);
        $order->update(['notes' => $request->input('notes')]);

        return back()->with('success', 'Catatan order produksi diperbarui.');
    }

    /**
     * Gabungkan 2+ task produksi menjadi satu (task lain diserap ke task induk).
     */
    public function mergeOrders(Request $request, ProductionOrderService $service)
    {
        $request->validate([
            'ids'   => 'required|array|min:2',
            'ids.*' => 'integer|exists:production_orders,id',
        ]);

        try {
            $induk = $service->mergeOrders($request->input('ids'));
            $n = count($request->input('ids'));
            return back()->with('success', "{$n} task berhasil digabung ke {$induk->order_number}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
