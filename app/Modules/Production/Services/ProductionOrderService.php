<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderMaterial;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionOrderSource;
use App\Modules\Production\Models\ProductionOrderCost;
use App\Modules\Production\Models\ProductionFinalization;
use App\Modules\Production\Models\ProductionFinalizationItem;
use App\Modules\Production\Models\ProductionTargetRevision;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Models\ProductionStepExecutorStatus;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\Department;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\Warehouse;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Enums\AccountCodeEnum;
use App\Core\Accounting\Account;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Exception;

class ProductionOrderService
{
    public function create(array $data): ProductionOrder
    {
        return DB::transaction(function () use ($data) {
            $order = ProductionOrder::create([
                'order_number'           => NumberGeneratorService::generate('OP'),
                'type'                   => $data['type'],
                'bom_id'             => $data['bom_id'] ?? null,
                'sales_order_id'     => $data['sales_order_id'] ?? null,
                'warehouse_id'       => $data['warehouse_id'],
                'repair_source_type' => $data['repair_source_type'] ?? null,
                'repair_source_ref'  => $data['repair_source_ref'] ?? null,
                'repair_source_id'   => $data['repair_source_id'] ?? null,
                'score_type'         => $data['score_type'] ?? 'auto',
                'priority_level'     => ($data['score_type'] ?? 'auto') === 'priority' ? ($data['priority_level'] ?? 'low') : null,
                'planned_cycles'         => $data['planned_cycles'] ?? 1,
                'planned_qty'            => $data['planned_qty'] ?? 0,
                'production_date' => $data['production_date'],
                'status'      => 'draft',
                'created_via' => $data['created_via'] ?? 'manual',
                'notes'       => $data['notes'] ?? null,
                'description' => $data['description'] ?? null,
                'image_paths' => isset($data['image_paths']) ? json_encode($data['image_paths']) : null,
            ]);

            // Tipe repair-like (perbaikan/garansi/legacy repair): auto-create materials/outputs
            // (dan sources bila ada dokumen) dari repair_items. Input=output produk sama.
            //  • perbaikan → item dari SKU Gudang Perbaikan, TANPA dokumen sumber.
            //  • garansi/repair → item dari dokumen warranty/retur, dengan source_id.
            if (ProductionOrder::isRepairType($data['type'] ?? '') && !empty($data['repair_items'])) {
                $sourceType = match($data['repair_source_type'] ?? '') {
                    'warranty'   => 'warranty',
                    'adjustment' => 'stock_repair',
                    'return'     => 'sales_return',
                    default      => 'stock_repair',
                };

                foreach ($data['repair_items'] as $item) {
                    if (empty($item['product_id']) || empty($item['qty'])) continue;

                    ProductionOrderMaterial::create([
                        'production_order_id' => $order->id,
                        'product_id'          => $item['product_id'],
                        'qty_required'        => $item['qty'],
                        'unit'                => '',
                    ]);

                    ProductionOrderOutput::create([
                        'production_order_id' => $order->id,
                        'product_id'          => $item['product_id'],
                        'qty_planned'         => $item['qty'],
                        // Perbaikan: tiap SKU adalah "main" independen (biaya tambahan dibagi rata
                        // per unit saat finalize, bukan berbasis persentase sampingan).
                        'output_type'         => $item['output_type'] ?? 'main',
                        'percentage'          => $item['percentage'] ?? 0,
                    ]);

                    if (!empty($data['repair_source_id'])) {
                        ProductionOrderSource::create([
                            'production_order_id' => $order->id,
                            'source_type'         => $sourceType,
                            'source_id'           => $data['repair_source_id'],
                            'product_id'          => $item['product_id'],
                            'qty'                 => $item['qty'],
                        ]);
                    }
                }
            } else {
                // Sync materials
                foreach ($data['materials'] ?? [] as $m) {
                    if (empty($m['product_id']) || empty($m['qty_required'])) continue;
                    // Unit wajib terisi: pakai unit dari form, jika kosong fallback ke base_unit
                    // produk. Unit ikut menentukan componentSignature() (gabung OP) — unit kosong
                    // membuat sidik jari beda sehingga OP dari resep sama gagal digabung.
                    $unit = $m['unit'] ?? null;
                    if (blank($unit)) {
                        $unit = \App\Core\Inventory\Product::where('id', $m['product_id'])->value('base_unit');
                    }
                    ProductionOrderMaterial::create([
                        'production_order_id' => $order->id,
                        'product_id'          => $m['product_id'],
                        'qty_required'        => $m['qty_required'],
                        'unit'                => $unit,
                        'notes'               => $m['notes'] ?? null,
                    ]);
                }

                // Sync outputs. Persentase output sampingan:
                //  • DENGAN BOM: otoritatif dari master Produk Sampingan (fixed per-unit):
                //    sampingan = unit% × (qty/siklus), unit_percentage disimpan → finalize recompute.
                //  • TANPA BOM: ukuran material bisa beda sehingga persentase pasti beda untuk
                //    sampingan yang sama → pakai persentase yang di-input/di-override user apa adanya,
                //    unit_percentage = null sebagai sinyal "manual" (finalize hormati nilai ini).
                //  Utama selalu = sisa (100 − Σ sampingan).
                $rawOutputs = array_values(array_filter(
                    $data['outputs'] ?? [],
                    fn($o) => !empty($o['product_id']) && !empty($o['qty_planned'])
                ));
                if ($rawOutputs) {
                    $usesBom = !empty($order->bom_id);
                    $cycles  = max(1e-9, (float) ($order->planned_cycles ?: 1));

                    if ($usesBom) {
                        $byIds  = collect($rawOutputs)
                            ->filter(fn($o) => ($o['output_type'] ?? 'main') === 'by_product')
                            ->pluck('product_id')->map(fn($id) => (int) $id)->unique();
                        $masters = \App\Modules\Production\Models\ProductionByproduct::whereIn('product_id', $byIds)
                            ->get()->keyBy('product_id');

                        $sumBp = 0.0;
                        foreach ($rawOutputs as $k => $o) {
                            if (($o['output_type'] ?? 'main') !== 'by_product') continue;
                            $master = $masters->get((int) $o['product_id']);
                            // Master tanpa % default (null/tak terdaftar) → manual: hormati persentase user.
                            if (!$master || $master->percentage === null) {
                                $rawOutputs[$k]['unit_percentage'] = null;
                                $rawOutputs[$k]['percentage']      = round((float) ($o['percentage'] ?? 0), 4);
                                $sumBp += $rawOutputs[$k]['percentage'];
                                continue;
                            }
                            $unitPct = (float) $master->percentage;
                            $pct     = round($unitPct * ((float) $o['qty_planned'] / $cycles), 4);
                            $rawOutputs[$k]['unit_percentage'] = $unitPct;
                            $rawOutputs[$k]['percentage']      = $pct;
                            $sumBp += $pct;
                        }
                    } else {
                        // Tanpa BOM: hormati persentase manual; jangan timpa dari master.
                        $sumBp = 0.0;
                        foreach ($rawOutputs as $k => $o) {
                            if (($o['output_type'] ?? 'main') !== 'by_product') continue;
                            $rawOutputs[$k]['unit_percentage'] = null;
                            $rawOutputs[$k]['percentage']      = round((float) ($o['percentage'] ?? 0), 4);
                            $sumBp += $rawOutputs[$k]['percentage'];
                        }
                    }

                    $mainPct = round(100 - $sumBp, 4);

                    foreach ($rawOutputs as $o) {
                        $isMain = ($o['output_type'] ?? 'main') === 'main';
                        ProductionOrderOutput::create([
                            'production_order_id' => $order->id,
                            'product_id'          => $o['product_id'],
                            'qty_planned'         => $o['qty_planned'],
                            'output_type'         => $o['output_type'] ?? 'main',
                            'percentage'          => $isMain ? $mainPct : ($o['percentage'] ?? 0),
                            'unit_percentage'     => $isMain ? null : ($o['unit_percentage'] ?? null),
                        ]);
                    }
                }
            }

            // Sync steps
            foreach ($data['steps'] ?? [] as $i => $s) {
                if (empty($s['name'])) continue;
                ProductionOrderStep::create([
                    'production_order_id' => $order->id,
                    'bom_step_id'         => $s['bom_step_id'] ?? null,
                    'step_number'         => $i + 1,
                    'department_id'       => $s['department_id'] ?? null,
                    'name'                => $s['name'],
                    'description'         => $s['description'] ?? null,
                    'status'              => 'pending',
                ]);
            }

            // Sync sources (untuk tipe repair)
            foreach ($data['sources'] ?? [] as $src) {
                if (empty($src['source_type']) || empty($src['product_id'])) continue;
                ProductionOrderSource::create([
                    'production_order_id' => $order->id,
                    'source_type'         => $src['source_type'],
                    'source_id'           => $src['source_id'],
                    'product_id'          => $src['product_id'],
                    'qty'                 => $src['qty'],
                    'notes'               => $src['notes'] ?? null,
                ]);
            }

            // Simpan biaya produksi & jurnal Dr.WIP / Cr.Kas
            $costItems = collect($data['costs'] ?? [])
                ->filter(fn($c) => !empty($c['description']) && !empty($c['amount']) && !empty($c['cash_account_id']));

            if ($costItems->isNotEmpty()) {
                $wipAccount = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
                $totalCost  = 0;
                $lines      = [];

                foreach ($costItems->groupBy('cash_account_id') as $cashAccId => $items) {
                    $subtotal = $items->sum('amount');
                    $totalCost += $subtotal;
                    $cashAccount = Account::findOrFail($cashAccId);
                    $lines[] = new JournalLineDTO(
                        account_id:  $cashAccount->id,
                        debit:       0,
                        credit:      (float) $subtotal,
                        description: 'Kas keluar biaya produksi'
                    );
                }

                $lines[] = new JournalLineDTO(
                    account_id:  $wipAccount->id,
                    debit:       (float) $totalCost,
                    credit:      0,
                    description: 'Biaya produksi masuk WIP'
                );

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:             $order->production_date->format('Y-m-d'),
                    reference_type:   'production_order_cost',
                    reference_id:     $order->id,
                    description:      "Biaya Produksi - {$order->order_number}",
                    lines:            $lines,
                    reference_number: $order->order_number
                ));

                foreach ($costItems as $c) {
                    ProductionOrderCost::create([
                        'production_order_id' => $order->id,
                        'description'         => $c['description'],
                        'amount'              => $c['amount'],
                        'cash_account_id'     => $c['cash_account_id'],
                    ]);
                }
            }

            return $order;
        });
    }

    public function updateSteps(int $orderId, array $steps): void
    {
        $order = ProductionOrder::findOrFail($orderId);

        if (!in_array($order->status, ['draft', 'confirmed'])) {
            throw new Exception('Langkah hanya bisa diubah pada status draft atau dikonfirmasi.');
        }

        DB::transaction(function () use ($order, $steps) {
            $order->steps()->delete();

            foreach ($steps as $i => $s) {
                $deptId = $s['department_id'] ?? null;
                if (empty($deptId)) continue; // langkah wajib punya divisi

                // Nama langkah diturunkan otomatis dari nama divisi (fallback ke nama manual bila ada)
                $dept = Department::find($deptId);
                $name = !empty($s['name']) ? $s['name'] : ($dept?->name ?? 'Langkah ' . ($i + 1));

                ProductionOrderStep::create([
                    'production_order_id' => $order->id,
                    'bom_step_id'         => $s['bom_step_id'] ?? null,
                    'step_number'         => $i + 1,
                    'department_id'       => $deptId,
                    'name'                => $name,
                    'description'         => $s['description'] ?? null,
                    'status'              => 'pending',
                ]);
            }
        });
    }

    /**
     * Tambah satu langkah baru (append di urutan akhir) — boleh saat produksi
     * sedang berjalan. Langkah baru selalu pending; langkah lama tidak disentuh
     * agar progress timer/eksekutor aman.
     */
    public function addStep(int $orderId, int $departmentId): ProductionOrderStep
    {
        $order = ProductionOrder::findOrFail($orderId);

        if (!in_array($order->status, ['draft', 'confirmed', 'in_progress'])) {
            throw new Exception('Langkah hanya bisa ditambah saat order draft, dikonfirmasi, atau sedang berjalan.');
        }

        $dept = Department::find($departmentId);
        if (!$dept) {
            throw new Exception('Divisi tidak ditemukan.');
        }

        $nextNumber = (int) ($order->steps()->max('step_number') ?? 0) + 1;

        return ProductionOrderStep::create([
            'production_order_id' => $order->id,
            'step_number'         => $nextNumber,
            'department_id'       => $dept->id,
            'name'                => $dept->name,
            'status'              => 'pending',
        ]);
    }

    /**
     * Hapus satu langkah — hanya boleh selama produksi BELUM dimulai
     * (order masih draft/confirmed) dan langkah masih pending.
     */
    public function deleteStep(int $orderId, int $stepId): void
    {
        $order = ProductionOrder::findOrFail($orderId);

        if (!in_array($order->status, ['draft', 'confirmed'])) {
            throw new Exception('Langkah tidak bisa dihapus setelah produksi dimulai.');
        }

        $step = ProductionOrderStep::where('production_order_id', $order->id)
            ->where('id', $stepId)
            ->firstOrFail();

        if ($step->status !== 'pending') {
            throw new Exception('Hanya langkah yang belum dikerjakan yang bisa dihapus.');
        }

        DB::transaction(function () use ($order, $step) {
            $step->delete();
            // Rapikan nomor urut langkah yang tersisa
            $order->steps()->orderBy('step_number')->get()
                ->each(fn ($s, $i) => $s->update(['step_number' => $i + 1]));
        });
    }

    /**
     * Konfirmasi order → consume material dari stok (Dr. WIP / Cr. Persediaan).
     *
     * Kekurangan bahan TIDAK memblokir konfirmasi: produksi harus tetap bisa jalan sambil
     * bahan dibeli. Bahan yang stoknya kurang ditunda konsumsinya (qty_consumed tetap 0)
     * dan baru di-FIFO saat finalisasi — di sanalah stok wajib benar-benar cukup.
     *
     * @return array<int,string>  Label bahan yang konsumsinya ditunda (kosong = semua terkonsumsi)
     */
    public function confirm(int $orderId): array
    {
        return DB::transaction(function () use ($orderId) {
            $order = ProductionOrder::with(['materials.product', 'outputs', 'steps'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->status !== 'draft') {
                throw new Exception('Hanya order berstatus draft yang dapat dikonfirmasi.');
            }

            if ($order->materials->isEmpty()) {
                throw new Exception('Order produksi harus memiliki minimal 1 material.');
            }

            if ($order->outputs->isEmpty()) {
                throw new Exception('Order produksi harus memiliki minimal 1 output.');
            }

            [$consumable, $deferred] = $this->splitMaterialsByStock($order, $order->materials);

            if ($consumable->isNotEmpty()) {
                $this->consumeOrderMaterials($order, $consumable);
            }

            $order->update(['status' => 'confirmed']);

            return $deferred->map(fn ($m) => trim(($m->product->sku ?? '') . ' ' . ($m->product->name ?? '-')))
                ->values()->all();
        });
    }

    /**
     * Pisahkan material menjadi yang bisa dikonsumsi sekarang vs yang harus ditunda.
     *
     * Plafonnya sengaja saldo FISIK (ledger) DAN sisa layer FIFO — dua hal yang benar-benar
     * bikin konsumsi gagal. Reservasi penjualan tidak dihitung supaya perilaku order yang
     * selama ini lolos konfirmasi tidak berubah. Beberapa baris material bisa menunjuk produk
     * yang sama, jadi sisa stok dilacak per produk selama pembagian.
     *
     * @param  iterable<ProductionOrderMaterial>  $materials
     * @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection}
     */
    private function splitMaterialsByStock(ProductionOrder $order, iterable $materials): array
    {
        $engine      = app(InventoryEngine::class);
        $warehouseId = $this->materialWarehouseId($order);

        $consumable = collect();
        $deferred   = collect();
        $budget     = [];

        foreach ($materials as $material) {
            $toConsume = (float) $material->qty_required - (float) $material->qty_consumed;
            if ($toConsume <= 1e-9) {
                $consumable->push($material);   // tidak ada sisa; consume() akan melewatinya
                continue;
            }

            $pid = (int) $material->product_id;
            if (!array_key_exists($pid, $budget)) {
                $budget[$pid] = min(
                    (float) $engine->onHand($pid, $warehouseId),
                    (float) $engine->fifoRemaining($pid, $warehouseId)
                );
            }

            if ($budget[$pid] + 1e-9 >= $toConsume) {
                $budget[$pid] -= $toConsume;
                $consumable->push($material);
            } else {
                $deferred->push($material);
            }
        }

        return [$consumable, $deferred];
    }

    /**
     * Gudang asal bahan: tipe 'perbaikan' menarik barang menunggu perbaikan dari GUDANG
     * PERBAIKAN, sisanya (termasuk garansi/legacy repair) dari gudang order yang terkunci.
     */
    private function materialWarehouseId(ProductionOrder $order): int
    {
        return (int) ($order->type === 'perbaikan'
            ? (Warehouse::repairId() ?? $order->warehouse_id)
            : $order->warehouse_id);
    }

    /**
     * Konsumsi material order → catat ledger, FIFO consume, update qty_consumed,
     * dan post jurnal Dr. WIP / Cr. Persediaan (atau Persediaan Perbaikan).
     *
     * Dipakai oleh confirm() (konsumsi langsung) dan finalize() (konsumsi tertunda
     * untuk preorder yang sebelumnya soft-confirmed via DP).
     *
     * @param  ProductionOrder  $order
     * @param  iterable<ProductionOrderMaterial>  $materials  Material yang akan dikonsumsi
     * @return float  Total cost yang masuk WIP
     */
    private function consumeOrderMaterials(ProductionOrder $order, iterable $materials): float
    {
        $creditCode = $order->isRepairLike()
            ? AccountCodeEnum::INVENTORY_REPAIR
            : AccountCodeEnum::INVENTORY;

        $materialWarehouseId = $this->materialWarehouseId($order);

        $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
        $inventoryAccount = Account::where('code', $creditCode)->firstOrFail();

        $totalMaterialCost = 0;
        $fifo   = app(FifoService::class);
        $engine = app(InventoryEngine::class);

        foreach ($materials as $material) {
            // Konsumsi hanya sisa yang belum terkonsumsi (mendukung material hasil merge
            // yang sudah terkonsumsi sebagian: qty_consumed antara 0 dan qty_required).
            $toConsume = (float) $material->qty_required - (float) $material->qty_consumed;
            if ($toConsume <= 1e-9) {
                continue;
            }

            // Catat stock-out di inventory_ledgers (FifoService::consume tidak menyentuh ledger).
            $engine->ledger(
                productId: $material->product_id,
                warehouseId: $materialWarehouseId,
                qtyIn: 0,
                qtyOut: $toConsume,
                type: 'production_material',
                reference: $order->order_number,
                notes: null,
                transactionId: $order->id
            );

            $cogs = $fifo->consume(
                $material->product_id,
                $materialWarehouseId,
                $toConsume,
                'production_material',
                $order->id
            );

            $material->update(['qty_consumed' => $material->qty_required]);
            $totalMaterialCost += $cogs;
        }

        if ($totalMaterialCost > 0) {
            app(JournalPostingService::class)->post(new JournalEntryDTO(
                date:             $order->production_date->format('Y-m-d'),
                reference_type:   'production_order_confirm',
                reference_id:     $order->id,
                description:      "Konsumsi Material - {$order->order_number}",
                lines: [
                    new JournalLineDTO(
                        account_id:  $wipAccount->id,
                        debit:       (float) $totalMaterialCost,
                        credit:      0,
                        description: 'Material masuk WIP'
                    ),
                    new JournalLineDTO(
                        account_id:  $inventoryAccount->id,
                        debit:       0,
                        credit:      (float) $totalMaterialCost,
                        description: 'Pengeluaran material produksi'
                    ),
                ],
                reference_number: $order->order_number,
                // Konsumsi material bisa terjadi lebih dari sekali untuk satu OP: sebagian
                // bahan dikonsumsi saat confirm, sisanya menyusul saat stok datang (finalisasi
                // dari status Menunggu Stok). Tanpa ini, jurnal konsumsi kedua ditolak
                // "Journal already posted for this reference." dan OP tak bisa difinalisasi.
                // Aman karena qty_consumed yang menjaga agar bahan tak dikonsumsi dua kali,
                // dan semua pembaca jurnal konsumsi (wipBreakdown, merge, void) menjumlah
                // SEMUA baris, bukan mengambil satu.
                allow_repeat: true
            ));
        }

        return $totalMaterialCost;
    }

    /**
     * Mulai proses — mendukung 1 atau lebih operator mengerjakan step bersamaan.
     * $force=true → bypass scan check (untuk override saat mesin fingerprint rusak). Audit di notes step.
     */
    public function startStep(int $stepId, array $executorIds = [], bool $force = false): void
    {
        DB::transaction(function () use ($stepId, $executorIds, $force) {
            $step = ProductionOrderStep::with('productionOrder')->lockForUpdate()->findOrFail($stepId);
            $order = $step->productionOrder;

            $testing = \App\Models\ProductionSetting::isTestingMode();

            // Operator penaung tidak boleh jadi pelaku langkah — yang bekerja mesinnya.
            // Dijaga di sini, bukan hanya di tampilan, karena pilihan ini menentukan
            // pembagi kapasitas: satu jam yang tercatat dua kali merusak HPP.
            $supervisors = DepartmentExecutor::whereIn('id', $executorIds)
                ->whereHas('children')->pluck('name');
            if ($supervisors->isNotEmpty()) {
                throw new Exception(
                    $supervisors->join(', ') . ' adalah operator penaung mesin, bukan pelaku langkah. '
                    . 'Pilih mesinnya, bukan operatornya.'
                );
            }

            $this->assertExecutorsReady($executorIds, strict: !$force, bypassReady: $testing);

            if (!in_array($order->status, ['confirmed', 'in_progress', 'partial'])) {
                throw new Exception('Order belum dikonfirmasi.');
            }

            $prevStep = $order->steps()->where('step_number', $step->step_number - 1)->first();
            if ($prevStep && $prevStep->status !== 'completed') {
                throw new Exception("Langkah {$prevStep->step_number} ({$prevStep->name}) harus diselesaikan dulu.");
            }

            if ($step->status !== 'pending') {
                throw new Exception('Langkah ini sudah dimulai atau selesai.');
            }

            // Pastikan tidak ada executor yang sedang mengerjakan task lain.
            if (!empty($executorIds)) {
                $busyIds = DB::table('production_order_step_executors')
                    ->join('production_order_steps', 'production_order_steps.id', '=', 'production_order_step_executors.step_id')
                    ->where('production_order_steps.status', 'in_progress')
                    ->whereIn('production_order_step_executors.executor_id', $executorIds)
                    ->pluck('production_order_step_executors.executor_id')
                    ->toArray();

                if (!empty($busyIds)) {
                    $names = DB::table('production_department_executors')
                        ->whereIn('id', $busyIds)->pluck('name')->join(', ');
                    throw new Exception("{$names} sedang mengerjakan task lain. Selesaikan atau pause task aktif terlebih dahulu.");
                }
            }

            $primaryId = $executorIds[0] ?? null;

            // Timer task dihitung sejak operator menekan Mulai (realistis: task baru → 00:00:00).
            // Tidak di-backdate ke jam scan check-in, supaya timer mencerminkan durasi task ini
            // saja, bukan selisih sejak awal shift. (Kredit jam kerja untuk payroll dihitung
            // terpisah lewat absensi/fingerprint.)
            $effectiveStart = now();

            $step->update([
                'status'               => 'in_progress',
                'started_at'           => now(),
                'started_effective_at' => $effectiveStart,
                'executor_id'          => $primaryId,
            ]);

            ProductionStepTimeLog::create([
                'production_order_step_id' => $step->id,
                'event_type'               => 'started',
                'occurred_at'              => now(),
                'notes'                    => $force
                                                ? 'Mulai via override manual (bypass scan)'
                                                : ($testing ? 'Mulai via mode testing (bypass scan)' : null),
            ]);

            if (!empty($executorIds)) {
                $pivot = collect($executorIds)
                    ->mapWithKeys(fn($id) => [$id => ['joined_at' => now()->toDateTimeString()]])
                    ->toArray();
                $step->executors()->attach($pivot);

                // Inisialisasi baris status per-executor
                foreach ($executorIds as $eid) {
                    $exec = DepartmentExecutor::find($eid);
                    ProductionStepExecutorStatus::updateOrCreate(
                        ['step_id' => $step->id, 'executor_id' => $eid],
                        [
                            'karyawan_id' => $exec?->effectiveKaryawanId(),
                            'is_active'   => true,
                            'paused_at'   => null,
                            'paused_reason' => null,
                        ]
                    );
                }
            }

            $order->update(['status' => 'in_progress']);
        });
    }

    /**
     * Selesaikan 1 langkah → set langkah berikutnya ke antre (pending).
     * Pada langkah TERAKHIR, qty aktual output di-input oleh operator lewat parameter
     * $actualOutputs (format: [{ output_id, qty_produced, variance_notes? }, ...]).
     * Hanya menyimpan ke production_order_outputs — FIFO + jurnal closing dilakukan
     * nanti saat admin klik Finalisasi.
     *
     * Returns true jika semua langkah selesai (order completed), false jika masih ada langkah.
     */
    public function completeStep(int $stepId, ?int $executorId = null, ?string $notes = null, array $actualOutputs = []): bool
    {
        return DB::transaction(function () use ($stepId, $executorId, $notes, $actualOutputs) {
            $step = ProductionOrderStep::with('productionOrder')->lockForUpdate()->findOrFail($stepId);
            $order = $step->productionOrder;

            if ($step->status !== 'in_progress') {
                throw new Exception('Langkah belum dimulai.');
            }

            $step->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'executor_id'  => $executorId,
                'notes'        => $notes,
            ]);

            ProductionStepTimeLog::create([
                'production_order_step_id' => $step->id,
                'event_type'               => 'completed',
                'occurred_at'              => now(),
            ]);

            $nextStep = $order->steps()->where('step_number', $step->step_number + 1)->first();
            if ($nextStep) {
                $nextStep->update(['status' => 'pending']);
                return false;
            }

            // Langkah terakhir → simpan qty aktual + persentase + keterangan dari operator.
            //
            // Bila sebagian hasil sudah pernah dilepas ke stok, qty_produced adalah AKUMULASI
            // yang dipegang batch — angka operator di sini (qty batch penutup) tidak boleh
            // menimpanya. Qty penutupnya ditanyakan lagi di layar finalisasi.
            $hasReleases = ProductionFinalization::where('production_order_id', $order->id)
                ->whereNull('voided_at')
                ->exists();

            foreach ($actualOutputs as $out) {
                $outputRecord = $order->outputs()->find($out['output_id'] ?? null);
                if (!$outputRecord) continue;
                $payload = [
                    'variance_notes' => $out['variance_notes'] ?? null,
                ];
                if (!$hasReleases) {
                    $payload['qty_produced'] = (float) ($out['qty_produced'] ?? 0);
                }
                if (isset($out['percentage']) && $out['percentage'] !== '' && $out['percentage'] !== null) {
                    $payload['percentage'] = (float) $out['percentage'];
                }
                $outputRecord->update($payload);
            }

            $order->update(['status' => 'completed']);
            return true;
        });
    }

    public function pauseStep(int $stepId): void
    {
        DB::transaction(function () use ($stepId) {
            $step = ProductionOrderStep::lockForUpdate()->findOrFail($stepId);

            if ($step->status !== 'in_progress') {
                throw new Exception('Hanya langkah yang sedang dikerjakan yang dapat dipause.');
            }

            $step->update(['status' => 'paused', 'paused_at' => now()]);

            // Mark semua executor status dengan reason 'manual' agar tidak ter-auto-resume
            ProductionStepExecutorStatus::where('step_id', $step->id)->update([
                'is_active'     => false,
                'paused_at'     => now(),
                'paused_reason' => 'manual',
            ]);

            ProductionStepTimeLog::create([
                'production_order_step_id' => $step->id,
                'event_type'               => 'paused',
                'occurred_at'              => now(),
            ]);
        });
    }

    /**
     * Resume manual — mode longgar (strict=false) sehingga eksekutor tetap bisa
     * melanjutkan walau mesin scan rusak / belum scan / di luar jam kerja.
     * Cron tick() tetap akan auto-pause kembali kalau benar-benar di luar jam kerja —
     * jadi ini hanya "izin sementara" sampai kondisi normalize / di-pause manual lagi.
     */
    public function resumeStep(int $stepId): void
    {
        DB::transaction(function () use ($stepId) {
            $step = ProductionOrderStep::with('productionOrder', 'executors')
                ->lockForUpdate()->findOrFail($stepId);
            $order = $step->productionOrder;

            $execIds = $step->executors->pluck('id')->toArray();
            $this->assertExecutorsReady($execIds, strict: false);

            if ($step->status !== 'paused') {
                throw new Exception('Langkah ini tidak dalam status pending (paused).');
            }

            if (!in_array($order->status, ['confirmed', 'in_progress', 'partial'])) {
                throw new Exception('Order tidak dalam status aktif.');
            }

            $step->update(['status' => 'in_progress', 'paused_at' => null]);

            // Reset semua executor status jadi aktif (clear manual-pause flag)
            ProductionStepExecutorStatus::where('step_id', $step->id)->update([
                'is_active'     => true,
                'paused_at'     => null,
                'paused_reason' => null,
            ]);

            ProductionStepTimeLog::create([
                'production_order_step_id' => $step->id,
                'event_type'               => 'resumed',
                'occurred_at'              => now(),
                'notes'                    => 'Resume manual oleh eksekutor',
            ]);
        });
    }

    /**
     * Kembalikan langkah saat ini (yang masih di ANTREAN/pending) ke langkah sebelumnya.
     * Use-case: operator salah klik selesai di langkah sebelumnya / salah produk.
     *
     * Syarat:
     *  - Langkah saat ini masih 'pending' (belum dimulai sama sekali).
     *  - Ada langkah sebelumnya dan statusnya 'completed'.
     *
     * Efek: langkah sebelumnya di-reset jadi 'pending' (fresh session) sehingga muncul
     * lagi di antrean divisinya; operator harus tekan Mulai lagi. Langkah saat ini otomatis
     * keluar dari antrean "siap" karena prasyarat (langkah sebelumnya completed) tak lagi terpenuhi.
     */
    public function revertToPreviousStep(int $currentStepId): void
    {
        DB::transaction(function () use ($currentStepId) {
            $current = ProductionOrderStep::with('productionOrder')->lockForUpdate()->findOrFail($currentStepId);
            $order   = $current->productionOrder;

            if ($current->status !== 'pending') {
                throw new Exception('Hanya langkah yang masih di antrean (belum dimulai) yang bisa dikembalikan.');
            }

            if ((int) $current->step_number <= 1) {
                throw new Exception('Ini langkah pertama — tidak ada langkah sebelumnya.');
            }

            $prev = $order->steps()
                ->where('step_number', $current->step_number - 1)
                ->lockForUpdate()
                ->first();

            if (!$prev) {
                throw new Exception('Langkah sebelumnya tidak ditemukan.');
            }
            if ($prev->status !== 'completed') {
                throw new Exception('Langkah sebelumnya belum selesai — tidak ada yang perlu dikembalikan.');
            }

            // Reset langkah sebelumnya jadi sesi baru di antrean divisinya.
            $prev->update([
                'status'               => 'pending',
                'completed_at'         => null,
                'started_at'           => null,
                'started_effective_at' => null,
                'paused_at'            => null,
                'executor_id'          => null,
            ]);
            $prev->executors()->detach();
            ProductionStepExecutorStatus::where('step_id', $prev->id)->delete();
            $prev->timeLogs()->delete(); // timer kembali 00:00:00 — operator mulai sesi baru

            // Order harus tetap aktif agar antrean memunculkan langkah sebelumnya.
            if (!in_array($order->status, ['confirmed', 'in_progress', 'partial'])) {
                $order->update(['status' => 'in_progress']);
            }
        });
    }

    /**
     * Finalisasi produksi selesai → stock IN output + jurnal closing WIP.
     *
     * Untuk preorder yang sebelumnya soft-confirmed via DP, material BELUM dikonsumsi
     * di tahap confirm. Di sini kami:
     *  1. Pre-check stok untuk material yang qty_consumed-nya masih 0.
     *  2. Jika ada yang kurang → status order diubah ke 'pending' dan finalisasi DITOLAK.
     *     Setelah stok dilengkapi (pembelian/adjustment), user bisa coba finalisasi lagi.
     *  3. Jika cukup → konsumsi material via FIFO + post jurnal Dr. WIP / Cr. Persediaan,
     *     lalu lanjut ke proses finalisasi standar (output ke persediaan + closing WIP).
     */
    /**
     * Normalisasi alokasi gudang untuk satu baris output finalisasi.
     *  • Tanpa alokasi (kiriman lama / form tanpa split) → semua qty ke gudang order ($defaultWarehouseId).
     *  • Beberapa baris gudang sama → digabung.
     *  • Total alokasi WAJIB sama dengan qty hasil produksi (toleransi float) — kalau tidak → throw.
     *
     * @return array<int,array{warehouse_id:int,qty:float}>
     */
    private function resolveOutputAllocations(array $out, float $qtyProduced, int $defaultWarehouseId): array
    {
        $raw = $out['allocations'] ?? null;

        $byWarehouse = [];
        if (is_array($raw)) {
            foreach ($raw as $a) {
                $wid = (int) ($a['warehouse_id'] ?? 0);
                $qty = (float) ($a['qty'] ?? 0);
                if ($wid <= 0 || $qty <= 0) continue;
                $byWarehouse[$wid] = ($byWarehouse[$wid] ?? 0) + $qty;
            }
        }

        if (empty($byWarehouse)) {
            return [['warehouse_id' => $defaultWarehouseId, 'qty' => $qtyProduced]];
        }

        $sum = array_sum($byWarehouse);
        if (abs($sum - $qtyProduced) > 1e-6) {
            $fmt = fn($n) => rtrim(rtrim(number_format($n, 4, ',', '.'), '0'), ',');
            throw new Exception(
                "Total alokasi gudang ({$fmt($sum)}) tidak sama dengan qty hasil produksi ({$fmt($qtyProduced)}). " .
                "Pastikan jumlah qty tiap gudang pas dengan qty terpakai."
            );
        }

        $result = [];
        foreach ($byWarehouse as $wid => $qty) {
            $result[] = ['warehouse_id' => $wid, 'qty' => $qty];
        }
        return $result;
    }

    /**
     * Ringkasan WIP order untuk layar finalisasi & penyelesaian sebagian.
     *
     * @return array{total: float, released: float, remaining: float, reserved_byproduct: float, main_pool: float, released_qty: array<int,float>}
     */
    public function wipSummary(int $orderId): array
    {
        $order = ProductionOrder::with('outputs')->findOrFail($orderId);

        $total    = $this->getWipCost($orderId);
        $released = (float) ProductionFinalization::where('production_order_id', $orderId)
            ->whereNull('voided_at')
            ->sum('wip_released');

        $tallies   = $this->releasedTallies($orderId);
        $remaining = max(0.0, $total - $released);
        $reserved  = $this->reservedByproductCost($order, $total, $tallies['cost']);

        return [
            'total'              => $total,
            'released'           => $released,
            'remaining'          => $remaining,
            'reserved_byproduct' => $reserved,
            'main_pool'          => max(0.0, $remaining - $reserved),
            'released_qty'       => $tallies['qty'],
        ];
    }

    /**
     * Revisi target produksi pada OP yang sedang berjalan.
     *
     * Angka rencana adalah PEMBAGI penyelesaian sebagian (sisa WIP ÷ sisa qty), jadi begitu
     * qty nyata menyimpang — mis. operator memotong 10 lembar padahal rencana 8 — pembaginya
     * harus ikut dikoreksi supaya batch berikutnya tidak salah harga. Kalau penyimpangannya
     * baru ketahuan di batch penutup, revisi tidak perlu: batch penutup menyapu sisa WIP.
     *
     * SENGAJA TIDAK menyentuh kebutuhan material. Bahan yang benar-benar keluar gudang dicatat
     * lewat Penambahan Bahan; kalau qty_required ikut dinaikkan di sini, finalisasi akan
     * menarik selisihnya sekali lagi dari stok (konsumsi ganda).
     *
     * @param  array{planned_qty?: mixed, planned_cycles?: mixed, reason?: mixed, outputs?: array}  $data
     */
    public function reviseTarget(int $orderId, array $data): ProductionTargetRevision
    {
        return DB::transaction(function () use ($orderId, $data) {
            $order = ProductionOrder::with('outputs.product')->lockForUpdate()->findOrFail($orderId);

            if (!in_array($order->status, ['confirmed', 'in_progress', 'partial', 'pending', 'completed'], true)) {
                throw new Exception('Target hanya bisa direvisi selama order produksi belum ditutup.');
            }

            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new Exception('Alasan revisi target wajib diisi.');
            }

            $newCycles = (float) ($data['planned_cycles'] ?? $order->planned_cycles);
            if ($newCycles <= 0) {
                throw new Exception('Jumlah siklus harus lebih dari 0.');
            }

            $tallies       = $this->releasedTallies($orderId);
            $outputsBefore = [];
            $outputsAfter  = [];
            $mainQtyAfter  = 0.0;

            foreach ($order->outputs as $out) {
                $outputsBefore[] = ['output_id' => $out->id, 'qty_planned' => (float) $out->qty_planned];
            }

            foreach ($data['outputs'] ?? [] as $row) {
                $rec = $order->outputs->firstWhere('id', $row['output_id'] ?? null);
                if (!$rec) continue;

                $newQty = (float) ($row['qty_planned'] ?? 0);
                if ($newQty < 0) {
                    throw new Exception('Target qty tidak boleh negatif.');
                }

                // Target tidak boleh turun di bawah qty yang SUDAH masuk stok — sisa qty akan
                // jadi nol/negatif dan pembagi alokasi partial berikutnya rusak.
                $released = (float) ($tallies['qty'][$rec->id] ?? 0);
                if ($newQty + 1e-9 < $released) {
                    $name = $rec->product?->name ?? 'Output';
                    throw new Exception(
                        "Target {$name} tidak boleh di bawah qty yang sudah masuk stok (" .
                        rtrim(rtrim(number_format($released, 4, ',', '.'), '0'), ',') . ")."
                    );
                }

                $rec->update(['qty_planned' => $newQty]);
            }

            $order->refresh()->load('outputs');
            foreach ($order->outputs as $out) {
                $outputsAfter[] = ['output_id' => $out->id, 'qty_planned' => (float) $out->qty_planned];
                if ($out->output_type !== 'by_product') {
                    $mainQtyAfter += (float) $out->qty_planned;
                }
            }

            $fromQty    = (float) $order->planned_qty;
            $fromCycles = (float) $order->planned_cycles;

            // planned_qty order mengikuti total target produk utama bila tidak dikirim eksplisit.
            $newQtyTotal = array_key_exists('planned_qty', $data) && $data['planned_qty'] !== null && $data['planned_qty'] !== ''
                ? (float) $data['planned_qty']
                : $mainQtyAfter;

            $order->update([
                'planned_qty'    => $newQtyTotal,
                'planned_cycles' => $newCycles,
            ]);

            return ProductionTargetRevision::create([
                'production_order_id' => $order->id,
                'from_planned_qty'    => $fromQty,
                'to_planned_qty'      => $newQtyTotal,
                'from_planned_cycles' => $fromCycles,
                'to_planned_cycles'   => $newCycles,
                'outputs_before'      => $outputsBefore,
                'outputs_after'       => $outputsAfter,
                'reason'              => $reason,
                'user_id'             => auth()->id(),
            ]);
        });
    }

    /**
     * Finalisasi PENUTUP: melepas seluruh SISA WIP ke stok lalu menutup order.
     *
     * Aturan alokasi (berlaku untuk semua batch): setiap rupiah WIP hanya dibebankan ke unit
     * yang BELUM keluar. Batch penutup karena itu menyapu sisa WIP berapa pun qty-nya —
     * kekurangan qty otomatis menaikkan HPP unit terakhir, kelebihan qty menurunkannya.
     */
    public function finalize(int $orderId, array $actualOutputs): void
    {
        $this->releaseOutputs($orderId, $actualOutputs, closing: true);
    }

    /**
     * PENYELESAIAN SEBAGIAN: ambil hasil yang sudah jadi lebih dulu (mis. mengejar batas
     * kirim marketplace) sementara sisanya masih dikerjakan.
     *
     * Timer & langkah TIDAK disentuh sama sekali — order hanya berpindah ke status 'partial'
     * dan tetap dihitung sebagai produksi aktif. Biaya yang dilepas dihitung dari sisa WIP
     * dibagi sisa qty, setelah jatah produk sampingan disisihkan lebih dulu.
     */
    public function finalizePartial(int $orderId, array $actualOutputs): void
    {
        $this->releaseOutputs($orderId, $actualOutputs, closing: false);
    }

    /**
     * Mesin bersama finalisasi penutup & penyelesaian sebagian.
     *
     * Tiap pelepasan tercatat sebagai satu batch (production_finalizations) lengkap dengan
     * jurnal, FIFO layer, dan rincian per output-nya sendiri, sehingga bisa dibatalkan
     * per batch (LIFO) tanpa mengganggu batch lain.
     */
    private function releaseOutputs(int $orderId, array $actualOutputs, bool $closing): void
    {
        // Pre-flight di luar transaksi: cek status & ketersediaan stok untuk material tertunda.
        // Kalau gagal, kita ingin status 'pending' tetap tersimpan walau finalize dibatalkan.
        $order = ProductionOrder::with(['materials.product', 'steps'])->findOrFail($orderId);

        if ($closing) {
            if (!in_array($order->status, ['completed', 'pending', 'partial'], true)) {
                throw new Exception('Order produksi belum siap difinalisasi (harus berstatus Menunggu Finalisasi, Menunggu Stok, atau Selesai Sebagian).');
            }

            // Self-heal: order LAMA (sebelum ada batch) yang pernah difinalisasi lalu di-void
            // meninggalkan jurnal 'production_order_finalize' yang masih posted. Tandai void
            // supaya pengecekan double-posting tidak memblokir finalisasi ulang.
            \App\Core\Journal\Journal::where('reference_type', 'production_order_finalize')
                ->where('reference_id', $orderId)
                ->where('status', '!=', 'void')
                ->update(['status' => 'void', 'voided_at' => now()]);
        } else {
            $this->assertPartialAllowed($order);
        }

        // Material yang masih punya sisa belum dikonsumsi (termasuk sebagian, akibat merge).
        //
        // Penyelesaian sebagian pun mengkonsumsi SELURUH sisa material, bukan proporsional:
        // biaya per unit dihitung dari WIP dibagi qty rencana, jadi basis biayanya harus sudah
        // lengkap di WIP. Bahan yang benar-benar bertambah di tengah jalan masuk lewat
        // Penambahan Bahan, bukan lewat jalur ini.
        $unconsumed = $order->materials->filter(fn($m) => (float) $m->qty_consumed < (float) $m->qty_required - 1e-9);

        if ($unconsumed->isNotEmpty()) {
            $engine = app(InventoryEngine::class);
            $insufficient = [];
            $materialWarehouseId = $this->materialWarehouseId($order);

            foreach ($unconsumed as $material) {
                $available = (float) $engine->availableStock(
                    (int) $material->product_id,
                    (int) $materialWarehouseId
                );
                // Hanya butuh sisa yang belum dikonsumsi.
                $required = (float) $material->qty_required - (float) $material->qty_consumed;

                if ($available < $required) {
                    $insufficient[] = [
                        'sku'       => $material->product->sku ?? '-',
                        'name'      => $material->product->name ?? '-',
                        'required'  => $required,
                        'available' => $available,
                        'short'     => $required - $available,
                    ];
                }
            }

            if (!empty($insufficient)) {
                // Tandai pending agar UI menampilkan kondisi blokir + memberi entry point retry.
                // Pada penyelesaian sebagian status TIDAK diubah — order harus tetap berada di
                // papan proses karena sisa unitnya masih dikerjakan.
                if ($closing) {
                    $order->update(['status' => 'pending']);
                }

                $details = collect($insufficient)
                    ->map(fn($i) => "• {$i['sku']} {$i['name']} — butuh " .
                        rtrim(rtrim(number_format($i['required'], 4, ',', '.'), '0'), ',') .
                        ", tersedia " .
                        rtrim(rtrim(number_format($i['available'], 4, ',', '.'), '0'), ',') .
                        ", kurang " .
                        rtrim(rtrim(number_format($i['short'], 4, ',', '.'), '0'), ','))
                    ->join("\n");

                $head = $closing
                    ? 'Finalisasi ditolak. Stok material belum mencukupi (status diubah ke Menunggu Stok). '
                    : 'Penyelesaian sebagian ditolak. Stok material belum mencukupi. ';

                throw new Exception(
                    $head . "Lengkapi pembelian/adjustment untuk:\n{$details}"
                );
            }
        }

        DB::transaction(function () use ($orderId, $actualOutputs, $closing) {
            $order = ProductionOrder::with(['outputs.product', 'materials'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            // Konsumsi tertunda: untuk preorder soft-confirmed, retry dari pending, atau
            // sisa material hasil merge (terkonsumsi sebagian) di-FIFO sekarang.
            $unconsumed = $order->materials->filter(fn($m) => (float) $m->qty_consumed < (float) $m->qty_required - 1e-9);
            if ($unconsumed->isNotEmpty()) {
                $this->consumeOrderMaterials($order, $unconsumed);
            }

            // ── Basis biaya ──
            // WIP keseluruhan OP (seumur hidup) dikurangi yang sudah dilepas batch sebelumnya.
            $totalWip = $this->getWipCost($orderId);
            $released = (float) ProductionFinalization::where('production_order_id', $orderId)
                ->whereNull('voided_at')
                ->sum('wip_released');
            $sisaWip  = max(0.0, $totalWip - $released);

            // Baris output yang benar-benar dilepas di batch ini.
            $rows = [];
            foreach ($actualOutputs as $out) {
                $rec = $order->outputs->firstWhere('id', $out['output_id'] ?? null);
                if (!$rec) continue;
                $qty = (float) ($out['qty_produced'] ?? 0);
                if ($qty <= 0) continue;
                $rows[] = ['rec' => $rec, 'input' => $out, 'qty' => $qty];
            }

            if ($rows === []) {
                throw new Exception('Qty output harus lebih dari 0.');
            }

            $tallies = $this->releasedTallies($orderId);

            $costs = $closing
                ? $this->allocateClosingCost($order, $rows, $totalWip, $sisaWip)
                : $this->allocatePartialCost($order, $rows, $totalWip, $sisaWip, $tallies);

            $sequence = (int) (ProductionFinalization::where('production_order_id', $orderId)->max('sequence') ?? 0) + 1;

            $batch = ProductionFinalization::create([
                'production_order_id' => $orderId,
                'sequence'            => $sequence,
                'is_closing'          => $closing,
                'wip_released'        => 0,
                'wip_total_snapshot'  => $totalWip,
                'created_by'          => auth()->id(),
            ]);

            $totalOutputCost = 0.0;
            $fifo = app(FifoService::class);

            foreach ($rows as $row) {
                $outputRecord = $row['rec'];
                $qtyProduced  = $row['qty'];
                $itemCost     = (float) ($costs[$outputRecord->id]['cost'] ?? 0);
                $pct          = $costs[$outputRecord->id]['pct'] ?? null;

                // Alokasi hasil produksi ke satu/beberapa gudang. Fallback: semua ke gudang order
                // (default Utama). Biaya per unit seragam lintas gudang (produk & WIP sama).
                $allocations = $this->resolveOutputAllocations($row['input'], $qtyProduced, (int) $order->warehouse_id);
                $unitCost    = $qtyProduced > 0 ? $itemCost / $qtyProduced : 0;

                // qty_produced = AKUMULASI qty yang sudah masuk stok lintas batch, bukan angka
                // batch ini saja — supaya semua layar lama (kartu output, laporan) tetap benar.
                $releasedBefore = (float) ($tallies['qty'][$outputRecord->id] ?? 0);

                $updatePayload = [
                    'qty_produced'          => $releasedBefore + $qtyProduced,
                    'warehouse_allocations' => $allocations,
                ];
                if (array_key_exists('variance_notes', $row['input'])) {
                    $updatePayload['variance_notes'] = $row['input']['variance_notes'];
                }
                if ($pct !== null) {
                    $updatePayload['percentage'] = round($pct, 4);
                }
                $outputRecord->update($updatePayload);

                // Stock IN: output masuk persediaan via FIFO, dipecah per gudang sesuai alokasi.
                // Tiap layer ditandai batch-nya supaya pembatalan batch tidak menyentuh layer
                // batch lain (source_id dipakai bersama oleh semua batch pada satu OP).
                foreach ($allocations as $alloc) {
                    $layer = $fifo->stockIn(
                        productId:     $outputRecord->product_id,
                        warehouseId:   $alloc['warehouse_id'],
                        type:          'production_order',
                        reference:     $order->order_number,
                        qty:           $alloc['qty'],
                        cost:          $unitCost,
                        transactionId: $order->id
                    );

                    if ($layer) {
                        $layer->update(['production_finalization_id' => $batch->id]);
                    }
                }

                // Untuk custom order: tag FIFO layer dengan sales_order_id
                if ($order->type === 'custom' && $order->sales_order_id) {
                    StockLayer::where('production_finalization_id', $batch->id)
                        ->where('product_id', $outputRecord->product_id)
                        ->update(['sales_order_id' => $order->sales_order_id]);
                }

                ProductionFinalizationItem::create([
                    'production_finalization_id' => $batch->id,
                    'production_order_output_id' => $outputRecord->id,
                    'product_id'                 => $outputRecord->product_id,
                    'qty'                        => $qtyProduced,
                    'cost'                       => $itemCost,
                    'unit_cost'                  => $unitCost,
                    'percentage'                 => $pct !== null ? round($pct, 4) : null,
                    'warehouse_allocations'      => $allocations,
                    'variance_notes'             => $row['input']['variance_notes'] ?? null,
                ]);

                $totalOutputCost += $itemCost;
            }

            // Jurnal: Dr. Persediaan / Cr. WIP — per batch, bukan per order.
            if ($totalOutputCost > 0) {
                $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
                $inventoryAccount = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();

                $journal = app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:             now()->format('Y-m-d'),
                    reference_type:   'production_order_release',
                    reference_id:     $batch->id,
                    description:      ($closing ? 'Produksi Selesai - ' : 'Produksi Selesai Sebagian - ') . $order->order_number,
                    lines: [
                        new JournalLineDTO(
                            account_id:  $inventoryAccount->id,
                            debit:       (float) $totalOutputCost,
                            credit:      0,
                            description: 'Output produksi masuk persediaan'
                        ),
                        new JournalLineDTO(
                            account_id:  $wipAccount->id,
                            debit:       0,
                            credit:      (float) $totalOutputCost,
                            description: $closing ? 'Closing WIP produksi' : 'Pelepasan sebagian WIP produksi'
                        ),
                    ],
                    reference_number: $closing ? $order->order_number : "{$order->order_number}/{$sequence}"
                ));

                $batch->journal_id = $journal->id;
            }

            $batch->wip_released = $totalOutputCost;
            $batch->save();

            if (!$closing) {
                // Produksi masih berjalan: langkah & timer tidak disentuh sama sekali.
                $order->update(['status' => 'partial']);
                return;
            }

            $order->update(['status' => 'finalized', 'finalized_at' => now()]);

            // Task yang diserap lewat penggabungan ikut ditandai Selesai — pekerjaannya memang
            // sudah dikerjakan & masuk stok lewat induk ini. Tanpa ini status 'merged' menggantung
            // selamanya dan masih dianggap "produksi aktif" oleh AutoProductionService dan
            // pengecekan kesiapan SO (FulfillmentReadinessService), sehingga produk yang habis
            // tidak pernah memicu OP otomatis lagi.
            $this->syncMergedChildrenStatus($order->refresh());

            // Auto-advance dokumen sumber Perbaikan → "Selesai Diperbaiki".
            // Garansi: received/posted → repaired, sehingga SJ Garansi bisa langsung dibuat.
            if ($order->isRepairLike()
                && $order->repair_source_type === 'warranty'
                && $order->repair_source_id) {
                app(\App\Modules\Sales\Services\WarrantyOrderService::class)
                    ->markRepairedFromProduction((int) $order->repair_source_id);
            }
        });
    }

    /**
     * Penyelesaian sebagian hanya boleh di AKHIR proses: semua langkah sebelum langkah
     * terakhir sudah selesai dan langkah terakhir sedang dikerjakan/dijeda.
     *
     * Sengaja TIDAK menutup langkah: sisa unit masih dikerjakan di langkah yang sama, jadi
     * timer harus terus berjalan tanpa event pause/complete palsu.
     */
    private function assertPartialAllowed(ProductionOrder $order): void
    {
        if (!in_array($order->status, ['in_progress', 'partial'], true)) {
            throw new Exception('Penyelesaian sebagian hanya bisa dilakukan saat produksi sedang berjalan.');
        }

        if ($order->merged_into_id !== null) {
            throw new Exception(
                "Task {$order->order_number} adalah hasil penggabungan — penyelesaian sebagian dilakukan di task induknya."
            );
        }

        $steps = $order->steps->sortBy('step_number')->values();
        if ($steps->isEmpty()) {
            throw new Exception('Order produksi belum punya langkah kerja.');
        }

        $last = $steps->last();
        if (!in_array($last->status, ['in_progress', 'paused'], true)) {
            throw new Exception(
                "Penyelesaian sebagian hanya bisa saat langkah terakhir ({$last->name}) sedang dikerjakan."
            );
        }

        $before = $steps->slice(0, $steps->count() - 1);
        if ($before->contains(fn($s) => $s->status !== 'completed')) {
            throw new Exception('Masih ada langkah sebelum langkah terakhir yang belum selesai — penyelesaian sebagian hanya di akhir proses.');
        }
    }

    /**
     * Qty & biaya yang sudah dilepas ke stok per baris output (batch aktif saja).
     *
     * @return array{qty: array<int,float>, cost: array<int,float>}
     */
    private function releasedTallies(int $orderId): array
    {
        $rows = ProductionFinalizationItem::query()
            ->join('production_finalizations as f', 'f.id', '=', 'production_finalization_items.production_finalization_id')
            ->where('f.production_order_id', $orderId)
            ->whereNull('f.voided_at')
            ->selectRaw('production_order_output_id as oid, SUM(qty) as total_qty, SUM(cost) as total_cost')
            ->groupBy('production_order_output_id')
            ->get();

        $qty = [];
        $cost = [];
        foreach ($rows as $r) {
            $qty[(int) $r->oid]  = (float) $r->total_qty;
            $cost[(int) $r->oid] = (float) $r->total_cost;
        }

        return ['qty' => $qty, 'cost' => $cost];
    }

    /**
     * Alokasi biaya batch PENUTUP.
     *
     *  • Produk sampingan mengambil persentasenya dari WIP KESELURUHAN order (bukan dari sisa
     *    WIP), sesuai kesepakatan: jatah sampingan lahir dari seluruh proses, bukan dari
     *    sisa di akhir. Dengan BOM, persentase di-recompute dari qty aktual sehingga sampingan
     *    yang rusak otomatis mengembalikan jatahnya ke produk utama.
     *  • Produk utama menyerap SELURUH sisa WIP setelah jatah sampingan → WIP order pasti nol.
     *
     * @param  array<int,array{rec: ProductionOrderOutput, input: array, qty: float}>  $rows
     * @return array<int,array{cost: float, pct: float|null}>  keyed by output id
     */
    private function allocateClosingCost(ProductionOrder $order, array $rows, float $totalWip, float $sisaWip): array
    {
        // Order repair-like (perbaikan/garansi/repair) pakai alokasi RASIO QTY = nilai
        // (material + biaya tambahan) dibagi RATA PER UNIT output. Tidak ada konsep %
        // sampingan. Sesuai aturan: biaya perbaikan/penggantian komponen dibagi rata.
        $usePctMode = !$order->isRepairLike()
            && $order->outputs->contains(fn($o) => $o->output_type === 'by_product');

        $result = [];

        if (!$usePctMode) {
            $totalQty = array_sum(array_column($rows, 'qty'));
            foreach ($rows as $row) {
                $result[$row['rec']->id] = [
                    'cost' => $totalQty > 0 ? $sisaWip * $row['qty'] / $totalQty : 0.0,
                    'pct'  => null,
                ];
            }
            return $result;
        }

        $cycles   = max(1e-9, (float) ($order->planned_cycles ?: 1));
        $bomFixed = $order->bom_id !== null;

        // ── Produk sampingan: persentase × WIP keseluruhan ──
        $sumBp = 0.0;
        foreach ($rows as $row) {
            $rec = $row['rec'];
            if ($rec->output_type !== 'by_product') continue;

            // BOM dgn % master → recompute dari qty. Sampingan manual (unit_percentage null)
            // tetap hormati persentase tersimpan/dikirim meski order pakai BOM.
            if ($bomFixed && $rec->unit_percentage !== null) {
                $pct = round((float) $rec->unit_percentage * ($row['qty'] / $cycles), 4);
            } else {
                $in  = $row['input'];
                $pct = (isset($in['percentage']) && $in['percentage'] !== '' && $in['percentage'] !== null)
                    ? round((float) $in['percentage'], 4)
                    : round((float) $rec->percentage, 4);
            }

            $sumBp += $pct;
            $result[$rec->id] = ['cost' => $totalWip * $pct / 100, 'pct' => $pct];
        }

        if ($sumBp > 100 + 0.01) {
            $shown = rtrim(rtrim(number_format($sumBp, 4, '.', ''), '0'), '.');
            throw new Exception("Total persentase produk sampingan melebihi 100% ({$shown}%). Periksa qty / persentase hasil produksi.");
        }

        $byproductCost = array_sum(array_column($result, 'cost'));

        // Pengaman: jatah sampingan tidak boleh melebihi sisa WIP yang benar-benar tersedia
        // (bisa terjadi bila sampingan aktual jauh melebihi rencana setelah banyak partial).
        // Tanpa clamp ini biaya produk utama bisa negatif.
        if ($byproductCost > $sisaWip && $byproductCost > 0) {
            $scale = $sisaWip / $byproductCost;
            foreach ($result as $id => $r) {
                $result[$id]['cost'] = $r['cost'] * $scale;
                $result[$id]['pct']  = $totalWip > 0 ? $result[$id]['cost'] / $totalWip * 100 : $r['pct'];
            }
            $byproductCost = $sisaWip;
        }

        // ── Produk utama: menyerap seluruh sisa ──
        $mainPool = max(0.0, $sisaWip - $byproductCost);
        $mainQty  = 0.0;
        foreach ($rows as $row) {
            if ($row['rec']->output_type !== 'by_product') {
                $mainQty += $row['qty'];
            }
        }

        foreach ($rows as $row) {
            $rec = $row['rec'];
            if ($rec->output_type === 'by_product') continue;

            $cost = $mainQty > 0 ? $mainPool * $row['qty'] / $mainQty : 0.0;
            $result[$rec->id] = [
                'cost' => $cost,
                'pct'  => $totalWip > 0 ? round($cost / $totalWip * 100, 4) : null,
            ];
        }

        return $result;
    }

    /**
     * Alokasi biaya batch PARTIAL.
     *
     *   biaya batch = (sisa WIP − cadangan sampingan) × qty batch / sisa qty
     *
     * Pembaginya "sisa dibagi sisa", bukan total: biaya apa pun yang masuk belakangan
     * (mis. Penambahan Bahan) otomatis hanya membebani unit yang belum keluar, dan batch
     * penutup pasti menghabiskan WIP tanpa penyesuaian manual.
     *
     * @param  array<int,array{rec: ProductionOrderOutput, input: array, qty: float}>  $rows
     * @return array<int,array{cost: float, pct: float|null}>  keyed by output id
     */
    private function allocatePartialCost(ProductionOrder $order, array $rows, float $totalWip, float $sisaWip, array $tallies): array
    {
        // Sampingan hanya dicatat di batch penutup: persentasenya baru pasti setelah seluruh
        // produksi selesai (mis. sampingan yang rusak mengembalikan jatah ke produk utama).
        foreach ($rows as $row) {
            if ($row['rec']->output_type === 'by_product') {
                throw new Exception(
                    'Produk sampingan hanya bisa dicatat saat finalisasi penutup. Pada penyelesaian sebagian isi qty produk utama saja.'
                );
            }
        }

        $cadangan = $this->reservedByproductCost($order, $totalWip, $tallies['cost']);
        $pool     = max(0.0, $sisaWip - $cadangan);

        // Sisa qty menurut TARGET TERKINI (bisa berubah lewat Revisi Target) dikurangi
        // yang sudah dilepas batch sebelumnya.
        $sisaQty = 0.0;
        foreach ($order->outputs as $o) {
            if ($o->output_type === 'by_product') continue;
            $sisaQty += max(0.0, (float) $o->qty_planned - (float) ($tallies['qty'][$o->id] ?? 0));
        }

        $batchQty = array_sum(array_column($rows, 'qty'));

        // Clamp: qty batch yang melebihi sisa target tidak boleh menarik biaya lebih dari
        // sisa WIP yang tersedia. Selisih targetnya diperbaiki lewat Revisi Target.
        $ratio     = $sisaQty > 1e-9 ? min(1.0, $batchQty / $sisaQty) : 1.0;
        $batchCost = $pool * $ratio;

        $result = [];
        foreach ($rows as $row) {
            $result[$row['rec']->id] = [
                'cost' => $batchQty > 0 ? $batchCost * $row['qty'] / $batchQty : 0.0,
                'pct'  => null,
            ];
        }

        return $result;
    }

    /**
     * Jatah biaya produk sampingan yang harus DISISIHKAN selama produksi masih berjalan.
     *
     * Basisnya WIP keseluruhan (bukan sisa WIP), sesuai aturan alokasi sampingan. Tanpa
     * cadangan ini batch partial pertama ikut menyedot jatah yang bukan miliknya, sehingga
     * unit yang identik berbeda HPP hanya karena urutan keluarnya.
     *
     * @param  array<int,float>  $releasedCost  biaya sampingan yang sudah terlanjur dilepas
     */
    private function reservedByproductCost(ProductionOrder $order, float $totalWip, array $releasedCost): float
    {
        if ($order->isRepairLike()) {
            return 0.0;
        }

        $cycles   = max(1e-9, (float) ($order->planned_cycles ?: 1));
        $bomFixed = $order->bom_id !== null;
        $pct      = 0.0;
        $already  = 0.0;

        foreach ($order->outputs as $o) {
            if ($o->output_type !== 'by_product') continue;

            // Sumber persentase harus SAMA dengan yang dipakai batch penutup, kalau tidak
            // cadangannya meleset dari jatah yang benar-benar diambil nanti:
            //  • order ber-BOM  → % per unit PER SIKLUS × qty rencana (menaikkan siklus otomatis
            //    mengecilkan jatah per pcs, jadi total jatah tetap proporsional terhadap WIP).
            //  • tanpa BOM      → persentase manual yang tersimpan di baris output.
            $pct += ($bomFixed && $o->unit_percentage !== null)
                ? (float) $o->unit_percentage * ((float) $o->qty_planned / $cycles)
                : (float) $o->percentage;

            $already += (float) ($releasedCost[$o->id] ?? 0);
        }

        $reserved = $totalWip * min(100.0, max(0.0, $pct)) / 100;

        return max(0.0, $reserved - $already);
    }

    /**
     * Samakan status task anak hasil penggabungan dengan induknya.
     *
     * Induk 'finalized' → anak ikut 'finalized' (+ finalized_at sama): pekerjaannya sudah
     * dikerjakan dan hasilnya masuk stok atas nama induk, jadi anak tidak boleh terus
     * dihitung sebagai produksi yang masih berjalan.
     * Induk kembali aktif (void / edit finalisasi) → anak dikembalikan ke 'merged'.
     *
     * Rantai penggabungan bertingkat (anak dari anak) ikut ditelusuri. Hanya baris berstatus
     * 'merged'/'finalized' yang disentuh, sehingga anak yang pernah dibatalkan tidak terbawa.
     */
    private function syncMergedChildrenStatus(ProductionOrder $induk): void
    {
        $isFinal     = $induk->status === 'finalized';
        $status      = $isFinal ? 'finalized' : 'merged';
        $finalizedAt = $isFinal ? $induk->finalized_at : null;

        $parentIds = [$induk->id];
        $depth     = 0;

        while ($parentIds !== [] && $depth++ < 20) {
            $childIds = ProductionOrder::whereIn('merged_into_id', $parentIds)
                ->whereIn('status', ['merged', 'finalized'])
                ->pluck('id')
                ->all();

            if ($childIds === []) {
                break;
            }

            ProductionOrder::whereIn('id', $childIds)->update([
                'status'       => $status,
                'finalized_at' => $finalizedAt,
                'updated_at'   => now(),
            ]);

            $parentIds = $childIds;
        }
    }

    /**
     * Batch pelepasan hasil terakhir yang masih berlaku (null bila order lama tanpa batch).
     */
    private function latestActiveBatch(int $orderId): ?ProductionFinalization
    {
        return ProductionFinalization::with('items')
            ->where('production_order_id', $orderId)
            ->whereNull('voided_at')
            ->orderByDesc('sequence')
            ->first();
    }

    /**
     * Batalkan pelepasan hasil TERAKHIR.
     *
     * Order yang hasilnya diambil bertahap dibatalkan mundur satu per satu (LIFO): alokasi
     * biaya tiap batch dihitung dari sisa WIP saat itu, jadi membatalkan batch tengah akan
     * membuat batch sesudahnya salah harga.
     */
    public function void(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = ProductionOrder::with(['outputs', 'steps'])->lockForUpdate()->findOrFail($orderId);

            if ($order->merged_into_id !== null) {
                throw new Exception(
                    "Task {$order->order_number} adalah hasil penggabungan — void dilakukan di task induknya."
                );
            }

            $batch = $this->latestActiveBatch($orderId);

            if ($batch) {
                $this->reverseBatch($order, $batch);
                return;
            }

            // Order lama (difinalisasi sebelum fitur batch) — jalur reverse tunggal.
            if ($order->status !== 'finalized') {
                throw new Exception('Hanya order yang sudah difinalisasi yang dapat di-void.');
            }

            $this->reverseFinalizationInternal($order);

            // Reset order ke 'pending' agar admin bisa re-finalize tanpa menerobos ulang ke antrean step.
            // - Output qty_produced & variance_notes TIDAK direset → kerja operator dipertahankan.
            // - Last step TIDAK dibalik ke pending → step memang sudah selesai dikerjakan.
            // Yang dibatalkan oleh void hanyalah finalisasi (FIFO stockIn + jurnal closing WIP), bukan kerja produksinya.
            $order->update(['status' => 'pending', 'finalized_at' => null]);

            // Induk tidak lagi selesai → anak gabungan dikembalikan ke status 'Digabung'.
            $this->syncMergedChildrenStatus($order->refresh());
        });
    }

    /**
     * Batalkan satu batch tertentu (dipanggil dari riwayat pengambilan di halaman order).
     * Hanya batch terakhir yang boleh dibatalkan.
     */
    public function voidBatch(int $finalizationId): void
    {
        DB::transaction(function () use ($finalizationId) {
            $batch = ProductionFinalization::with('items')->lockForUpdate()->findOrFail($finalizationId);

            if ($batch->voided_at !== null) {
                throw new Exception('Pengambilan ini sudah dibatalkan sebelumnya.');
            }

            $order = ProductionOrder::with(['outputs', 'steps'])
                ->lockForUpdate()
                ->findOrFail($batch->production_order_id);

            if ($order->merged_into_id !== null) {
                throw new Exception(
                    "Task {$order->order_number} adalah hasil penggabungan — pembatalan dilakukan di task induknya."
                );
            }

            $latest = $this->latestActiveBatch($order->id);
            if (!$latest || $latest->id !== $batch->id) {
                throw new Exception(
                    "Hanya pengambilan terakhir yang bisa dibatalkan — biaya batch sesudahnya dihitung dari sisa WIP setelah batch ini. " .
                    "Batalkan {$latest?->label()} lebih dulu."
                );
            }

            $this->reverseBatch($order, $batch);
        });
    }

    /**
     * Balik efek satu batch: jurnal, ledger, FIFO layer, qty kumulatif, lalu status order.
     * Asumsi: $order & $batch sudah di-lockForUpdate dan $batch adalah batch aktif terakhir.
     */
    private function reverseBatch(ProductionOrder $order, ProductionFinalization $batch): void
    {
        // Layer batch ini harus masih utuh — kalau stoknya sudah dipakai dokumen lain,
        // membalik akan mengorupsi FIFO.
        $layers = StockLayer::where('production_finalization_id', $batch->id)->get();

        foreach ($layers as $layer) {
            if ((float) $layer->qty_remaining < (float) $layer->qty_in) {
                throw new Exception(
                    "Stok hasil {$batch->label()} sudah sebagian terpakai sehingga tidak bisa dibatalkan. Batalkan dulu dokumen yang memakai stok ini."
                );
            }
        }

        // Jurnal balik: Dr. WIP / Cr. Persediaan — biaya kembali menggantung di WIP dan
        // otomatis jadi jatah unit yang belum keluar.
        if ((float) $batch->wip_released > 0) {
            $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
            $inventoryAccount = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();

            $journal = app(JournalPostingService::class)->post(new JournalEntryDTO(
                date:             now()->format('Y-m-d'),
                reference_type:   'production_order_release_void',
                reference_id:     $batch->id,
                description:      "Batal {$batch->label()} Produksi - {$order->order_number}",
                lines: [
                    new JournalLineDTO(
                        account_id:  $wipAccount->id,
                        debit:       (float) $batch->wip_released,
                        credit:      0,
                        description: 'Balik WIP dari pembatalan pelepasan hasil'
                    ),
                    new JournalLineDTO(
                        account_id:  $inventoryAccount->id,
                        debit:       0,
                        credit:      (float) $batch->wip_released,
                        description: 'Balik persediaan dari pembatalan pelepasan hasil'
                    ),
                ],
                reference_number: "{$order->order_number}/{$batch->sequence}"
            ));

            $batch->void_journal_id = $journal->id;
        }

        // Balancing qty_out ke inventory_ledgers supaya saldo & product_stocks kembali seperti
        // sebelum batch ini (hapus StockLayer saja tidak cukup — ledger sumber terpisah).
        $engine = app(InventoryEngine::class);
        foreach ($layers as $layer) {
            $qty = (float) $layer->qty_in;
            if ($qty <= 0) continue;

            $engine->ledger(
                productId:     (int) $layer->product_id,
                warehouseId:   (int) $layer->warehouse_id,
                qtyIn:         0,
                qtyOut:        $qty,
                type:          'production_order_void',
                reference:     $order->order_number,
                notes:         null,
                transactionId: $order->id
            );
        }

        StockLayer::where('production_finalization_id', $batch->id)->delete();

        // Jurnal pelepasan batch ini ditandai void supaya tidak ikut terhitung lagi.
        if ($batch->journal_id) {
            \App\Core\Journal\Journal::where('id', $batch->journal_id)
                ->where('status', '!=', 'void')
                ->update(['status' => 'void', 'voided_at' => now()]);
        }

        // qty_produced kumulatif dikurangi porsi batch ini.
        foreach ($batch->items as $item) {
            $output = $order->outputs->firstWhere('id', $item->production_order_output_id);
            if (!$output) continue;

            $output->update([
                'qty_produced' => max(0.0, (float) $output->qty_produced - (float) $item->qty),
            ]);
        }

        $batch->voided_at = now();
        $batch->save();

        $this->recomputeStatusAfterBatchVoid($order);
    }

    /**
     * Status order setelah sebuah batch dibatalkan.
     *  • Masih ada batch aktif  → 'partial' (sebagian hasil tetap di stok, produksi belum tuntas).
     *  • Tidak ada lagi         → kembali ke antrean finalisasi bila semua langkah sudah selesai,
     *                             atau ke 'in_progress' bila langkah terakhir masih dikerjakan.
     */
    private function recomputeStatusAfterBatchVoid(ProductionOrder $order): void
    {
        $hasActive = ProductionFinalization::where('production_order_id', $order->id)
            ->whereNull('voided_at')
            ->exists();

        if ($hasActive) {
            $order->update(['status' => 'partial', 'finalized_at' => null]);
        } else {
            $steps   = $order->steps()->get();
            $allDone = $steps->isEmpty() || $steps->every(fn($s) => $s->status === 'completed');

            $order->update([
                'status'       => $allDone ? 'pending' : 'in_progress',
                'finalized_at' => null,
            ]);
        }

        // Induk tidak lagi selesai → anak gabungan dikembalikan ke status 'Digabung'.
        $this->syncMergedChildrenStatus($order->refresh());
    }

    /**
     * Balik (reverse) efek finalisasi pada FIFO & jurnal — TANPA mengubah status order.
     * Dipakai HANYA untuk order lama yang difinalisasi sebelum ada batch (tidak punya baris
     * production_finalizations), karena penandanya cuma production_order_id.
     *
     * Yang dibalik: jurnal Dr.WIP/Cr.Persediaan, balancing qty_out ledger, hapus StockLayer
     * output, dan tandai jurnal finalisasi lama 'void'. Material yang sudah dikonsumsi TIDAK
     * disentuh (WIP basis tetap), sehingga re-apply mengalokasikan ulang biaya WIP yang sama.
     *
     * Guard: bila stok output sudah sebagian terpakai downstream → throw (tidak bisa dibalik).
     * Asumsi: $order sudah di-lockForUpdate dengan relasi outputs.
     */
    private function reverseFinalizationInternal(ProductionOrder $order): void
    {
        $orderId = $order->id;

        // Cek stok belum dikonsumsi downstream
        $layers = StockLayer::where('source_type', 'production_order')
            ->where('source_id', $orderId)->get();

        foreach ($layers as $layer) {
            // Kolom layer adalah qty_in (bukan qty) — sebelumnya membandingkan dengan
            // properti null sehingga guard ini TIDAK PERNAH aktif & void bisa korup stok.
            if ((float) $layer->qty_remaining < (float) $layer->qty_in) {
                throw new Exception("Stok output sudah sebagian terpakai sehingga finalisasi tidak bisa dibalik/diedit. Batalkan dulu dokumen yang memakai stok ini.");
            }
        }

        // Self-heal: kalau order pernah di-void sebelumnya (lalu di-finalize ulang dan sekarang
        // di-void lagi), tandai jurnal void yang lama sebagai 'void' supaya pengecekan
        // double-posting tidak memblokir penulisan jurnal balik baru.
        \App\Core\Journal\Journal::where('reference_type', 'production_order_void')
            ->where('reference_id', $orderId)
            ->where('status', '!=', 'void')
            ->update(['status' => 'void', 'voided_at' => now()]);

        // Jurnal balik: Dr. WIP / Cr. Persediaan
        $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
        $inventoryAccount = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();

        // Hanya jurnal finalisasi yang masih AKTIF (status posted) yang dihitung,
        // supaya kalau order ini sebelumnya pernah finalize-void-finalize, kita reverse
        // amount dari finalize yang aktif saja — bukan akumulasi semua history.
        $inventoryCost = (float) \App\Core\Journal\JournalLine::where('account_id', $inventoryAccount->id)
            ->whereHas('journal', fn($q) => $q->where('reference_type', 'production_order_finalize')
                ->where('reference_id', $orderId)
                ->where('status', 'posted'))
            ->sum('debit');

        if ($inventoryCost > 0) {
            app(JournalPostingService::class)->post(new JournalEntryDTO(
                date:             now()->format('Y-m-d'),
                reference_type:   'production_order_void',
                reference_id:     $order->id,
                description:      "Void Produksi - {$order->order_number}",
                lines: [
                    new JournalLineDTO(
                        account_id:  $wipAccount->id,
                        debit:       $inventoryCost,
                        credit:      0,
                        description: 'Balik WIP dari void produksi'
                    ),
                    new JournalLineDTO(
                        account_id:  $inventoryAccount->id,
                        debit:       0,
                        credit:      $inventoryCost,
                        description: 'Balik persediaan dari void produksi'
                    ),
                ],
                reference_number: $order->order_number
            ));
        }

        // Tulis balancing qty_out ke inventory_ledgers untuk tiap output yang sebelumnya stockIn,
        // supaya saldo & product_stocks.qty_on_hand kembali ke kondisi sebelum finalisasi.
        // (Hanya hapus StockLayer saja tidak cukup — FIFO dan ledger adalah dua sumber yang terpisah.)
        // Sumber qty & gudang diambil dari StockLayer hasil finalisasi: ini menangani alokasi
        // multi-gudang dengan benar (qty_out dikembalikan ke gudang yang persis menerima stok),
        // sekaligus kompatibel dengan order lama yang seluruhnya masuk satu gudang.
        $engine = app(InventoryEngine::class);
        foreach ($layers as $layer) {
            $qty = (float) $layer->qty_in;
            if ($qty <= 0) continue;
            $engine->ledger(
                productId:     (int) $layer->product_id,
                warehouseId:   (int) $layer->warehouse_id,
                qtyIn:         0,
                qtyOut:        $qty,
                type:          'production_order_void',
                reference:     $order->order_number,
                notes:         null,
                transactionId: $order->id
            );
        }

        // Hapus stock layers FIFO untuk output ini (cost layer ikut hilang).
        StockLayer::where('source_type', 'production_order')
            ->where('source_id', $orderId)->delete();

        // Tandai journal finalisasi yang lama sebagai 'void' supaya pengecekan
        // double-posting tidak memblokir saat admin re-finalize.
        \App\Core\Journal\Journal::where('reference_type', 'production_order_finalize')
            ->where('reference_id', $orderId)
            ->where('status', '!=', 'void')
            ->update(['status' => 'void', 'voided_at' => now()]);
    }

    /**
     * Edit hasil pelepasan TERAKHIR (koreksi salah input): balik efek lama lalu terapkan ulang
     * dengan qty/persentase output baru — ATOMIK. FIFO (layer + ledger) DAN jurnal ikut teredit
     * dalam satu transaksi. Bila apa pun gagal, seluruh edit di-rollback dan data lama tetap utuh.
     *
     * Batch partial diedit sebagai partial, batch penutup sebagai penutup — sifat batch tidak
     * berubah karena diedit. Biaya WIP yang dikapitalisasi tidak berubah (material sudah
     * dikonsumsi), hanya dialokasikan ulang ke qty output baru.
     */
    public function editFinalization(int $orderId, array $actualOutputs): void
    {
        DB::transaction(function () use ($orderId, $actualOutputs) {
            $order = ProductionOrder::with(['outputs', 'steps'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->merged_into_id !== null) {
                throw new Exception(
                    "Task {$order->order_number} adalah hasil penggabungan — edit finalisasi dilakukan di task induknya."
                );
            }

            $batch = $this->latestActiveBatch($orderId);

            if ($batch) {
                $wasClosing = (bool) $batch->is_closing;

                // 1) Balik batch terakhir (guard "stok sudah terpakai" ada di dalam).
                $this->reverseBatch($order, $batch);

                // 2) Terapkan ulang dengan sifat batch yang sama.
                $this->releaseOutputs($orderId, $actualOutputs, $wasClosing);
                return;
            }

            // Order lama tanpa batch — jalur legacy.
            if ($order->status !== 'finalized') {
                throw new Exception('Hanya order yang sudah difinalisasi yang dapat diedit.');
            }

            $this->reverseFinalizationInternal($order);
            $order->update(['status' => 'pending', 'finalized_at' => null]);
            $this->finalize($orderId, $actualOutputs);
        });
    }

    /**
     * Batalkan order produksi.
     *  • Draft        → tandai cancelled (belum ada konsumsi stok).
     *  • Dikonfirmasi → hanya bila BELUM dikerjakan (semua langkah masih antre).
     *    Material yang sudah dikeluarkan saat konfirmasi dikembalikan ke stok
     *    (FIFO + ledger) dan dibuat jurnal balik Dr. Persediaan / Cr. WIP.
     *
     * @return bool  true bila ada material yang dikembalikan ke stok.
     */
    public function cancel(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            $order = ProductionOrder::with(['materials', 'steps', 'mergedChildren'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->status === 'draft') {
                $order->update(['status' => 'cancelled']);
                return false;
            }

            if ($order->status !== 'confirmed') {
                throw new Exception('Hanya order berstatus draft atau yang sudah dikonfirmasi tapi belum dikerjakan yang dapat dibatalkan.');
            }

            // Guard penggabungan: order hasil/sumber merge tidak bisa dibatalkan di sini.
            if ($order->merged_into_id !== null || $order->mergedChildren->isNotEmpty()) {
                throw new Exception('Order ini terkait penggabungan task — batalkan lewat alur penggabungan, bukan di sini.');
            }

            // Guard "masih antre": begitu satu langkah mulai dikerjakan, batal tidak boleh.
            $started = $order->steps->first(
                fn ($s) => $s->status !== 'pending' || $s->started_at !== null
            );
            if ($started) {
                throw new Exception('Order sudah mulai dikerjakan (langkah ' . $started->step_number . ' tidak lagi antre) — tidak bisa dibatalkan.');
            }

            // Guard penambahan bahan/biaya: reverseConfirmConsumption() hanya membalik material
            // bawaan order, bukan penambahan di tengah jalan. Kalau dibiarkan, WIP & stok dari
            // penambahan itu nyangkut selamanya — jadi minta di-void dulu.
            $aktif = $order->materialAdditions()->whereNull('voided_at')->count();
            if ($aktif > 0) {
                throw new Exception('Order ini punya ' . $aktif . ' penambahan bahan/biaya yang masih aktif. Batalkan (void) penambahan itu dulu di menu Penambahan Bahan, baru order bisa dibatalkan.');
            }

            $restored = $this->reverseConfirmConsumption($order);

            $order->update(['status' => 'cancelled']);

            return $restored;
        });
    }

    /**
     * Kembalikan material yang dikonsumsi saat konfirmasi ke stok dan balik jurnal WIP.
     * Dipakai saat membatalkan order yang sudah dikonfirmasi tapi belum dikerjakan.
     *
     * @return bool  true bila ada material yang benar-benar dikembalikan.
     */
    private function reverseConfirmConsumption(ProductionOrder $order): bool
    {
        $consumed = $order->materials->filter(fn ($m) => (float) $m->qty_consumed > 1e-9);
        if ($consumed->isEmpty()) {
            // Preorder soft-confirm: material belum pernah dikonsumsi, tidak ada yang dibalik.
            return false;
        }

        $debitCode = $order->isRepairLike()
            ? AccountCodeEnum::INVENTORY_REPAIR
            : AccountCodeEnum::INVENTORY;

        // Perbaikan: bahan dikembalikan ke Gudang Perbaikan (asal konsumsinya).
        $restoreWarehouseId = $order->type === 'perbaikan'
            ? (Warehouse::repairId() ?? $order->warehouse_id)
            : $order->warehouse_id;

        $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
        $inventoryAccount = Account::where('code', $debitCode)->firstOrFail();

        $fifo          = app(FifoService::class);
        $totalRestored = 0.0;

        // Kelompokkan per produk (mendukung beberapa baris material dengan produk sama).
        foreach ($consumed->groupBy('product_id') as $productId => $rows) {
            $qty = (float) $rows->sum('qty_consumed');
            if ($qty <= 1e-9) {
                continue;
            }

            // Biaya konsumsi aktual saat konfirmasi (FIFO) → harga pokok pengembalian.
            $layers = \App\Models\InventoryCostLayer::where('product_id', $productId)
                ->where('reference_type', 'production_material')
                ->where('reference_id', $order->id)
                ->whereNotNull('qty_out')
                ->get();
            $consumedQty  = (float) $layers->sum('qty_out');
            $consumedCost = (float) $layers->sum(fn ($l) => (float) $l->qty_out * (float) $l->unit_cost);
            $unitCost     = $consumedQty > 1e-9 ? $consumedCost / $consumedQty : 0.0;

            // Stok kembali ke gudang: layer FIFO baru + ledger qtyIn.
            $fifo->stockIn(
                productId:     (int) $productId,
                warehouseId:   (int) $restoreWarehouseId,
                type:          'production_cancel',
                reference:     $order->order_number,
                qty:           $qty,
                cost:          $unitCost,
                transactionId: $order->id
            );

            $totalRestored += $qty * $unitCost;
        }

        // Reset penanda konsumsi agar konsisten dengan stok yang sudah dikembalikan.
        foreach ($consumed as $m) {
            $m->update(['qty_consumed' => 0]);
        }

        // Jurnal balik (reversing entry): Dr. Persediaan / Cr. WIP. Jurnal konfirmasi
        // dibiarkan tetap posted sebagai jejak audit — keduanya saling meniadakan.
        if ($totalRestored > 1e-9) {
            app(JournalPostingService::class)->post(new JournalEntryDTO(
                date:             now()->format('Y-m-d'),
                reference_type:   'production_order_cancel',
                reference_id:     $order->id,
                description:      "Batal Order Produksi - {$order->order_number}",
                lines: [
                    new JournalLineDTO(
                        account_id:  $inventoryAccount->id,
                        debit:       (float) $totalRestored,
                        credit:      0,
                        description: 'Material kembali ke persediaan'
                    ),
                    new JournalLineDTO(
                        account_id:  $wipAccount->id,
                        debit:       0,
                        credit:      (float) $totalRestored,
                        description: 'Balik WIP dari pembatalan order'
                    ),
                ],
                reference_number: $order->order_number
            ));
        }

        return true;
    }

    /**
     * Nomor langkah aktif sebuah OP (sesuai kartu di halaman Proses):
     * step in_progress/paused, atau step pending pertama yang langkah sebelumnya sudah selesai.
     * Null bila tidak ada langkah aktif yang valid.
     */
    public function activeStepNumber(ProductionOrder $o): ?int
    {
        $active = $o->steps->whereIn('status', ['in_progress', 'paused'])->sortBy('step_number')->first();
        if ($active) {
            return (int) $active->step_number;
        }
        foreach ($o->steps->sortBy('step_number') as $s) {
            if ($s->status !== 'pending') {
                continue;
            }
            $prev = $o->steps->firstWhere('step_number', $s->step_number - 1);
            if ($prev === null || $prev->status === 'completed') {
                return (int) $s->step_number;
            }
        }
        return null;
    }

    /**
     * Signature resep OP (dinormalisasi per-siklus) untuk menentukan "komponen sama persis".
     * Dua OP bisa digabung hanya bila signature-nya identik.
     */
    public function componentSignature(ProductionOrder $o): string
    {
        $cycles = max((float) $o->planned_cycles, 1.0);

        $mats = $o->materials
            ->map(fn($m) => $m->product_id . ':' . (string) $m->unit . ':' . round((float) $m->qty_required / $cycles, 4))
            ->sort()->values()->implode('|');

        $outs = $o->outputs
            ->map(fn($x) => $x->product_id . ':' . $x->output_type . ':' . round((float) $x->percentage, 2) . ':' . round((float) $x->qty_planned / $cycles, 4))
            ->sort()->values()->implode('|');

        $steps = $o->steps->sortBy('step_number')
            ->map(fn($s) => (string) $s->department_id . ':' . trim((string) $s->name))
            ->values()->implode('|');

        return md5("{$o->type}#{$o->warehouse_id}#M{$mats}#O{$outs}#S{$steps}");
    }

    /**
     * Apakah OP layak digabung (bukan repair, status aktif, belum pernah digabung,
     * dan punya langkah aktif). Dipakai controller untuk menampilkan checkbox.
     *
     * Status 'partial' sengaja TIDAK ikut: sebagian hasilnya sudah masuk stok dengan biaya
     * yang dihitung dari WIP order ini, jadi materialnya tidak bisa lagi dipindah ke induk.
     */
    public function isMergeEligible(ProductionOrder $o): bool
    {
        return $o->type !== 'repair'
            && in_array($o->status, ['confirmed', 'in_progress'], true)
            && $o->merged_into_id === null
            && $this->activeStepNumber($o) !== null;
    }

    /**
     * Gabungkan beberapa task produksi (OP) menjadi satu task induk.
     *
     * Syarat: resep identik, gudang & tipe sama (bukan repair), status aktif
     * (confirmed/in_progress), dan semua berada di langkah aktif yang sama.
     * Boleh setelah WIP terbentuk — biaya WIP task yang diserap dipindah ke induk
     * sehingga COGS output saat finalisasi tetap benar.
     *
     * @param  int[] $orderIds
     * @return ProductionOrder  induk hasil gabungan
     */
    public function mergeOrders(array $orderIds): ProductionOrder
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (count($orderIds) < 2) {
            throw new Exception('Pilih minimal 2 task untuk digabung.');
        }

        return DB::transaction(function () use ($orderIds) {
            $orders = ProductionOrder::with(['materials', 'outputs', 'steps', 'bom'])
                ->lockForUpdate()
                ->whereIn('id', $orderIds)
                ->get();

            if ($orders->count() !== count($orderIds)) {
                throw new Exception('Sebagian task tidak ditemukan.');
            }

            // 1) Guard kelayakan
            foreach ($orders as $o) {
                if ($o->type === 'repair') {
                    throw new Exception("Task {$o->order_number} adalah Perbaikan dan tidak bisa digabung.");
                }
                if (!in_array($o->status, ['confirmed', 'in_progress'], true)) {
                    // 'partial' ikut ditolak di sini: hasil yang sudah dilepas ke stok terikat
                    // pada WIP task ini, sehingga materialnya tidak bisa dipindah ke induk.
                    throw new Exception("Task {$o->order_number} berstatus {$o->status_label} — hanya task aktif yang bisa digabung.");
                }
                if ($o->merged_into_id !== null) {
                    throw new Exception("Task {$o->order_number} sudah pernah digabung.");
                }
            }

            // 2) Langkah aktif harus sama
            $stepNumbers = $orders->map(fn($o) => $this->activeStepNumber($o));
            if ($stepNumbers->contains(null)) {
                throw new Exception('Sebagian task tidak punya langkah aktif yang valid untuk digabung.');
            }
            if ($stepNumbers->unique()->count() > 1) {
                throw new Exception('Task berada di langkah yang berbeda — hanya bisa digabung pada langkah yang sama.');
            }

            // 3) Resep identik
            if ($orders->map(fn($o) => $this->componentSignature($o))->unique()->count() > 1) {
                throw new Exception('Komponen task tidak sama persis — tidak bisa digabung.');
            }

            // 4) Pilih induk: progres (sedang dikerjakan/paused dulu) → effective_score → tertua
            $progressRank = fn(ProductionOrder $o): int =>
                $o->steps->whereIn('status', ['in_progress', 'paused'])->isNotEmpty() ? 1 : 0;

            $induk = $orders->reduce(function ($best, $o) use ($progressRank) {
                if ($best === null) {
                    return $o;
                }
                $po = $progressRank($o);
                $pb = $progressRank($best);
                if ($po !== $pb) {
                    return $po > $pb ? $o : $best;
                }
                if ((float) $o->effective_score !== (float) $best->effective_score) {
                    return $o->effective_score > $best->effective_score ? $o : $best;
                }
                return $o->created_at < $best->created_at ? $o : $best;
            }, null);

            $children = $orders->reject(fn($o) => $o->id === $induk->id);

            // 5-8) Serap tiap anak ke induk
            foreach ($children as $child) {
                // Qty header
                $induk->planned_cycles = (float) $induk->planned_cycles + (float) $child->planned_cycles;
                $induk->planned_qty    = (float) $induk->planned_qty + (float) $child->planned_qty;

                // Materials (match product_id + unit) — resep identik dijamin ketemu
                foreach ($child->materials as $cm) {
                    $im = $induk->materials->first(
                        fn($m) => $m->product_id == $cm->product_id && (string) $m->unit === (string) $cm->unit
                    );
                    if ($im) {
                        $im->qty_required = (float) $im->qty_required + (float) $cm->qty_required;
                        $im->qty_consumed = (float) $im->qty_consumed + (float) $cm->qty_consumed;
                        $im->save();
                    } else {
                        $cm->production_order_id = $induk->id;
                        $cm->save();
                    }
                }

                // Outputs (match product_id + output_type)
                foreach ($child->outputs as $co) {
                    $io = $induk->outputs->first(
                        fn($x) => $x->product_id == $co->product_id && $x->output_type === $co->output_type
                    );
                    if ($io) {
                        $io->qty_planned = (float) $io->qty_planned + (float) $co->qty_planned;
                        $io->save();
                    } else {
                        $co->production_order_id = $induk->id;
                        $co->save();
                    }
                }

                // 6) Pindah biaya WIP anak → induk (jurnal + penambahan bahan + biaya + ledger)
                \App\Core\Journal\Journal::whereIn('reference_type', ['production_order_confirm', 'production_order_cost'])
                    ->where('reference_id', $child->id)
                    ->update(['reference_id' => $induk->id]);
                \App\Core\Journal\JournalLine::whereIn('reference_type', ['production_order_confirm', 'production_order_cost'])
                    ->where('reference_id', $child->id)
                    ->update(['reference_id' => $induk->id]);
                \App\Modules\Production\Models\ProductionMaterialAddition::where('production_order_id', $child->id)
                    ->update(['production_order_id' => $induk->id]);
                ProductionOrderCost::where('production_order_id', $child->id)
                    ->update(['production_order_id' => $induk->id]);
                \App\Models\InventoryLedger::where('transaction_type', 'production_material')
                    ->where('transaction_id', $child->id)
                    ->update(['transaction_id' => $induk->id]);

                // 8) Tandai anak sebagai diserap
                $child->status = 'merged';
                $child->merged_into_id = $induk->id;
                $child->save();
            }

            // 7) Skor/priority hasil = tertinggi (priority menang bila >= skor auto; selain itu auto)
            $this->applyMergedScore($induk, $orders);

            $induk->save();

            return $induk->fresh(['materials', 'outputs', 'steps', 'mergedChildren']);
        });
    }

    /**
     * Tetapkan skor/priority induk = yang tertinggi dari semua OP yang digabung.
     * Bila nilai priority >= skor auto tertinggi → pakai priority tertinggi;
     * selain itu pakai mode auto (skor BOM).
     */
    private function applyMergedScore(ProductionOrder $induk, $orders): void
    {
        $map = ['low' => 0, 'medium' => 100, 'high' => 200, 'very_high' => 300];

        $maxPriority = null;
        $maxPriorityLevel = null;
        $hasAuto = false;
        $autoMax = 0.0;

        foreach ($orders as $o) {
            if ($o->score_type === 'priority') {
                $v = $map[$o->priority_level] ?? 0;
                if ($maxPriority === null || $v > $maxPriority) {
                    $maxPriority = $v;
                    $maxPriorityLevel = $o->priority_level;
                }
            } else {
                $hasAuto = true;
                $autoMax = max($autoMax, (float) $o->effective_score);
            }
        }

        if ($maxPriority !== null && (!$hasAuto || $maxPriority >= $autoMax)) {
            $induk->score_type = 'priority';
            $induk->priority_level = $maxPriorityLevel;
        } else {
            $induk->score_type = 'auto';
            $induk->priority_level = null;
        }
    }

    private function getWipCost(int $orderId): float
    {
        return $this->wipBreakdown($orderId)['total'];
    }

    /**
     * Rincian biaya yang MASUK ke WIP order ini, per sumbernya — dipakai panel audit WIP
     * di halaman order sekaligus jadi sumber tunggal angka total WIP.
     *
     * Jurnal yang berkontribusi:
     *  - production_order_confirm  → konsumsi material awal (reference_id = order_id)
     *  - production_order_cost     → biaya produksi saat buat order (reference_id = order_id)
     *  - production_material_addition → penambahan bahan (reference_id = addition_id)
     *  - production_cost_addition  → biaya tambahan saat penambahan bahan (reference_id = addition_id)
     *
     * Dihitung NETTO (debit − kredit): pembatalan penambahan bahan memasang jurnal balik
     * Cr. WIP dengan reference_type *_void, bukan mem-void jurnal aslinya. Kalau hanya debit
     * yang dijumlah, WIP order tetap menghitung bahan yang sudah dikembalikan ke gudang →
     * batch penutup melepas lebih besar dari saldo WIP nyata dan akun WIP jadi minus.
     *
     * Pelepasan hasil (production_order_release) SENGAJA tidak ikut: itu sisi keluar, sudah
     * dihitung terpisah lewat wip_released tiap batch di wipSummary().
     *
     * @return array{material: float, cost: float, addition_material: float, addition_cost: float, total: float}
     */
    public function wipBreakdown(int $orderId): array
    {
        $empty = ['material' => 0.0, 'cost' => 0.0, 'addition_material' => 0.0, 'addition_cost' => 0.0, 'total' => 0.0];

        $wipAccount = Account::where('code', AccountCodeEnum::WIP)->first();
        if (!$wipAccount) return $empty;

        $additionIds = \App\Modules\Production\Models\ProductionMaterialAddition::where('production_order_id', $orderId)
            ->pluck('id')->all();

        $net = function (array $types, array $refIds) use ($wipAccount): float {
            if (!$refIds) return 0.0;

            $row = \App\Core\Journal\JournalLine::where('account_id', $wipAccount->id)
                ->whereHas('journal', function ($q) use ($types, $refIds) {
                    $q->whereIn('reference_type', $types)
                      ->whereIn('reference_id', $refIds)
                      ->where('status', '!=', 'void');
                })
                ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
                ->first();

            return (float) $row->d - (float) $row->c;
        };

        $breakdown = [
            // production_order_cancel mengkredit WIP saat order dibatalkan → netto jadi nol.
            'material'          => $net(['production_order_confirm', 'production_order_cancel'], [$orderId]),
            'cost'              => $net(['production_order_cost'], [$orderId]),
            'addition_material' => $net(['production_material_addition', 'production_material_addition_void'], $additionIds),
            'addition_cost'     => $net(['production_cost_addition', 'production_cost_addition_void'], $additionIds),
        ];
        $breakdown['total'] = array_sum($breakdown);

        return $breakdown;
    }

