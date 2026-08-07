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
use App\Modules\Production\Services\ProductionOrderService;

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
        // Filter divisi: hanya divisi produksi yang aktif (penambahan bahan = konteks produksi).
        $departments  = Department::where('type', 'produksi')
            ->where('is_active', true)
            ->orderBy('name')->get();

        // Tampilkan order steps yang in_progress atau paused (bukan antre murni)
        $activeSteps = ProductionOrderStep::with([
                'productionOrder.outputs.product',
                'department',
                'executors',
            ])
            ->whereIn('status', ['in_progress', 'paused'])
            ->whereHas('productionOrder', fn($q) => $q->whereIn('status', ['in_progress', 'partial']))
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
            ->whereHas('productionOrder', fn($q) => $q->whereIn('status', ['in_progress', 'partial']))
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

        // Arahkan kembali ke posisi task di Proses Produksi (divisi task tsb) — TAPI hanya bila
        // penggunanya memang boleh membuka papan divisi itu. Operator divisi lain (mis. Assembling
        // mencatat bahan untuk langkah CNC) akan ditolak middleware di halaman tujuan, dan
        // penyimpanan yang sebenarnya sudah berhasil jadi terlihat seperti gagal.
        return redirect($this->bolehBukaProses($redirectDeptId)
                ? route('production.process.index', array_filter(['department_id' => $redirectDeptId]))
                : route('production.material-additions.index'))
            ->with('success', 'Penambahan bahan berhasil dicatat dan stok telah dikurangi.');
    }

    /**
     * Pengguna saat ini boleh membuka papan Proses Produksi divisi ini? Aturannya disamakan
     * dengan EnsureMenuAccess: admin bebas, user divisi hanya papan divisinya sendiri.
     */
    private function bolehBukaProses(?int $departmentId): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return app(\App\Services\MenuRegistry::class)
            ->userCanAccessProcessRoute($departmentId, fn ($key) => $user->hasMenuPermission($key));
    }

    /**
     * Void penambahan bahan: kembalikan stok ke gudang & balik jurnal WIP.
     * Hanya boleh selagi order masih dikerjakan (in_progress) — setelah selesai/finalisasi
     * biaya bahan sudah masuk barang jadi sehingga tidak bisa dibatalkan dari sini.
     */
    public function void($id)
    {
        $addition = ProductionMaterialAddition::with(['items', 'costs', 'productionOrder'])->findOrFail($id);

        if ($addition->isVoided()) {
            return back()->with('error', 'Penambahan bahan ini sudah dibatalkan.');
        }

        $order = $addition->productionOrder;
        if (!$order || !in_array($order->status, ['in_progress', 'partial'], true)) {
            return back()->with('error', 'Tidak bisa membatalkan: pengerjaan produksi sudah selesai atau difinalisasi. Void hanya bisa selagi order masih dikerjakan.');
        }

        // Order yang hasilnya sudah diambil sebagian: biaya penambahan ini mungkin sudah
        // ikut terlepas ke stok. Menariknya kembali akan membuat WIP minus, jadi ditolak —
        // batalkan pengambilan terakhir dulu baru koreksi bahannya.
        if ($order->status === 'partial') {
            $wipAccountId = Account::where('code', AccountCodeEnum::WIP)->value('id');

            $additionWip = (float) \App\Core\Journal\JournalLine::where('account_id', $wipAccountId)
                ->whereHas('journal', fn($q) => $q
                    ->whereIn('reference_type', ['production_material_addition', 'production_cost_addition'])
                    ->where('reference_id', $addition->id)
                    ->where('status', '!=', 'void'))
                ->sum('debit');

            $wip = app(ProductionOrderService::class)->wipSummary($order->id);

            if ($additionWip > $wip['remaining'] + 0.01) {
                return back()->with('error',
                    'Tidak bisa membatalkan: biaya penambahan bahan ini sudah ikut terlepas ke stok lewat pengambilan hasil. ' .
                    'Batalkan pengambilan terakhir dulu di halaman order produksi.');
            }
        }

        DB::transaction(function () use ($addition, $order) {
            $fifo   = app(FifoService::class);
            $engine = app(InventoryEngine::class);
            $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
            $inventoryAccount = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();

            $totalMaterialCost = 0.0;

            foreach ($addition->items as $item) {
                // Biaya FIFO yang dulu dikonsumsi untuk item ini (dari cost-layer qty_out).
                $consumed = \App\Models\InventoryCostLayer::where('reference_type', 'production_material_addition')
                    ->where('reference_id', $addition->id)
                    ->where('product_id', $item->product_id)
                    ->get();
                $qtyOut  = (float) $consumed->sum('qty_out');
                $costOut = (float) $consumed->sum(fn($c) => (float) $c->qty_out * (float) $c->unit_cost);
                $restoreQty = $qtyOut > 0 ? $qtyOut : (float) $item->qty_requested;
                $unitCost   = $qtyOut > 0 ? ($costOut / $qtyOut) : 0.0;

                // Kembalikan stok: ledger qty_in + layer FIFO baru pada biaya konsumsi semula.
                $engine->ledger(
                    productId: $item->product_id,
                    warehouseId: $order->warehouse_id,
                    qtyIn: $restoreQty,
                    qtyOut: 0,
                    type: 'production_material_addition_void',
                    reference: $addition->addition_number,
                    notes: null,
                    transactionId: $addition->id
                );
                $fifo->createLayer($item->product_id, $order->warehouse_id, $restoreQty, $unitCost, 'production_material_addition_void', $addition->id);

                $totalMaterialCost += $costOut;
            }

            // Jurnal balik bahan: Dr. Persediaan / Cr. WIP
            if ($totalMaterialCost > 0) {
                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:           now()->format('Y-m-d'),
                    reference_type: 'production_material_addition_void',
                    reference_id:   $addition->id,
                    description:    "Batal Tambah Bahan - {$addition->addition_number} ({$order->order_number})",
                    lines: [
                        new JournalLineDTO(
                            account_id:  $inventoryAccount->id,
                            debit:       (float) $totalMaterialCost,
                            credit:      0,
                            description: 'Balik persediaan dari batal tambah bahan'
                        ),
                        new JournalLineDTO(
                            account_id:  $wipAccount->id,
                            debit:       0,
                            credit:      (float) $totalMaterialCost,
                            description: 'Balik WIP dari batal tambah bahan'
                        ),
                    ],
                    reference_number: $addition->addition_number
                ));
            }

            // Jurnal balik biaya kas (jika ada): Dr. Kas / Cr. WIP
            $costs = $addition->costs;
            if ($costs->isNotEmpty()) {
                $costTotal = 0.0;
                $lines = [];
                foreach ($costs->groupBy('cash_account_id') as $cashAccId => $items) {
                    $subtotal   = (float) $items->sum('amount');
                    $costTotal += $subtotal;
                    $lines[] = new JournalLineDTO(
                        account_id:  (int) $cashAccId,
                        debit:       (float) $subtotal,
                        credit:      0,
                        description: 'Balik kas dari batal biaya produksi'
                    );
                }
                $lines[] = new JournalLineDTO(
                    account_id:  $wipAccount->id,
                    debit:       0,
                    credit:      (float) $costTotal,
                    description: 'Balik WIP dari batal biaya produksi'
                );

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:             now()->format('Y-m-d'),
                    reference_type:   'production_cost_addition_void',
                    reference_id:     $addition->id,
                    description:      "Batal Biaya Produksi - {$addition->addition_number} ({$order->order_number})",
                    lines:            $lines,
                    reference_number: $addition->addition_number
                ));
            }

            $addition->update(['voided_at' => now()]);
        });

        return back()->with('success', 'Penambahan bahan dibatalkan. Stok dikembalikan & jurnal WIP dibalik.');
    }
}
