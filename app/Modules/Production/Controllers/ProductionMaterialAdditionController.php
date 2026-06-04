<?php

namespace App\Modules\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionMaterialAddition;
use App\Modules\Production\Models\ProductionMaterialAdditionItem;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\InventoryEngine;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Enums\AccountCodeEnum;
use App\Core\Accounting\Account;
use App\Services\NumberGeneratorService;
use App\Modules\Production\Models\ProductionOrderCost;

class ProductionMaterialAdditionController extends Controller
{
    public function index(Request $request)
    {
        $q        = trim((string) $request->get('q', ''));
        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');

        $additions = ProductionMaterialAddition::with(['productionOrder', 'step', 'items.product'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('addition_number', 'LIKE', "%{$q}%")
                      ->orWhereHas('productionOrder', fn($po) => $po->where('order_number', 'LIKE', "%{$q}%"));
                });
            })
            ->when($dateFrom, fn($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo,   fn($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('erp.production.material-additions.index', compact('additions'));
    }

    public function create(Request $request)
    {
        $departmentId      = $request->get('department_id');
        $preSelectedStepId = $request->get('step_id');
        $departments  = Department::orderBy('name')->get();

        // Tampilkan order steps yang in_progress atau paused (bukan antre murni)
        $activeSteps = ProductionOrderStep::with([
                'productionOrder.outputs.product',
                'department',
                'executors',
            ])
            ->whereIn('status', ['in_progress', 'paused'])
            ->whereHas('productionOrder', fn($q) => $q->whereIn('status', ['in_progress']))
            ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
            ->orderBy('started_at', 'desc')
            ->get();

        // Juga sertakan order yang confirmed dan punya step pending (sedang antre aktif)
        // namun sudah ada step sebelumnya yang selesai
        $pendingActiveSteps = ProductionOrderStep::with([
                'productionOrder.outputs.product',
                'department',
            ])
            ->where('status', 'pending')
            ->whereHas('productionOrder', fn($q) => $q->where('status', 'in_progress'))
            ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
            ->get()
            ->filter(function ($step) {
                // Hanya tampilkan jika ada step sebelumnya yang sudah selesai
                $prevDone = $step->productionOrder->steps()
                    ->where('step_number', '<', $step->step_number)
                    ->where('status', 'completed')
                    ->exists();
                return $prevDone;
            });

        $activeSteps = $activeSteps->concat($pendingActiveSteps)->unique('id')->values();

        $cashAccounts = Account::where(function ($q) {
            $q->where('is_cash_account', true)
              ->orWhereIn('account_category', ['cash', 'cash_equivalent']);
        })->where('is_active', true)->orderBy('code')->get();

        return view('erp.production.material-additions.create', compact(
            'departments', 'activeSteps', 'departmentId', 'cashAccounts', 'preSelectedStepId'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'production_order_step_id' => 'required|exists:production_order_steps,id',
            'notes'                    => 'nullable|string|max:1000',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.qty_requested'    => 'required|numeric|min:0.0001',
            'items.*.unit'             => 'nullable|string|max:50',
            'items.*.notes'            => 'nullable|string|max:500',
            'costs'                    => 'nullable|array',
            'costs.*.description'      => 'required_with:costs.*.amount|string|max:255',
            'costs.*.amount'           => 'required_with:costs.*.description|numeric|min:0.01',
            'costs.*.cash_account_id'  => 'required_with:costs.*.description|exists:accounts,id',
        ]);

        // Divisi task — untuk redirect kembali ke posisi task di Proses Produksi
        $redirectDeptId = ProductionOrderStep::where('id', $request->production_order_step_id)->value('department_id');

        DB::transaction(function () use ($request) {
            $step  = ProductionOrderStep::with('productionOrder')->findOrFail($request->production_order_step_id);
            $order = $step->productionOrder;

            $addition = ProductionMaterialAddition::create([
                'production_order_id'      => $order->id,
                'production_order_step_id' => $step->id,
                'addition_number'          => NumberGeneratorService::generate('MAT'),
                'notes'                    => $request->notes,
            ]);

            $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
            $inventoryAccount = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();
            $fifo = app(FifoService::class);
            $engine = app(InventoryEngine::class);
            $totalCost = 0;

            foreach ($request->items as $item) {
                ProductionMaterialAdditionItem::create([
                    'addition_id'   => $addition->id,
                    'product_id'    => $item['product_id'],
                    'qty_requested' => $item['qty_requested'],
                    'unit'          => $item['unit'] ?? null,
                    'notes'         => $item['notes'] ?? null,
                ]);

                // Catat stock-out di inventory_ledgers (FifoService::consume tidak menyentuh ledger).
                $engine->ledger(
                    productId: $item['product_id'],
                    warehouseId: $order->warehouse_id,
                    qtyIn: 0,
                    qtyOut: (float) $item['qty_requested'],
                    type: 'production_material_addition',
                    reference: $addition->addition_number,
                    notes: null,
                    transactionId: $addition->id
                );

                // Consume dari stok → masuk WIP (sama seperti konfirmasi order)
                $cogs = $fifo->consume(
                    $item['product_id'],
                    $order->warehouse_id,
                    $item['qty_requested'],
                    'production_material_addition',
                    $addition->id
                );
                $totalCost += $cogs;
            }

            // Jurnal: Dr. WIP / Cr. Persediaan
            if ($totalCost > 0) {
                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date: now()->format('Y-m-d'),
                    reference_type: 'production_material_addition',
                    reference_id: $addition->id,
                    description: "Tambah Bahan - {$addition->addition_number} ({$order->order_number})",
                    lines: [
                        new JournalLineDTO(
                            account_id:  $wipAccount->id,
                            debit:       (float) $totalCost,
                            credit:      0,
                            description: 'Tambahan material masuk WIP'
                        ),
                        new JournalLineDTO(
                            account_id:  $inventoryAccount->id,
                            debit:       0,
                            credit:      (float) $totalCost,
                            description: 'Pengeluaran tambahan bahan'
                        ),
                    ],
                    reference_number: $addition->addition_number
                ));
            }

            // Simpan biaya & jurnal Dr.WIP / Cr.Kas
            $costItems = collect($request->costs ?? [])
                ->filter(fn($c) => !empty($c['description']) && !empty($c['amount']) && !empty($c['cash_account_id']));

            if ($costItems->isNotEmpty()) {
                $costTotal = 0;
                $costLines = [];

                foreach ($costItems->groupBy('cash_account_id') as $cashAccId => $items) {
                    $subtotal  = $items->sum('amount');
                    $costTotal += $subtotal;
                    $cashAcc   = Account::findOrFail($cashAccId);
                    $costLines[] = new JournalLineDTO(
                        account_id:  $cashAcc->id,
                        debit:       0,
                        credit:      (float) $subtotal,
                        description: 'Kas keluar biaya produksi'
                    );
                }

                $costLines[] = new JournalLineDTO(
                    account_id:  $wipAccount->id,
                    debit:       (float) $costTotal,
                    credit:      0,
                    description: 'Biaya produksi masuk WIP'
                );

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:             now()->format('Y-m-d'),
                    reference_type:   'production_cost_addition',
                    reference_id:     $addition->id,
                    description:      "Biaya Produksi - {$addition->addition_number} ({$order->order_number})",
                    lines:            $costLines,
                    reference_number: $addition->addition_number
                ));

                foreach ($costItems as $c) {
                    ProductionOrderCost::create([
                        'production_order_id' => $order->id,
                        'material_addition_id' => $addition->id,
                        'description'          => $c['description'],
                        'amount'               => $c['amount'],
                        'cash_account_id'      => $c['cash_account_id'],
                    ]);
                }
            }
        });

        // Arahkan kembali ke posisi task di Proses Produksi (divisi task tsb)
        return redirect(route('production.process.index', array_filter(['department_id' => $redirectDeptId])))
            ->with('success', 'Penambahan bahan berhasil dicatat dan stok telah dikurangi.');
    }
}