/**
     * Validasi eksekutor.
     * - $strict=true  (default startStep): wajib scan check-in & dalam jam kerja
     * - $strict=false (resume manual / fallback mesin rusak): hanya validate karyawan terlink + ada schedule (boleh tanpa scan, boleh di luar jam kerja)
     *
     * Tetap throw kalau eksekutor tidak ter-link karyawan, tidak ada jadwal, atau libur — itu kondisi data fundamental yang harus dibetulkan sebelum mulai.
     */
    private function assertExecutorsReady(array $executorIds, bool $strict = true, bool $bypassReady = false): void
    {
        if (empty($executorIds)) {
            throw new Exception('Pilih minimal 1 eksekutor.');
        }

        // Mode testing: lewati semua cek kesiapan (scan, jam kerja, jadwal, link karyawan)
        // supaya alur produksi bisa diuji tanpa fingerprint.
        if ($bypassReady) {
            return;
        }

        $resolver = app(ExecutorScheduleResolver::class);
        $now = now();

        $executors = DepartmentExecutor::whereIn('id', $executorIds)->get();
        foreach ($executors as $exec) {
            $karyawanId = $exec->effectiveKaryawanId();
            $execName = $exec->display_name;

            if (!$karyawanId) {
                throw new Exception("Eksekutor {$execName} belum ter-link ke karyawan (dan tidak punya parent).");
            }

            $sched = $resolver->forKaryawan($karyawanId, $now);
            if (!$sched) {
                throw new Exception("{$execName}: tidak ada jadwal kerja untuk hari ini.");
            }
            if ($sched->is_off) {
                throw new Exception("{$execName}: karyawan terjadwal libur hari ini.");
            }

            if (!$strict) {
                continue; // Mode resume manual / override — skip scan & jam check
            }

            $checkIn = $resolver->hasCheckedIn($karyawanId, $now);
            if (!$checkIn) {
                throw new Exception("{$execName}: belum scan check-in hari ini.");
            }

            $checkOut = $resolver->hasCheckedOut($karyawanId, $now);
            $overtimeIn = $resolver->hasOvertimeIn($karyawanId, $now);

            if ($checkOut && (!$overtimeIn || $checkOut->gt($overtimeIn))) {
                throw new Exception("{$execName}: sudah scan pulang. Tidak bisa memulai langkah.");
            }

            $status = $resolver->currentStatus($sched, $now, $overtimeIn);
            if (!in_array($status, ['in_work', 'in_overtime'], true)) {
                $msg = match ($status) {
                    'pre_work'       => "Sebelum jam kerja ({$sched->jam_masuk}).",
                    'in_break'       => "Sedang jam istirahat.",
                    'after_work'     => "Sudah melewati jam pulang. Aktifkan lembur dulu via scan overtime_in.",
                    'after_overtime' => "Sudah melewati jam pulang lembur.",
                    default          => "Tidak dalam jam kerja.",
                };
                throw new Exception("{$execName}: {$msg}");
            }
        }
    }
}
