<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\StockLayer;
use App\Enums\AccountCodeEnum;
use App\Modules\Production\Models\ProductionFinalization;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penyelesaian partial produksi — aturan alokasi WIP.
 *
 * Aturan tunggal yang dijaga di sini: setiap rupiah WIP hanya dibebankan ke unit yang BELUM
 * keluar. Turunannya:
 *   • batch partial  = (sisa WIP − cadangan sampingan) × qty batch ÷ sisa qty
 *   • batch penutup  = menyapu SELURUH sisa WIP (kurang qty menaikkan HPP, lebih menurunkan)
 *   • sampingan      = persentase × WIP KESELURUHAN, dicadangkan sejak partial pertama
 *
 * Angka-angkanya memakai ilustrasi yang disepakati: AM-40x30x6 64 pcs, WIP 2.800.000,
 * sampingan NM-7x30-M1 5 pcs = 8%.
 */
class PartialFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrderService $service;
    private int $warehouseId;
    private int $mainProductId;
    private int $byproductId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductionOrderService::class);

        foreach ([
            [AccountCodeEnum::WIP, 'Barang Dalam Proses', 'asset'],
            [AccountCodeEnum::INVENTORY, 'Persediaan', 'asset'],
        ] as [$code, $name, $type]) {
            DB::table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal_balance' => 'debit', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'Utama', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->mainProductId = $this->product('AM-40x30x6', 'Akrilik Menu 40x30x6');
        $this->byproductId   = $this->product('NM-7x30-M1', 'Name Tag 7x30');
    }

    private function product(string $sku, string $name): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Order siap dilepas: langkah terakhir sedang dikerjakan & WIP sudah terisi lewat
     * jurnal konsumsi material (Dr. WIP) seperti yang dilakukan confirm().
     */
    private function makeOrder(float $wip, float $plannedQty = 64, ?float $byproductQty = 5, float $byproductPct = 8, float $cycles = 1): ProductionOrder
    {
        // Order ber-BOM: persentase sampingan di-recompute dari qty aktual saat penutupan
        // (sampingan rusak otomatis mengembalikan jatahnya ke produk utama).
        $bomId = DB::table('boms')->insertGetId([
            'bom_number' => 'BOM-' . uniqid(),
            'name'       => 'BOM Uji',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = ProductionOrder::create([
            'order_number'    => 'OP-TEST-' . uniqid(),
            'type'            => 'ready_stock',
            'bom_id'          => $bomId,
            'warehouse_id'    => $this->warehouseId,
            'planned_cycles'  => $cycles,
            'planned_qty'     => $plannedQty,
            'production_date' => now()->toDateString(),
            'status'          => 'in_progress',
        ]);

        ProductionOrderOutput::create([
            'production_order_id' => $order->id,
            'product_id'          => $this->mainProductId,
            'qty_planned'         => $plannedQty,
            'qty_produced'        => 0,
            'output_type'         => 'main',
            'percentage'          => 0,
        ]);

        if ($byproductQty !== null) {
            ProductionOrderOutput::create([
                'production_order_id' => $order->id,
                'product_id'          => $this->byproductId,
                'qty_planned'         => $byproductQty,
                'qty_produced'        => 0,
                'output_type'         => 'by_product',
                'percentage'          => $byproductPct,
                // % per unit PER SIKLUS → 8% untuk 5 pcs = 1,6%/pcs.
                'unit_percentage'     => $byproductQty > 0 ? $byproductPct / $byproductQty : null,
            ]);
        }

        DB::table('production_order_steps')->insert([
            'production_order_id' => $order->id,
            'step_number'         => 1,
            'name'                => 'Cutting',
            'status'              => 'in_progress',
            'started_at'          => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->addWip($order, $wip, 'production_order_confirm', $order->id);

        return $order->refresh();
    }

    /** Debit ke WIP seperti jurnal konsumsi material / penambahan bahan. */
    private function addWip(ProductionOrder $order, float $amount, string $referenceType, int $referenceId): void
    {
        $wipAccountId = DB::table('accounts')->where('code', AccountCodeEnum::WIP)->value('id');
        $invAccountId = DB::table('accounts')->where('code', AccountCodeEnum::INVENTORY)->value('id');

        app(\App\Core\Period\PeriodService::class)->ensureOpen(now());
        $periodId = DB::table('accounting_periods')
            ->where('year', now()->year)->where('month', now()->month)
            ->value('id');

        $journalId = DB::table('journals')->insertGetId([
            'journal_number'  => 'JRN-' . uniqid(),
            'date'            => now()->toDateString(),
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'description'     => 'Konsumsi material uji',
            'period_id'       => $periodId,
            'status'          => 'posted',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('journal_lines')->insert([
            ['journal_id' => $journalId, 'account_id' => $wipAccountId, 'debit' => $amount, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_id' => $journalId, 'account_id' => $invAccountId, 'debit' => 0, 'credit' => $amount, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** Kredit ke WIP seperti jurnal balik pembatalan penambahan bahan. */
    private function reverseWip(float $amount, string $referenceType, int $referenceId): void
    {
        $wipAccountId = DB::table('accounts')->where('code', AccountCodeEnum::WIP)->value('id');
        $invAccountId = DB::table('accounts')->where('code', AccountCodeEnum::INVENTORY)->value('id');

        app(\App\Core\Period\PeriodService::class)->ensureOpen(now());
        $periodId = DB::table('accounting_periods')
            ->where('year', now()->year)->where('month', now()->month)
            ->value('id');

        $journalId = DB::table('journals')->insertGetId([
            'journal_number'  => 'JRN-' . uniqid(),
            'date'            => now()->toDateString(),
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'description'     => 'Balik WIP uji',
            'period_id'       => $periodId,
            'status'          => 'posted',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('journal_lines')->insert([
            ['journal_id' => $journalId, 'account_id' => $invAccountId, 'debit' => $amount, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_id' => $journalId, 'account_id' => $wipAccountId, 'debit' => 0, 'credit' => $amount, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function mainOutput(ProductionOrder $order): ProductionOrderOutput
    {
        return $order->outputs()->where('output_type', 'main')->firstOrFail();
    }

    private function byproductOutput(ProductionOrder $order): ProductionOrderOutput
    {
        return $order->outputs()->where('output_type', 'by_product')->firstOrFail();
    }

    private function batchCost(ProductionOrder $order, int $sequence): float
    {
        return (float) ProductionFinalization::where('production_order_id', $order->id)
            ->where('sequence', $sequence)
            ->value('wip_released');
    }

    private function sisaWip(ProductionOrder $order): float
    {
        $summary = $this->service->wipSummary($order->id);
        return $summary['remaining'];
    }

    // ── Ilustrasi utama ────────────────────────────────────────────────────────────

    public function test_partial_menyisihkan_jatah_sampingan_lebih_dulu(): void
    {
        $order = $this->makeOrder(wip: 2_800_000);

        // Ambil 10 pcs duluan (kejar batas kirim marketplace).
        $this->service->finalizePartial($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 10],
        ]);

        // cadangan sampingan = 8% × 2.800.000 = 224.000
        // biaya batch       = (2.800.000 − 224.000) × 10/64 = 402.500 → 40.250/pcs
        $this->assertEqualsWithDelta(402_500, $this->batchCost($order, 1), 0.01);

        $layer = StockLayer::where('product_id', $this->mainProductId)->firstOrFail();
        $this->assertEqualsWithDelta(40_250, (float) $layer->unit_cost, 0.01, 'HPP per pcs batch partial');

        $this->assertSame('partial', $order->refresh()->status);
        $this->assertEqualsWithDelta(10, (float) $this->mainOutput($order)->qty_produced, 0.001);
    }

    public function test_alur_penuh_penambahan_bahan_dan_sampingan_rusak(): void
    {
        $order = $this->makeOrder(wip: 2_800_000);

        // 1) Partial 10 pcs → 402.500
        $this->service->finalizePartial($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 10],
        ]);

        // 2) Penambahan bahan 50.000 (komponen rusak) → WIP keseluruhan 2.850.000
        $this->addWip($order, 50_000, 'production_material_addition', $this->fakeAdditionId($order));

        // 3) Penutup: 54 pcs utama + 4 pcs sampingan (1 rusak dari rencana 5)
        $this->service->finalize($order->id, [
            ['output_id' => $this->mainOutput($order)->id,      'qty_produced' => 54],
            ['output_id' => $this->byproductOutput($order)->id, 'qty_produced' => 4],
        ]);

        // Sampingan: 1,6% × 4 = 6,4% × 2.850.000 = 182.400 → 45.600/pcs
        $bpLayer = StockLayer::where('product_id', $this->byproductId)->firstOrFail();
        $this->assertEqualsWithDelta(45_600, (float) $bpLayer->unit_cost, 0.01, 'HPP sampingan');

        // Utama penutup: 2.447.500 − 182.400 = 2.265.100 → 41.946,30/pcs
        $closingLayer = StockLayer::where('product_id', $this->mainProductId)
            ->orderByDesc('id')->firstOrFail();
        $this->assertEqualsWithDelta(41_946.2963, (float) $closingLayer->unit_cost, 0.01, 'HPP utama batch penutup');

        // WIP habis tepat — inti dari aturan "batch penutup menyapu sisa".
        $this->assertEqualsWithDelta(0, $this->sisaWip($order), 0.01);

        // Total biaya = WIP keseluruhan.
        $total = (float) ProductionFinalization::where('production_order_id', $order->id)
            ->whereNull('voided_at')->sum('wip_released');
        $this->assertEqualsWithDelta(2_850_000, $total, 0.01);

        $this->assertSame('finalized', $order->refresh()->status);
        $this->assertEqualsWithDelta(64, (float) $this->mainOutput($order)->qty_produced, 0.001, 'qty_produced akumulatif lintas batch');
    }

    public function test_tanpa_partial_hasilnya_sama_dengan_finalisasi_biasa(): void
    {
        // Kontrol: order yang tidak diambil sebagian harus persis seperti perilaku lama.
        $order = $this->makeOrder(wip: 2_800_000);

        // Semua langkah selesai → menunggu finalisasi (jalur normal tanpa partial).
        DB::table('production_order_steps')->where('production_order_id', $order->id)
            ->update(['status' => 'completed', 'completed_at' => now()]);
        $order->update(['status' => 'completed']);

        $this->service->finalize($order->id, [
            ['output_id' => $this->mainOutput($order)->id,      'qty_produced' => 64],
            ['output_id' => $this->byproductOutput($order)->id, 'qty_produced' => 5],
        ]);

        // Sampingan 8% × 2.800.000 = 224.000; utama = 2.576.000 / 64 = 40.250
        $mainLayer = StockLayer::where('product_id', $this->mainProductId)->firstOrFail();
        $this->assertEqualsWithDelta(40_250, (float) $mainLayer->unit_cost, 0.01);
        $this->assertEqualsWithDelta(0, $this->sisaWip($order), 0.01);
    }

    public function test_kekurangan_qty_di_penutup_menaikkan_hpp_unit_terakhir(): void
    {
        $order = $this->makeOrder(wip: 2_800_000, byproductQty: null);

        $this->service->finalizePartial($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 40],
        ]);
        // 2.800.000 × 40/64 = 1.750.000 → 43.750/pcs
        $this->assertEqualsWithDelta(1_750_000, $this->batchCost($order, 1), 0.01);

        // Penutup hanya 20 pcs (4 pcs gagal) → menyapu sisa 1.050.000 → 52.500/pcs
        $this->service->finalize($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 20],
        ]);

        $closing = StockLayer::where('product_id', $this->mainProductId)->orderByDesc('id')->firstOrFail();
        $this->assertEqualsWithDelta(52_500, (float) $closing->unit_cost, 0.01);
        $this->assertEqualsWithDelta(0, $this->sisaWip($order), 0.01);
    }

    public function test_kelebihan_potong_menurunkan_hpp_setelah_bahan_dicatat(): void
    {
        // Operator memotong 10 lembar padahal rencana 8 → 16 pcs ekstra.
        $order = $this->makeOrder(wip: 2_800_000, byproductQty: null);

        $this->service->finalizePartial($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 10],
        ]);
        // 2.800.000 × 10/64 = 437.500 → 43.750/pcs
        $this->assertEqualsWithDelta(437_500, $this->batchCost($order, 1), 0.01);

        // Bahan ekstra dicatat lewat Penambahan Bahan (2 lembar @300.000).
        $this->addWip($order, 600_000, 'production_material_addition', $this->fakeAdditionId($order));

        // Target direvisi 64 → 80 pcs supaya pembagi partial berikutnya benar.
        $this->service->reviseTarget($order->id, [
            'planned_cycles' => 1.25,
            'outputs'        => [
                ['output_id' => $this->mainOutput($order)->id, 'qty_planned' => 80],
            ],
            'reason' => 'operator potong 10 lembar, rencana 8',
        ]);

        $this->assertEqualsWithDelta(80, (float) $order->refresh()->planned_qty, 0.001);

        // Penutup 70 pcs → sisa WIP 2.962.500 ÷ 70 = 42.321,43/pcs (turun dari 43.750
        // kalau bahan ekstra tidak dicatat HPP-nya justru jatuh palsu).
        $this->service->finalize($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 70],
        ]);

        $closing = StockLayer::where('product_id', $this->mainProductId)->orderByDesc('id')->firstOrFail();
        $this->assertEqualsWithDelta(42_321.4286, (float) $closing->unit_cost, 0.01);
        $this->assertEqualsWithDelta(0, $this->sisaWip($order), 0.01);
    }

    // ── Guard ─────────────────────────────────────────────────────────────────────

    public function test_sampingan_tidak_boleh_dilepas_saat_partial(): void
    {
        $order = $this->makeOrder(wip: 2_800_000);

        $this->expectExceptionMessageMatches('/sampingan hanya bisa dicatat saat finalisasi penutup/i');

        $this->service->finalizePartial($order->id, [
            ['output_id' => $this->byproductOutput($order)->id, 'qty_produced' => 2],
        ]);
    }

    public function test_partial_ditolak_bila_langkah_terakhir_belum_dikerjakan(): void
    {
        $order = $this->makeOrder(wip: 2_800_000);
        DB::table('production_order_steps')->where('production_order_id', $order->id)
            ->update(['status' => 'pending']);

        $this->expectExceptionMessageMatches('/langkah terakhir/i');

        $this->service->finalizePartial($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 5],
        ]);
    }

    public function test_revisi_target_tidak_boleh_di_bawah_qty_yang_sudah_masuk_stok(): void
    {
        $order = $this->makeOrder(wip: 2_800_000, byproductQty: null);

        $this->service->finalizePartial($order->id, [
            ['output_id' => $this->mainOutput($order)->id, 'qty_produced' => 20],
        ]);

        $this->expectExceptionMessageMatches('/tidak boleh di bawah qty yang sudah masuk stok/i');

        $this->service->reviseTarget($order->id, [
            'planned_cycles' => 1,
            'outputs'        => [
                ['output_id' => $this->mainOutput($order)->id, 'qty_planned' => 15],
            ],
            'reason' => 'salah input',
        ]);
    }

    // ── Pembatalan LIFO ───────────────────────────────────────────────────────────

    public function test_batch_hanya_bisa_dibatalkan_dari_yang_terakhir(): void
    {
        $order = $this->makeOrder(wip: 2_800_000, byproductQty: null);
        $mainId = $this->mainOutput($order)->id;

        $this->service->finalizePartial($order->id, [['output_id' => $mainId, 'qty_produced' => 10]]);
        $this->service->finalizePartial($order->id, [['output_id' => $mainId, 'qty_produced' => 10]]);

        $first = ProductionFinalization::where('production_order_id', $order->id)->where('sequence', 1)->firstOrFail();

        try {
            $this->service->voidBatch($first->id);
            $this->fail('Batch tengah seharusnya tidak bisa dibatalkan.');
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression('/pengambilan terakhir/i', $e->getMessage());
        }

        // Batch terakhir boleh: stok keluar lagi, biayanya kembali ke WIP.
        $last = ProductionFinalization::where('production_order_id', $order->id)->where('sequence', 2)->firstOrFail();
        $wipBefore = $this->sisaWip($order);

        $this->service->voidBatch($last->id);

        $this->assertEqualsWithDelta($wipBefore + (float) $last->wip_released, $this->sisaWip($order), 0.01);
        $this->assertSame('partial', $order->refresh()->status, 'masih ada batch pertama yang aktif');
        $this->assertEqualsWithDelta(10, (float) $this->mainOutput($order)->qty_produced, 0.001);
        $this->assertSame(0, StockLayer::where('production_finalization_id', $last->id)->count());
    }

    public function test_membatalkan_batch_terakhir_mengembalikan_status_produksi(): void
    {
        $order = $this->makeOrder(wip: 2_800_000, byproductQty: null);
        $mainId = $this->mainOutput($order)->id;

        $this->service->finalizePartial($order->id, [['output_id' => $mainId, 'qty_produced' => 10]]);
        $batch = ProductionFinalization::where('production_order_id', $order->id)->firstOrFail();

        $this->service->voidBatch($batch->id);

        $this->assertSame('in_progress', $order->refresh()->status, 'langkah terakhir masih berjalan');
        $this->assertEqualsWithDelta(2_800_000, $this->sisaWip($order), 0.01);
        $this->assertEqualsWithDelta(0, (float) $this->mainOutput($order)->qty_produced, 0.001);
    }

    // ── Neraca WIP ────────────────────────────────────────────────────────────────

    public function test_rincian_wip_terpisah_per_sumber(): void
    {
        $order      = $this->makeOrder(wip: 2_800_000, byproductQty: null);
        $additionId = $this->fakeAdditionId($order);

        $this->addWip($order, 50_000, 'production_material_addition', $additionId);
        $this->addWip($order, 20_000, 'production_cost_addition', $additionId);

        $breakdown = $this->service->wipBreakdown($order->id);

        $this->assertEqualsWithDelta(2_800_000, $breakdown['material'], 0.01);
        $this->assertEqualsWithDelta(50_000, $breakdown['addition_material'], 0.01);
        $this->assertEqualsWithDelta(20_000, $breakdown['addition_cost'], 0.01);
        $this->assertEqualsWithDelta(2_870_000, $breakdown['total'], 0.01);
    }

    public function test_pembatalan_penambahan_bahan_mengurangi_wip(): void
    {
        $order      = $this->makeOrder(wip: 2_800_000, byproductQty: null);
        $additionId = $this->fakeAdditionId($order);

        $this->addWip($order, 50_000, 'production_material_addition', $additionId);
        $this->assertEqualsWithDelta(2_850_000, $this->sisaWip($order), 0.01);

        // Void memasang jurnal balik Cr. WIP, bukan mem-void jurnal aslinya. Kalau hanya
        // debit yang dijumlah, bahan yang sudah balik ke gudang tetap terhitung di WIP dan
        // batch penutup melepas lebih besar dari saldo WIP nyata.
        $this->reverseWip(50_000, 'production_material_addition_void', $additionId);

        $breakdown = $this->service->wipBreakdown($order->id);
        $this->assertEqualsWithDelta(0, $breakdown['addition_material'], 0.01);
        $this->assertEqualsWithDelta(2_800_000, $breakdown['total'], 0.01);
        $this->assertEqualsWithDelta(2_800_000, $this->sisaWip($order), 0.01);
    }

    public function test_penutup_menghabiskan_wip_setelah_penambahan_dibatalkan(): void
    {
        $order      = $this->makeOrder(wip: 2_800_000, byproductQty: null);
        $mainId     = $this->mainOutput($order)->id;
        $additionId = $this->fakeAdditionId($order);

        $this->addWip($order, 50_000, 'production_material_addition', $additionId);
        $this->service->finalizePartial($order->id, [['output_id' => $mainId, 'qty_produced' => 10]]);
        $this->reverseWip(50_000, 'production_material_addition_void', $additionId);

        $this->service->finalize($order->id, [['output_id' => $mainId, 'qty_produced' => 54]]);

        $this->assertEqualsWithDelta(0, $this->sisaWip($order), 0.01, 'sisa WIP wajib nol setelah penutup');
    }

    // ── Halaman ───────────────────────────────────────────────────────────────────

    public function test_halaman_partial_dan_riwayat_batch_terbuka(): void
    {
        $order  = $this->makeOrder(wip: 2_800_000);
        $mainId = $this->mainOutput($order)->id;

        $this->actingAs($this->admin())
            ->get(route('production.process.partial-confirm', $order->id))
            ->assertOk()
            ->assertSee('Selesaikan Sebagian');

        $this->service->finalizePartial($order->id, [['output_id' => $mainId, 'qty_produced' => 10]]);

        // Halaman order menampilkan riwayat pengambilan + tombol batal untuk batch terakhir.
        $this->actingAs($this->admin())
            ->get(route('production.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Pengambilan Hasil')
            ->assertSee('Partial #1');

        // Layar finalisasi penutup menanyakan sisa target, bukan target penuh.
        $this->actingAs($this->admin())
            ->get(route('production.orders.finalize-confirm', $order->id))
            ->assertOk()
            ->assertSee('Qty Penutup');
    }

    public function test_revisi_target_lewat_http(): void
    {
        $order = $this->makeOrder(wip: 2_800_000, byproductQty: null);

        $this->actingAs($this->admin())
            ->post(route('production.orders.revise-target', $order->id), [
                'planned_cycles' => 1.25,
                'reason'         => 'operator potong 10 lembar, rencana 8',
                'outputs'        => [
                    ['output_id' => $this->mainOutput($order)->id, 'qty_planned' => 80],
                ],
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(80, (float) $order->refresh()->planned_qty, 0.001);
        $this->assertDatabaseHas('production_target_revisions', [
            'production_order_id' => $order->id,
            'reason'              => 'operator potong 10 lembar, rencana 8',
        ]);
    }

    /**
     * Yang menjalankan "Ambil Sebagian" adalah operator divisi (role user), bukan admin. Rutenya
     * SENGAJA dinamai `production.process.*` supaya izinnya ikut menu Proses Produksi — waktu
     * masih bernama `production.orders.*` ia terkunci di balik menu Order Produksi yang tidak
     * dipegang operator, sehingga tombolnya ada tapi selalu berujung "akses ditolak".
     */
    public function test_operator_divisi_bisa_membuka_halaman_ambil_sebagian(): void
    {
        $order = $this->makeOrder(wip: 2_800_000, byproductQty: null);

        $this->actingAs($this->operator())
            ->get(route('production.process.partial-confirm', $order->id))
            ->assertOk()
            ->assertSee('Selesaikan Sebagian');
    }

    /** Operator divisi: role user dengan izin papan Proses Produksi divisinya saja. */
    private function operator(): \App\Models\User
    {
        $deptId = DB::table('production_departments')->insertGetId([
            'code' => 'CNC', 'name' => 'CNC', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = \App\Models\User::factory()->create(['role' => 'user', 'is_active' => true]);
        DB::table('user_menu_permissions')->insert([
            'user_id' => $user->id, 'menu_key' => "production.process.{$deptId}",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function admin(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /** Id penambahan bahan palsu — cukup unik supaya jurnalnya tidak bentrok. */
    private function fakeAdditionId(ProductionOrder $order): int
    {
        return DB::table('production_material_additions')->insertGetId([
            'production_order_id' => $order->id,
            'addition_number'     => 'MAT-' . uniqid(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }
}
