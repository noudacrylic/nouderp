<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderMaterial;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionOrderSource;
use App\Modules\Production\Models\ProductionOrderCost;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Models\ProductionStepExecutorStatus;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\Department;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\StockLayer;
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

            // Repair type: auto-create materials/outputs/sources from repair_items
            if (($data['type'] ?? '') === 'repair' && !empty($data['repair_items'])) {
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
                        'output_type'         => $item['output_type'],
                        'percentage'          => $item['percentage'],
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
                    ProductionOrderMaterial::create([
                        'production_order_id' => $order->id,
                        'product_id'          => $m['product_id'],
                        'qty_required'        => $m['qty_required'],
                        'unit'                => $m['unit'] ?? null,
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
     * Konfirmasi order → consume material dari stok (Dr. WIP / Cr. Persediaan)
     */
    public function confirm(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = ProductionOrder::with(['materials', 'outputs', 'steps'])
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

            $this->consumeOrderMaterials($order, $order->materials);

            $order->update(['status' => 'confirmed']);
        });
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
        $creditCode = $order->type === 'repair'
            ? AccountCodeEnum::INVENTORY_REPAIR
            : AccountCodeEnum::INVENTORY;

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
                warehouseId: $order->warehouse_id,
                qtyIn: 0,
                qtyOut: $toConsume,
                type: 'production_material',
                reference: $order->order_number,
                notes: null,
                transactionId: $order->id
            );

            $cogs = $fifo->consume(
                $material->product_id,
                $order->warehouse_id,
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
                reference_number: $order->order_number
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
            $this->assertExecutorsReady($executorIds, strict: !$force, bypassReady: $testing);

            if (!in_array($order->status, ['confirmed', 'in_progress'])) {
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
            foreach ($actualOutputs as $out) {
                $outputRecord = $order->outputs()->find($out['output_id'] ?? null);
                if (!$outputRecord) continue;
                $payload = [
                    'qty_produced'   => (float) ($out['qty_produced'] ?? 0),
                    'variance_notes' => $out['variance_notes'] ?? null,
                ];
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

            if (!in_array($order->status, ['confirmed', 'in_progress'])) {
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
            if (!in_array($order->status, ['confirmed', 'in_progress'])) {
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
    public function finalize(int $orderId, array $actualOutputs): void
    {
        // Pre-flight di luar transaksi: cek status & ketersediaan stok untuk material tertunda.
        // Kalau gagal, kita ingin status 'pending' tetap tersimpan walau finalize dibatalkan.
        $order = ProductionOrder::with(['materials.product'])->findOrFail($orderId);

        if (!in_array($order->status, ['completed', 'pending'], true)) {
            throw new Exception('Order produksi belum siap difinalisasi (harus berstatus Menunggu Finalisasi atau Menunggu Stok).');
        }

        // Self-heal: kalau order sebelumnya pernah difinalisasi lalu di-void/reset (status sekarang
        // bukan 'finalized'), pastikan jurnal finalisasi yang lama ditandai 'void' supaya pengecekan
        // double-posting di JournalPostingService tidak memblokir percobaan finalisasi ulang.
        \App\Core\Journal\Journal::where('reference_type', 'production_order_finalize')
            ->where('reference_id', $orderId)
            ->where('status', '!=', 'void')
            ->update(['status' => 'void', 'voided_at' => now()]);

        // Material yang masih punya sisa belum dikonsumsi (termasuk sebagian, akibat merge).
        $unconsumed = $order->materials->filter(fn($m) => (float) $m->qty_consumed < (float) $m->qty_required - 1e-9);

        if ($unconsumed->isNotEmpty()) {
            $engine = app(InventoryEngine::class);
            $insufficient = [];

            foreach ($unconsumed as $material) {
                $available = (float) $engine->availableStock(
                    (int) $material->product_id,
                    (int) $order->warehouse_id
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
                $order->update(['status' => 'pending']);

                $details = collect($insufficient)
                    ->map(fn($i) => "• {$i['sku']} {$i['name']} — butuh " .
                        rtrim(rtrim(number_format($i['required'], 4, ',', '.'), '0'), ',') .
                        ", tersedia " .
                        rtrim(rtrim(number_format($i['available'], 4, ',', '.'), '0'), ',') .
                        ", kurang " .
                        rtrim(rtrim(number_format($i['short'], 4, ',', '.'), '0'), ','))
                    ->join("\n");

                throw new Exception(
                    "Finalisasi ditolak. Stok material belum mencukupi (status diubah ke Menunggu Stok). " .
                    "Lengkapi pembelian/adjustment untuk:\n{$details}"
                );
            }
        }

        DB::transaction(function () use ($orderId, $actualOutputs) {
            $order = ProductionOrder::with(['outputs.product', 'materials'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            // Konsumsi tertunda: untuk preorder soft-confirmed, retry dari pending, atau
            // sisa material hasil merge (terkonsumsi sebagian) di-FIFO sekarang.
            $unconsumed = $order->materials->filter(fn($m) => (float) $m->qty_consumed < (float) $m->qty_required - 1e-9);
            if ($unconsumed->isNotEmpty()) {
                $this->consumeOrderMaterials($order, $unconsumed);
            }

            // Hitung ulang WIP setelah semua konsumsi material (termasuk Penambahan Bahan).
            $totalWipCost = $this->getWipCost($orderId);

            // Hitung total qty output untuk distribusi cost
            $totalOutputQty = array_sum(array_column($actualOutputs, 'qty_produced'));

            if ($totalOutputQty <= 0) {
                throw new Exception('Qty output harus lebih dari 0.');
            }

            // ── Alokasi biaya output ──
            // Bila order punya produk sampingan, alokasi berbasis PERSENTASE dari WIP; utama menyerap sisa.
            //  • DENGAN BOM (fixed per-unit): sampingan = unit% × (qty/siklus) → di-recompute dari qty
            //    hasil produksi, sehingga kerusakan sampingan otomatis menambah HPP produk utama.
            //  • TANPA BOM: ukuran material beda → persentase di-input/di-override manual operator;
            //    pakai persentase yang dikirim (fallback ke nilai tersimpan di OP).
            //  Bila tidak ada sampingan (mayoritas / data lama), pertahankan alokasi rasio qty.
            $cycles = max(1e-9, (float) ($order->planned_cycles ?: 1));
            // Order Perbaikan tetap pakai alokasi rasio qty (tidak ada konsep % sampingan).
            $usePctMode = $order->type !== 'repair'
                && $order->outputs->contains(fn($o) => $o->output_type === 'by_product');
            $bomFixed   = $order->bom_id !== null;

            // Pra-hitung persentase per output (output_id => pct) untuk mode persentase.
            $pctMap = [];
            if ($usePctMode) {
                $sumBp = 0.0;
                foreach ($actualOutputs as $out) {
                    $rec = $order->outputs->firstWhere('id', $out['output_id'] ?? null);
                    if (!$rec || $rec->output_type !== 'by_product') continue;
                    // BOM dgn % master → recompute dari qty. Sampingan manual (unit_percentage null)
                    // tetap hormati persentase tersimpan/dikirim meski order pakai BOM.
                    if ($bomFixed && $rec->unit_percentage !== null) {
                        $unitPct = (float) $rec->unit_percentage;
                        $pct = round($unitPct * ((float) $out['qty_produced'] / $cycles), 4);
                    } else {
                        $pct = (isset($out['percentage']) && $out['percentage'] !== '' && $out['percentage'] !== null)
                            ? round((float) $out['percentage'], 4)
                            : round((float) $rec->percentage, 4);
                    }
                    $pctMap[$out['output_id']] = $pct;
                    $sumBp += $pct;
                }
                if ($sumBp > 100 + 0.01) {
                    $shown = rtrim(rtrim(number_format($sumBp, 4, '.', ''), '0'), '.');
                    throw new Exception("Total persentase produk sampingan melebihi 100% ({$shown}%). Periksa qty / persentase hasil produksi.");
                }
                $mainPct = round(100 - $sumBp, 4);
                foreach ($actualOutputs as $out) {
                    $rec = $order->outputs->firstWhere('id', $out['output_id'] ?? null);
                    if ($rec && $rec->output_type === 'main') {
                        $pctMap[$out['output_id']] = $mainPct;
                    }
                }
            }

            $costPerUnit = $totalWipCost > 0 ? $totalWipCost / $totalOutputQty : 0;

            $wipAccount       = Account::where('code', AccountCodeEnum::WIP)->firstOrFail();
            $inventoryAccount = Account::where('code', AccountCodeEnum::INVENTORY)->firstOrFail();

            $totalOutputCost = 0;
            $fifo = app(FifoService::class);

            foreach ($actualOutputs as $out) {
                $outputRecord = $order->outputs()->find($out['output_id']);
                if (!$outputRecord || $out['qty_produced'] <= 0) continue;

                $qtyProduced = (float) $out['qty_produced'];

                // Mode persentase: biaya = pct% × WIP. Mode lama: rasio qty × costPerUnit.
                if ($usePctMode) {
                    $pct      = (float) ($pctMap[$out['output_id']] ?? 0);
                    $itemCost = $totalWipCost * $pct / 100;
                } else {
                    $pct      = null;
                    $itemCost = $costPerUnit * $qtyProduced;
                }

                // Update qty_produced + percentage (hasil recalc otoritatif) + variance_notes
                $updatePayload = [
                    'qty_produced'   => $qtyProduced,
                    'variance_notes' => $out['variance_notes'] ?? null,
                ];
                if ($pct !== null) {
                    $updatePayload['percentage'] = $pct;
                }
                $outputRecord->update($updatePayload);

                // Stock IN: output masuk persediaan via FIFO
                $fifo->stockIn(
                    productId:     $outputRecord->product_id,
                    warehouseId:   $order->warehouse_id,
                    type:          'production_order',
                    reference:     $order->order_number,
                    qty:           $qtyProduced,
                    cost:          $qtyProduced > 0 ? $itemCost / $qtyProduced : 0,
                    transactionId: $order->id
                );

                // Untuk custom order: tag FIFO layer dengan sales_order_id
                if ($order->type === 'custom' && $order->sales_order_id) {
                    StockLayer::where('source_type', 'production_order')
                        ->where('source_id', $order->id)
                        ->where('product_id', $outputRecord->product_id)
                        ->latest()
                        ->update(['sales_order_id' => $order->sales_order_id]);
                }

                $totalOutputCost += $itemCost;
            }

            // Jurnal: Dr. Persediaan / Cr. WIP
            if ($totalOutputCost > 0) {
                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date:             now()->format('Y-m-d'),
                    reference_type:   'production_order_finalize',
                    reference_id:     $order->id,
                    description:      "Produksi Selesai - {$order->order_number}",
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
                            description: 'Closing WIP produksi'
                        ),
                    ],
                    reference_number: $order->order_number
                ));
            }

            $order->update(['status' => 'finalized', 'finalized_at' => now()]);

            // Auto-advance dokumen sumber Perbaikan → "Selesai Diperbaiki".
            // Garansi: received/posted → repaired, sehingga SJ Garansi bisa langsung dibuat.
            if ($order->type === 'repair'
                && $order->repair_source_type === 'warranty'
                && $order->repair_source_id) {
                app(\App\Modules\Sales\Services\WarrantyOrderService::class)
                    ->markRepairedFromProduction((int) $order->repair_source_id);
            }
        });
    }

    public function void(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = ProductionOrder::with(['outputs', 'steps'])->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== 'finalized') {
                throw new Exception('Hanya order yang sudah difinalisasi yang dapat di-void.');
            }

            $this->reverseFinalizationInternal($order);

            // Reset order ke 'pending' agar admin bisa re-finalize tanpa menerobos ulang ke antrean step.
            // - Output qty_produced & variance_notes TIDAK direset → kerja operator dipertahankan.
            // - Last step TIDAK dibalik ke pending → step memang sudah selesai dikerjakan.
            // Yang dibatalkan oleh void hanyalah finalisasi (FIFO stockIn + jurnal closing WIP), bukan kerja produksinya.
            $order->update(['status' => 'pending', 'finalized_at' => null]);
        });
    }

    /**
     * Balik (reverse) efek finalisasi pada FIFO & jurnal — TANPA mengubah status order.
     * Dipakai oleh void() (lalu set 'pending') dan editFinalization() (lalu re-apply finalize).
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
        $engine = app(InventoryEngine::class);
        foreach ($order->outputs as $out) {
            $qty = (float) ($out->qty_produced ?? 0);
            if ($qty <= 0) continue;
            $engine->ledger(
                productId:     (int) $out->product_id,
                warehouseId:   (int) $order->warehouse_id,
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
     * Edit hasil finalisasi: balik efek lama lalu terapkan ulang dengan qty/persentase output
     * baru — ATOMIK. FIFO (layer + ledger) DAN jurnal ikut teredit dalam satu transaksi:
     * reversal lama + posting finalisasi baru. finalize() di dalam berjalan sebagai savepoint,
     * sehingga bila apa pun gagal seluruh edit (termasuk reversal) ikut di-rollback dan data
     * finalisasi lama tetap utuh.
     *
     * Status tetap 'finalized'. Biaya WIP yang dikapitalisasi tidak berubah (material sudah
     * dikonsumsi) — hanya dialokasikan ulang ke qty output baru.
     */
    public function editFinalization(int $orderId, array $actualOutputs): void
    {
        DB::transaction(function () use ($orderId, $actualOutputs) {
            $order = ProductionOrder::with(['outputs', 'steps'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->status !== 'finalized') {
                throw new Exception('Hanya order yang sudah difinalisasi yang dapat diedit.');
            }

            // 1) Balik efek finalisasi lama (jurnal reversal + ledger qty_out + hapus FIFO layer).
            //    Guard di dalam: bila output sudah terpakai downstream → throw → rollback.
            $this->reverseFinalizationInternal($order);

            // 2) Set 'pending' agar finalize() menerima order untuk re-apply.
            //    Material sudah terkonsumsi sejak finalisasi awal → tak ada pre-flight stok terpicu.
            $order->update(['status' => 'pending', 'finalized_at' => null]);

            // 3) Terapkan ulang dengan output baru. finalize() membuka transaksi sendiri
            //    (savepoint dalam transaksi ini) → FIFO stockIn baru + jurnal finalisasi baru +
            //    status kembali 'finalized'. Bila gagal, transaksi luar ini ikut di-rollback.
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

        $debitCode = $order->type === 'repair'
            ? AccountCodeEnum::INVENTORY_REPAIR
            : AccountCodeEnum::INVENTORY;

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
                warehouseId:   (int) $order->warehouse_id,
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
        // Ambil total debit ke WIP dari semua jurnal yang berkontribusi ke order ini:
        //  - production_order_confirm  → konsumsi material awal (reference_id = order_id)
        //  - production_material_addition → penambahan bahan (reference_id = addition_id)
        //  - production_cost_addition  → biaya tambahan saat penambahan bahan (reference_id = addition_id)
        $wipAccount = Account::where('code', AccountCodeEnum::WIP)->first();
        if (!$wipAccount) return 0;

        // (a) Direct: jurnal yang reference_id-nya = order_id
        $directDebit = (float) \App\Core\Journal\JournalLine::where('account_id', $wipAccount->id)
            ->whereHas('journal', function ($q) use ($orderId) {
                $q->where('reference_type', 'production_order_confirm')
                  ->where('reference_id', $orderId);
            })
            ->sum('debit');

        // (b) Indirect: jurnal yang reference_id-nya = id penambahan bahan order ini
        $additionIds = \App\Modules\Production\Models\ProductionMaterialAddition::where('production_order_id', $orderId)
            ->pluck('id');

        $additionDebit = 0.0;
        if ($additionIds->isNotEmpty()) {
            $additionDebit = (float) \App\Core\Journal\JournalLine::where('account_id', $wipAccount->id)
                ->whereHas('journal', function ($q) use ($additionIds) {
                    $q->whereIn('reference_type', ['production_material_addition', 'production_cost_addition'])
                      ->whereIn('reference_id', $additionIds);
                })
                ->sum('debit');
        }

        return $directDebit + $additionDebit;
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
