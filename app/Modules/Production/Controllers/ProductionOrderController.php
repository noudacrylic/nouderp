<?php

namespace App\Modules\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Models\SalesInvoice;
use App\Models\WarrantyOrder;
use App\Models\InventoryAdjustment;
use App\Core\Inventory\Warehouse;
use App\Core\Accounting\Account;

class ProductionOrderController extends Controller
{
    public function index(Request $request)
    {
        $q        = trim((string) $request->get('q', ''));
        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');
        $status   = $request->get('status');
        $type     = $request->get('type');

        $orders = ProductionOrder::with(['bom', 'salesOrder', 'warehouse', 'steps.department'])
            ->whereNotIn('status', ['cancelled'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('order_number', 'LIKE', "%{$q}%")
                      ->orWhereHas('bom', fn($b) => $b->where('name', 'LIKE', "%{$q}%")
                                                      ->orWhere('bom_number', 'LIKE', "%{$q}%"))
                      ->orWhereHas('salesOrder', fn($s) => $s->where('order_number', 'LIKE', "%{$q}%"));
                });
            })
            ->when($dateFrom, fn($query) => $query->whereDate('production_date', '>=', $dateFrom))
            ->when($dateTo,   fn($query) => $query->whereDate('production_date', '<=', $dateTo))
            ->when($status,   fn($query) => $query->where('status', $status))
            ->when($type,     fn($query) => $query->where('type', $type))
            ->latest('production_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('erp.production.orders.index', compact('orders'));
    }

    public function create(Request $request)
    {
        $boms       = Bom::orderByDesc('score')->get();
        $warehouses = Warehouse::orderBy('name')->get();
        $defaultWarehouseId = $warehouses->first(fn($w) => stripos($w->name, 'utama') !== false)?->id
            ?? $warehouses->where('is_active', 1)->first()?->id
            ?? $warehouses->first()?->id;

        // OP perbaikan dari garansi/retur → kunci gudang ke gudang sumbernya, supaya output
        // perbaikan & SJ berada di gudang yang sama dengan barang yang diterima (tidak ada stok phantom).
        $lockWarehouse = false;
        if ($request->type === 'repair' && $request->repair_source_id) {
            $srcWarehouseId = $this->repairSourceWarehouseId($request->repair_source_type, (int) $request->repair_source_id);
            if ($srcWarehouseId) {
                $defaultWarehouseId = $srcWarehouseId;
                $lockWarehouse = true;
            }
        }
        $salesOrders = SalesOrder::where('status', 'confirmed')
            ->with('customer')
            ->latest('order_date')
            ->get();
        $departments  = Department::produksi()->where('is_active', true)->orderBy('name')->get();
        $cashAccounts = Account::where(function ($q) {
            $q->where('is_cash_account', true)
              ->orWhereIn('account_category', ['cash', 'cash_equivalent']);
        })->where('is_active', true)->orderBy('code')->get();

        // Master bahan baku (lembaran) + ukuran PxL + harga terbaru — untuk Kalkulator Produk Custom.
        $rawMaterials = \App\Modules\Production\Models\ProductionRawMaterial::with('product')->get();
        $rawMaterialOptions = $rawMaterials->map(fn($m) => [
            'id'        => $m->product_id,
            'sku'       => $m->product?->sku ?? '',
            'label'     => ($m->product?->sku ? $m->product->sku . ' - ' : '') . ($m->product?->name ?? '—'),
            'base_unit' => $m->product?->base_unit ?? '',
            'panjang'   => (float) $m->panjang,
            'lebar'     => (float) $m->lebar,
            'last_cost' => (float) ($m->product?->last_cost ?: $m->product?->cost_price ?: 0),
        ])->values();

        // Index area lembar per produk bahan baku, untuk hitung harga-per-luas produk sampingan.
        $rmByProduct = $rawMaterials->keyBy('product_id');

        $byproductOptions = \App\Modules\Production\Models\ProductionByproduct::with(['product', 'rawMaterial'])->get()->map(function ($b) use ($rmByProduct) {
            $rm       = $b->raw_material_product_id ? $rmByProduct->get($b->raw_material_product_id) : null;
            $rmArea   = $rm ? ((float) $rm->panjang * (float) $rm->lebar) : 0.0;
            $rmCost   = (float) ($b->rawMaterial?->last_cost ?: $b->rawMaterial?->cost_price ?: 0);
            return [
                'id'              => $b->product_id,
                'label'           => ($b->product?->sku ? $b->product->sku . ' - ' : '') . ($b->product?->name ?? '—'),
                // null = belum diatur → di OP wajib diisi manual (tidak otomatis/terkunci).
                'unit_percentage' => $b->percentage === null ? null : (float) $b->percentage,
                'panjang'         => (float) $b->panjang,        // PxL kebutuhan produk sampingan
                'lebar'           => (float) $b->lebar,
                'rm_area'         => $rmArea,                    // luas lembar bahan baku sampingan
                'rm_last_cost'    => $rmCost,                    // harga lembar bahan baku sampingan
            ];
        })->values();

        return view('erp.production.orders.create', compact('boms', 'warehouses', 'defaultWarehouseId', 'salesOrders', 'departments', 'cashAccounts', 'lockWarehouse', 'byproductOptions', 'rawMaterialOptions'));
    }

    /**
     * Gudang dokumen sumber perbaikan (garansi/retur), agar OP perbaikan mengikuti
     * gudang barang yang diterima.
     */
    private function repairSourceWarehouseId(?string $sourceType, int $sourceId): ?int
    {
        return match ($sourceType) {
            'warranty' => WarrantyOrder::find($sourceId)?->warehouse_id,
            'return'   => SalesReturn::find($sourceId)?->warehouse_id,
            default    => null,
        };
    }

    public function store(Request $request, ProductionOrderService $service)
    {
        $isRepair = $request->type === 'repair';

        $rules = [
            'type'            => 'required|in:ready_stock,custom,repair',
            'warehouse_id'    => 'required|exists:warehouses,id',
            'production_date' => 'required|date',
            'planned_cycles'  => 'nullable|numeric|min:0.0001',
            'score_type'     => 'nullable|in:auto,priority',
            'priority_level' => 'required_if:score_type,priority|nullable|in:low,medium,high,very_high,urgent',
            'images'          => 'nullable|array|max:5',
            'images.*'        => 'image|max:5120',
            'costs'                   => 'nullable|array',
            // Baris biaya dianggap "aktif" jika ada nominalnya. Baris tanpa nominal diabaikan.
            'costs.*.amount'          => 'nullable|numeric|min:0.01',
            'costs.*.description'     => 'nullable|required_with:costs.*.amount|string|max:255',
            'costs.*.cash_account_id' => 'nullable|required_with:costs.*.amount|exists:accounts,id',
        ];

        if ($request->type === 'custom') {
            $rules['sales_order_id'] = 'required|exists:sales_orders,id';
        }

        if ($isRepair) {
            $rules['repair_source_id']             = 'required|integer';
            $rules['repair_items']                 = 'required|array|min:1';
            $rules['repair_items.*.product_id']    = 'required|exists:products,id';
            $rules['repair_items.*.qty']           = 'required|numeric|min:0.0001';
            $rules['repair_items.*.output_type']   = 'required|in:main,by_product';
            $rules['repair_items.*.percentage']    = 'required|numeric|min:0|max:100';
        } else {
            $rules['materials']                = 'required|array|min:1';
            $rules['materials.*.product_id']   = 'required|exists:products,id';
            $rules['materials.*.qty_required'] = 'required|numeric|min:0.0001';
            $rules['outputs']                  = 'required|array|min:1';
            $rules['outputs.*.product_id']     = 'required|exists:products,id';
            $rules['outputs.*.qty_planned']    = 'required|numeric|min:0.0001';
            $rules['outputs.*.output_type']    = 'required|in:main,by_product';
            $rules['outputs.*.percentage']     = 'required|numeric|min:0|max:100';
        }

        $messages = [
            'costs.*.cash_account_id.required_with' => 'Pilih Kas/Bank untuk baris Biaya Produksi yang ada nominalnya — atau hapus baris biaya tersebut.',
            'costs.*.description.required_with'     => 'Isi keterangan untuk baris Biaya Produksi yang ada nominalnya.',
            'outputs.*.product_id.required'         => 'Pilih produk untuk setiap baris Output.',
            'outputs.*.qty_planned.required'        => 'Isi qty untuk setiap baris Output.',
            'outputs.*.percentage.required'         => 'Isi persentase untuk setiap baris Output.',
            'materials.*.product_id.required'       => 'Pilih produk untuk setiap baris Material.',
            'materials.*.qty_required.required'     => 'Isi qty untuk setiap baris Material.',
            'materials.required'                    => 'Minimal harus ada 1 material.',
            'outputs.required'                      => 'Minimal harus ada 1 output.',
        ];

        $request->validate($rules, $messages);

        // OP perbaikan dari garansi/retur WAJIB segudang dengan dokumen sumbernya
        // (output perbaikan & SJ harus di gudang yang sama → tidak ada stok phantom).
        if ($isRepair && $request->repair_source_id) {
            $srcWh = $this->repairSourceWarehouseId($request->repair_source_type, (int) $request->repair_source_id);
            if ($srcWh) {
                $request->merge(['warehouse_id' => $srcWh]);
            }
        }

        // Tepat 1 produk Utama; sisanya wajib Sampingan. Total persentase wajib 100%.
        if (!$isRepair) {
            $outputs = collect($request->input('outputs', []));

            $mainCount = $outputs->filter(fn($o) => ($o['output_type'] ?? null) === 'main')->count();
            if ($mainCount !== 1) {
                return back()->withInput()
                    ->with('error', 'Output produksi harus memiliki tepat 1 produk Utama. Produk lainnya berstatus Sampingan.');
            }

            $pctSum = $outputs->sum(fn($o) => (float) ($o['percentage'] ?? 0));
            if (abs($pctSum - 100) > 0.01) {
                $shown = rtrim(rtrim(number_format($pctSum, 2, '.', ''), '0'), '.');
                return back()->withInput()
                    ->with('error', "Total persentase output harus 100%. Saat ini: {$shown}%.");
            }

            // Produk tidak boleh jadi bahan baku sekaligus output (produksi sirkular).
            $materialIds = collect($request->input('materials', []))->pluck('product_id')->filter()->map(fn($id) => (int) $id);
            $outputIds   = $outputs->pluck('product_id')->filter()->map(fn($id) => (int) $id);
            $overlap = $materialIds->intersect($outputIds)->unique()->values();
            if ($overlap->isNotEmpty()) {
                $names = \App\Core\Inventory\Product::whereIn('id', $overlap)->get()
                    ->map(fn($p) => trim(($p->sku ? $p->sku . ' - ' : '') . $p->name))->implode(', ');
                return back()->withInput()
                    ->with('error', "Produk tidak boleh menjadi bahan baku sekaligus output dalam satu produksi: {$names}.");
            }
        }

        if ($request->type === 'custom' && $request->filled('sales_order_id')) {
            $overError = $this->validateSoOutputLimits($request);
            if ($overError) {
                return back()->with('error', $overError)->withInput();
            }
        }

        try {
            $data = $request->all();

            if ($request->hasFile('images')) {
                $data['image_paths'] = collect($request->file('images'))
                    ->map(fn($f) => $f->store('production-images', 'public'))
                    ->values()
                    ->toArray();
            }

            $order = $service->create($data);
            return redirect()->route('production.orders.show', $order->id)
                ->with('success', "Order produksi {$order->order_number} berhasil dibuat.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(int $id)
    {
        $order = ProductionOrder::with([
            'bom', 'salesOrder.customer', 'warehouse',
            'materials.product', 'outputs.product',
            'steps.department', 'steps.executor', 'steps.executors', 'steps.timeLogs',
            'sources.product',
            'costs.cashAccount',
        ])->findOrFail($id);

        $departments = Department::produksi()->where('is_active', true)->with('activeExecutors')->get();

        // Map stok tersedia per material di gudang order — buat audit "kurang berapa"
        // di tabel Material. Hanya relevan untuk material yang belum dikonsumsi.
        $engine = app(\App\Core\Inventory\InventoryEngine::class);
        $materialStock = [];
        foreach ($order->materials as $m) {
            $materialStock[$m->product_id] = (float) $engine->availableStock(
                (int) $m->product_id,
                (int) $order->warehouse_id
            );
        }

        // Dokumen sumber untuk order Perbaikan (garansi / retur / stok rusak).
        // repair_source_type menyimpan nilai mentah: 'warranty' | 'return' | 'adjustment'.
        // repair_source_ref sudah berisi nomor dokumennya, repair_source_id = FK ke dokumen.
        $repairSource = null;
        if ($order->type === 'repair' && $order->repair_source_type) {
            $repairSource = match ($order->repair_source_type) {
                'warranty' => [
                    'label'  => 'Garansi',
                    'number' => $order->repair_source_ref,
                    'url'    => ($order->repair_source_id && \Route::has('sales.warranty.show'))
                                ? route('sales.warranty.show', $order->repair_source_id) : null,
                ],
                'return' => [
                    'label'  => 'Retur',
                    'number' => $order->repair_source_ref,
                    'url'    => ($order->repair_source_id && \Route::has('sales.returns.show'))
                                ? route('sales.returns.show', $order->repair_source_id) : null,
                ],
                'adjustment' => [
                    'label'  => 'Stok Rusak',
                    'number' => $order->repair_source_ref,
                    'url'    => null,
                ],
                default => $order->repair_source_ref
                    ? ['label' => 'Sumber', 'number' => $order->repair_source_ref, 'url' => null]
                    : null,
            };
        }

        return view('erp.production.orders.show', compact('order', 'departments', 'materialStock', 'repairSource'));
    }

    public function confirm(int $id, ProductionOrderService $service)
    {
        try {
            $service->confirm($id);
            return back()->with('success', 'Order produksi dikonfirmasi. Material dikeluarkan dari stok & jurnal WIP dicatat.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(int $id, ProductionOrderService $service)
    {
        try {
            $restored = $service->cancel($id);
            $msg = $restored
                ? 'Order produksi dibatalkan. Material dikembalikan ke stok & jurnal WIP dibalik.'
                : 'Order produksi dibatalkan.';
            return back()->with('success', $msg);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function finalizeConfirm(int $id)
    {
        $order = ProductionOrder::with([
            'outputs.product',
            'steps.department',
            'steps.executor',
            'steps.executors',
            'steps.timeLogs',
        ])->findOrFail($id);

        if (!in_array($order->status, ['completed', 'pending'])) {
            return redirect()->route('production.orders.show', $id)
                ->with('error', 'Order ini tidak dalam status menunggu finalisasi atau menunggu stok.');
        }

        // Ringkasan waktu per divisi
        $deptSummary = $order->steps
            ->filter(fn($s) => $s->department_id)
            ->groupBy('department_id')
            ->map(function ($steps) {
                $totalSec  = $steps->sum('elapsed_working_seconds');
                $operators = $steps->flatMap(fn($s) => $s->executors->pluck('name'))
                    ->merge($steps->filter(fn($s) => $s->executor)->map(fn($s) => $s->executor->name))
                    ->filter()->unique()->values();
                return [
                    'dept_name'  => $steps->first()->department?->name ?? '—',
                    'total_sec'  => $totalSec,
                    'total_dur'  => sprintf('%02d:%02d:%02d', intdiv($totalSec,3600), intdiv($totalSec%3600,60), $totalSec%60),
                    'operators'  => $operators->join(', ') ?: '—',
                    'step_names' => $steps->pluck('name')->join(', '),
                ];
            })->values();

        $totalSec = $order->steps->sum('elapsed_working_seconds');
        $totalDur = sprintf('%02d:%02d:%02d', intdiv($totalSec,3600), intdiv($totalSec%3600,60), $totalSec%60);

        $warehouses         = Warehouse::orderBy('name')->get(['id', 'name']);
        $defaultWarehouseId = Warehouse::defaultId();

        return view('erp.production.orders.finalize-confirm', compact('order', 'deptSummary', 'totalDur', 'warehouses', 'defaultWarehouseId'));
    }

    public function finalize(Request $request, int $id, ProductionOrderService $service)
    {
        $request->validate([
            'outputs'                    => 'required|array|min:1',
            'outputs.*.output_id'        => 'required|integer',
            'outputs.*.qty_produced'     => 'required|numeric|min:0',
            'outputs.*.percentage'       => 'nullable|numeric|min:0|max:100',
            'outputs.*.variance_notes'   => 'nullable|string|max:500',
            'outputs.*.allocations'                => 'nullable|array',
            'outputs.*.allocations.*.warehouse_id' => 'required_with:outputs.*.allocations|integer|exists:warehouses,id',
            'outputs.*.allocations.*.qty'          => 'required_with:outputs.*.allocations|numeric|min:0',
        ]);

        try {
            $service->finalize($id, $request->outputs);
            return redirect(list_url('production.completed.index'))
                ->with('success', 'Produksi selesai! Output masuk stok & jurnal dicatat.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(int $id, ProductionOrderService $service)
    {
        try {
            $service->void($id);
            return back()->with('success', 'Finalisasi dibatalkan. Order kembali ke status Menunggu Stok / Finalisasi — silakan finalisasi ulang setelah stok atau koreksi siap.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Edit hasil finalisasi (koreksi salah input): balik + terapkan ulang qty output baru
     * secara atomik. FIFO & jurnal ikut teredit. Order tetap berstatus 'finalized'.
     */
    public function editFinalize(Request $request, int $id, ProductionOrderService $service)
    {
        $request->validate([
            'outputs'                  => 'required|array|min:1',
            'outputs.*.output_id'      => 'required|integer',
            'outputs.*.qty_produced'   => 'required|numeric|min:0',
            'outputs.*.percentage'     => 'nullable|numeric|min:0|max:100',
            'outputs.*.variance_notes' => 'nullable|string|max:500',
            'outputs.*.allocations'                => 'nullable|array',
            'outputs.*.allocations.*.warehouse_id' => 'required_with:outputs.*.allocations|integer|exists:warehouses,id',
            'outputs.*.allocations.*.qty'          => 'required_with:outputs.*.allocations|numeric|min:0',
        ]);

        try {
            $service->editFinalization($id, $request->outputs);
            return back()->with('success', 'Finalisasi diperbarui. Stok output (FIFO) & jurnal sudah disesuaikan dengan qty baru.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function storeStep(Request $request, int $id, ProductionOrderService $service)
    {
        $request->validate([
            'steps'                   => 'required|array|min:1',
            // Cukup pilih divisi; nama langkah diturunkan otomatis dari nama divisi
            'steps.*.department_id'   => 'required|exists:production_departments,id',
            'steps.*.name'            => 'nullable|string',
        ]);

        try {
            $service->updateSteps($id, $request->steps);
            return back()->with('success', 'Langkah produksi berhasil disimpan.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function addStep(Request $request, int $id, ProductionOrderService $service)
    {
        $request->validate([
            'department_id' => 'required|exists:production_departments,id',
        ]);

        try {
            $service->addStep($id, (int) $request->department_id);
            return back()->with('success', 'Langkah produksi berhasil ditambahkan.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteStep(int $id, int $stepId, ProductionOrderService $service)
    {
        try {
            $service->deleteStep($id, $stepId);
            return back()->with('success', 'Langkah produksi dihapus.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Update gambar kerja (image_paths) — tambah upload baru &/atau hapus yang dipilih.
     * Boleh selama order belum final/dibatalkan, termasuk saat produksi berjalan.
     */
    public function updateImages(Request $request, int $id)
    {
        $request->validate([
            'images'   => 'nullable|array',
            'images.*' => 'image|max:5120',
            'remove'   => 'nullable|array',
        ]);

        $order = ProductionOrder::findOrFail($id);

        if (in_array($order->status, ['finalized', 'cancelled'])) {
            return back()->with('error', 'Gambar kerja tidak bisa diubah untuk order yang sudah selesai atau dibatalkan.');
        }

        $paths = $this->currentImagePaths($order);

        // Hapus gambar yang dipilih (file + entri)
        foreach ((array) $request->input('remove', []) as $r) {
            $key = array_search($r, $paths, true);
            if ($key !== false) {
                \Storage::disk('public')->delete($r);
                unset($paths[$key]);
            }
        }
        $paths = array_values($paths);

        // Tambah gambar baru
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $f) {
                $paths[] = $f->store('production-images', 'public');
            }
        }

        // Simpan dengan format yang sama seperti saat create (lihat ProductionOrderService::create)
        $order->image_paths = json_encode(array_values($paths));
        $order->save();

        return back()->with('success', 'Gambar kerja berhasil diperbarui.');
    }

    /**
     * Baca image_paths secara robust (data lama tersimpan double-encoded JSON).
     */
    private function currentImagePaths(ProductionOrder $order): array
    {
        $raw = $order->getRawOriginal('image_paths');
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        if (is_string($decoded)) $decoded = json_decode($decoded, true);
        return is_array($decoded) ? array_values(array_filter($decoded)) : [];
    }

    public function updatePriority(Request $request, int $id)
    {
        $request->validate([
            'value' => 'required|in:auto,low,medium,high,very_high,urgent',
        ]);

        $order = ProductionOrder::findOrFail($id);

        if (in_array($order->status, ['finalized', 'cancelled'])) {
            $msg = 'Prioritas tidak dapat diubah untuk order yang sudah selesai atau dibatalkan.';
            return $request->wantsJson()
                ? response()->json(['error' => $msg], 422)
                : back()->with('error', $msg);
        }

        $value = $request->input('value');
        if ($value === 'auto') {
            $order->score_type     = 'auto';
            $order->priority_level = null;
        } else {
            $order->score_type     = 'priority';
            $order->priority_level = $value;
        }
        $order->save();

        if ($request->wantsJson()) {
            return response()->json([
                'score_type'     => $order->score_type,
                'priority_level' => $order->priority_level,
                'priority_label' => $order->priority_label,
                'effective_score'=> $order->effective_score,
            ]);
        }

        return back()->with('success', 'Prioritas produksi diperbarui.');
    }

    public function completed(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $from   = $request->input('from');
        $to     = $request->input('to');
        $type   = $request->input('type');

        $query = ProductionOrder::with([
                'bom', 'salesOrder', 'outputs.product', 'steps.department',
                'steps.executors.karyawan', 'steps.executor.karyawan', 'steps.timeLogs',
            ])
            ->where('status', 'finalized');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('order_number', 'like', $like)
                  ->orWhereHas('outputs.product',          fn($p) => $p->where('name', 'like', $like))
                  ->orWhereHas('steps.executors',          fn($e) => $e->where('name', 'like', $like))
                  ->orWhereHas('steps.executor',           fn($e) => $e->where('name', 'like', $like))
                  ->orWhereHas('steps.executors.karyawan', fn($k) => $k->where('name', 'like', $like))
                  ->orWhereHas('steps.executor.karyawan',  fn($k) => $k->where('name', 'like', $like));
            });
        }

        if ($from) $query->whereDate('finalized_at', '>=', $from);
        if ($to)   $query->whereDate('finalized_at', '<=', $to);
        if ($type) $query->where('type', $type);

        $finalized = $query->latest('finalized_at')->get();

        $awaitingConfirm = ProductionOrder::with(['bom', 'salesOrder', 'outputs.product'])
            ->whereIn('status', ['completed', 'pending'])
            ->latest()
            ->get();

        $warehouses         = Warehouse::orderBy('name')->get(['id', 'name']);
        $defaultWarehouseId = Warehouse::defaultId();

        return view('erp.production.completed.index', compact('finalized', 'awaitingConfirm', 'warehouses', 'defaultWarehouseId'));
    }

    private function validateSoOutputLimits(Request $request): ?string
    {
        $soId    = (int) $request->sales_order_id;
        $outputs = collect($request->input('outputs', []));
        if ($outputs->isEmpty()) return null;

        $so = SalesOrder::with('items.product')->find($soId);
        if (!$so) return null;

        $existing = DB::table('production_order_outputs as poo')
            ->join('production_orders as po', 'poo.production_order_id', '=', 'po.id')
            ->where('po.sales_order_id', $soId)
            ->whereNotIn('po.status', ['cancelled'])
            ->selectRaw('poo.product_id, SUM(poo.qty_planned) as total')
            ->groupBy('poo.product_id')
            ->pluck('total', 'product_id');

        $newByProduct = $outputs
            ->filter(fn($o) => !empty($o['product_id']))
            ->groupBy('product_id')
            ->map(fn($g) => collect($g)->sum(fn($r) => (float) ($r['qty_planned'] ?? 0)));

        // Produk custom bisa muncul di beberapa baris SO dgn product_id sama → batas = TOTAL qty
        // semua baris produk tsb (bukan hanya baris pertama).
        $orderedByProduct = $so->items->groupBy('product_id')->map(fn($g) => (float) $g->sum('qty'));

        foreach ($newByProduct as $productId => $newQty) {
            $pid = (int) $productId;
            if (!$orderedByProduct->has($pid)) continue;

            $ordered = (float) $orderedByProduct->get($pid);
            $planned = (float) ($existing[$productId] ?? 0);
            $after   = $planned + (float) $newQty;

            if ($after > $ordered + 0.0001) {
                $soItem = $so->items->firstWhere('product_id', $pid);
                $sku  = $soItem->product?->sku ?? '#'.$productId;
                $sisa = max(0, $ordered - $planned);
                return "Output untuk {$sku} melebihi batas SO. Dipesan: ".rtrim(rtrim(number_format($ordered, 4, '.', ''), '0'), '.').
                       ", sudah direncanakan: ".rtrim(rtrim(number_format($planned, 4, '.', ''), '0'), '.').
                       ", sisa yang dapat diproduksi: ".rtrim(rtrim(number_format($sisa, 4, '.', ''), '0'), '.').
                       ". Kurangi qty output.";
            }
        }

        return null;
    }

    public function searchSalesOrders(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        $salesOrders = SalesOrder::with(['customer', 'items.product'])
            ->where('status', 'confirmed')
            ->whereHas('items.product', fn($qp) => $qp->where('sale_type', 'preorder'))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('order_number', 'LIKE', "%{$q}%")
                      ->orWhereHas('customer', fn($c) => $c->where('name', 'LIKE', "%{$q}%"))
                      ->orWhereHas('items.product', function ($p) use ($q) {
                          $p->where('sale_type', 'preorder')
                            ->where(fn($x) => $x->where('sku', 'LIKE', "%{$q}%")
                                                 ->orWhere('name', 'LIKE', "%{$q}%"));
                      });
                });
            })
            ->latest('order_date')
            ->limit(25)
            ->get();

        if ($salesOrders->isEmpty()) {
            return response()->json([]);
        }

        $soIds = $salesOrders->pluck('id')->all();
        $plannedByPair = DB::table('production_order_outputs as poo')
            ->join('production_orders as po', 'poo.production_order_id', '=', 'po.id')
            ->whereIn('po.sales_order_id', $soIds)
            ->whereNotIn('po.status', ['cancelled'])
            ->selectRaw('po.sales_order_id as so_id, poo.product_id, SUM(poo.qty_planned) as planned')
            ->groupBy('po.sales_order_id', 'poo.product_id')
            ->get()
            ->keyBy(fn($r) => $r->so_id . '-' . $r->product_id);

        // SO yang sudah punya faktur (selain void) dianggap selesai → jangan ditampilkan.
        $invoicedSoIds = SalesInvoice::whereIn('sales_order_id', $soIds)
            ->whereNotIn('status', ['void'])
            ->pluck('sales_order_id')
            ->flip();

        $results = $salesOrders->map(function ($so) use ($plannedByPair) {
            // Gabung per product_id: produk custom bisa muncul di beberapa baris SO dgn nama beda
            // tapi 1 product_id. Produksi dilacak per product_id, jadi qty & sisa harus diakumulasi
            // per produk (kalau tidak, produksi sebagian salah hitung / SO salah disembunyikan).
            $items = $so->items
                ->filter(fn($i) => $i->product && $i->product->sale_type === 'preorder')
                ->groupBy('product_id')
                ->map(function ($group) use ($so, $plannedByPair) {
                    $first   = $group->first();
                    $ordered = (float) $group->sum('qty');
                    $key     = $so->id . '-' . $first->product_id;
                    $planned = (float) ($plannedByPair[$key]->planned ?? 0);
                    // Gabung nama yang diinput di SO (utamakan description, fallback nama master).
                    $name = $group->map(fn($i) => $i->description ?: $i->product->name)
                        ->filter()->unique()->implode(' + ');
                    return [
                        'product_id'    => $first->product_id,
                        'sku'           => $first->product->sku,
                        'name'          => $name !== '' ? $name : $first->product->name,
                        'qty_ordered'   => $ordered,
                        'qty_planned'   => $planned,
                        'qty_remaining' => max(0, round($ordered - $planned, 4)),
                    ];
                })
                ->values()
                ->all();

            return [
                'id'            => $so->id,
                'order_number'  => $so->order_number,
                'customer_name' => $so->customer?->name ?? '—',
                'order_date'    => optional($so->order_date)->format('d/m/Y') ?? '',
                'items'         => $items,
            ];
        })
        // Sembunyikan SO yang sudah ada fakturnya, atau sudah selesai diproduksi
        // (tidak ada item preorder dengan sisa qty yang belum diproduksi).
        ->filter(fn($so) => ! $invoicedSoIds->has($so['id']))
        ->filter(fn($so) => collect($so['items'])->contains(fn($i) => $i['qty_remaining'] > 0))
        ->values();

        return response()->json($results);
    }

    public function getRepairSources(Request $request): \Illuminate\Http\JsonResponse
    {
        $sourceType = $request->get('source_type', '');
        $search     = $request->get('search', '');

        $results = match($sourceType) {
            'warranty'   => $this->fetchWarrantySources($search),
            'adjustment' => $this->fetchAdjustmentSources($search),
            'return'     => $this->fetchReturnSources($search),
            default      => [],
        };

        return response()->json($results);
    }

    private function fetchWarrantySources(string $search): array
    {
        return WarrantyOrder::with(['items.product', 'customer'])
            ->whereIn('status', ['posted', 'repaired'])
            ->when($search, fn($q) => $q->where('warranty_number', 'LIKE', "%{$search}%"))
            ->latest('warranty_date')
            ->limit(20)
            ->get()
            ->map(fn($w) => $this->formatRepairSourceDoc(
                $w->id,
                $w->warranty_number,
                $w->customer?->name ?? '',
                $w->items
            ))
            ->toArray();
    }

    private function fetchAdjustmentSources(string $search): array
    {
        return InventoryAdjustment::with('items.product')
            ->where('purpose', 'perbaikan_rusak')
            ->where('status', 'posted')
            ->when($search, fn($q) => $q->where('number', 'LIKE', "%{$search}%"))
            ->latest('date')
            ->limit(20)
            ->get()
            ->map(fn($a) => $this->formatRepairSourceDoc(
                $a->id,
                $a->number,
                $a->responsible ?? '',
                $a->items,
                'adjustment'
            ))
            ->toArray();
    }

    private function fetchReturnSources(string $search): array
    {
        return SalesReturn::with(['items.product', 'customer'])
            ->where('status', 'posted')
            ->whereHas('items', fn($q) => $q->where('condition', 'repair'))
            ->when($search, fn($q) => $q->where('return_number', 'LIKE', "%{$search}%"))
            ->latest('return_date')
            ->limit(20)
            ->get()
            ->map(function ($r) {
                $damagedItems = $r->items->where('condition', 'repair')->values();
                return $this->formatRepairSourceDoc(
                    $r->id,
                    $r->return_number,
                    $r->customer?->name ?? '',
                    $damagedItems
                );
            })
            ->toArray();
    }

    private function formatRepairSourceDoc(int $id, string $number, string $suffix, $items, string $type = 'default'): array
    {
        $itemsData = collect($items)
            ->filter(fn($item) => $item->product_id && $item->product)
            ->values()
            ->map(function ($item) use ($type) {
                $qty = $type === 'adjustment'
                    ? abs((float) ($item->diff_qty ?? $item->system_qty ?? 0))
                    : (float) $item->qty;

                return [
                    'product_id' => $item->product_id,
                    'sku'        => $item->product->sku ?? '',
                    'name'       => $item->product->name ?? '',
                    'qty'        => $qty,
                    'last_cost'  => (float) ($item->product->last_cost ?? 0),
                ];
            })
            ->filter(fn($i) => $i['qty'] > 0)
            ->values();

        $totalValue = $itemsData->sum(fn($i) => $i['qty'] * $i['last_cost']);

        $itemsWithPct = $itemsData->map(function ($item, $idx) use ($totalValue) {
            $value = $item['qty'] * $item['last_cost'];
            $pct   = $totalValue > 0 ? round($value / $totalValue * 100, 2) : ($idx === 0 ? 100.0 : 0.0);
            return array_merge($item, [
                'output_type' => $idx === 0 ? 'main' : 'by_product',
                'percentage'  => $pct,
            ]);
        })->toArray();

        return [
            'id'    => $id,
            'number' => $number,
            'label' => $number . ($suffix ? ' — ' . $suffix : ''),
            'items' => $itemsWithPct,
        ];
    }
}
