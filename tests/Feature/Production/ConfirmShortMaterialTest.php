<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\FifoService;
use App\Enums\AccountCodeEnum;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderMaterial;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kekurangan bahan tidak boleh mengunci produksi.
 *
 * Aturan: konfirmasi OP tetap jalan walau stok bahan habis/minus (bahan dibeli sambil
 * produksi berjalan) — konsumsi bahan yang kurang DITUNDA ke finalisasi. Yang tetap galak
 * adalah finalisasi: di sana stok wajib benar-benar cukup.
 */
class ConfirmShortMaterialTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrderService $service;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductionOrderService::class);

        foreach ([
            [AccountCodeEnum::WIP, 'Barang Dalam Proses'],
            [AccountCodeEnum::INVENTORY, 'Persediaan'],
        ] as [$code, $name]) {
            DB::table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => 'asset',
                'normal_balance' => 'debit', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'Utama', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(string $sku, string $name): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Draft OP dengan dua bahan: satu tersedia, satu belum dibeli. */
    private function makeDraft(int $ada, int $kurang, int $hasil): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number'    => 'OP-TEST-' . uniqid(),
            'type'            => 'custom',
            'warehouse_id'    => $this->warehouseId,
            'planned_cycles'  => 1,
            'planned_qty'     => 10,
            'production_date' => now()->toDateString(),
            'status'          => 'draft',
        ]);

        foreach ([[$ada, 4], [$kurang, 6]] as [$productId, $qty]) {
            ProductionOrderMaterial::create([
                'production_order_id' => $order->id,
                'product_id'          => $productId,
                'qty_required'        => $qty,
                'qty_consumed'        => 0,
            ]);
        }

        ProductionOrderOutput::create([
            'production_order_id' => $order->id,
            'product_id'          => $hasil,
            'qty_planned'         => 10,
            'qty_produced'        => 0,
            'output_type'         => 'main',
            'percentage'          => 0,
        ]);

        return $order->refresh();
    }

    public function test_konfirmasi_jalan_terus_walau_satu_bahan_belum_ada(): void
    {
        $ada    = $this->product('LBR-3MM', 'Lembaran 3mm');
        $kurang = $this->product('LEM-01', 'Lem Akrilik');
        $hasil  = $this->product('AM-01', 'Akrilik Menu');

        app(FifoService::class)->stockIn($ada, $this->warehouseId, 'purchase', 'PO-UJI', 10, 50000);

        $order = $this->makeDraft($ada, $kurang, $hasil);

        $deferred = $this->service->confirm($order->id);

        $this->assertSame('confirmed', $order->refresh()->status);
        $this->assertCount(1, $deferred);
        $this->assertStringContainsString('LEM-01', $deferred[0]);

        // Bahan yang ada tetap keluar stok sekarang; yang kurang belum tersentuh.
        $materials = $order->materials()->get()->keyBy('product_id');
        $this->assertEqualsWithDelta(4, (float) $materials[$ada]->qty_consumed, 1e-6);
        $this->assertEqualsWithDelta(0, (float) $materials[$kurang]->qty_consumed, 1e-6);
        $this->assertEqualsWithDelta(6, (float) DB::table('product_stocks')
            ->where('product_id', $ada)->value('qty_on_hand'), 1e-6);
    }

    public function test_finalisasi_tetap_menolak_saat_bahan_masih_kurang(): void
    {
        $ada    = $this->product('LBR-3MM', 'Lembaran 3mm');
        $kurang = $this->product('LEM-01', 'Lem Akrilik');
        $hasil  = $this->product('AM-01', 'Akrilik Menu');

        app(FifoService::class)->stockIn($ada, $this->warehouseId, 'purchase', 'PO-UJI', 10, 50000);

        $order = $this->makeDraft($ada, $kurang, $hasil);
        $this->service->confirm($order->id);
        $order->update(['status' => 'completed']);

        $outputId = $order->outputs()->value('id');

        $this->expectExceptionMessageMatches('/Stok material belum mencukupi/');
        $this->service->finalize($order->id, [
            ['output_id' => $outputId, 'qty_produced' => 10],
        ]);
    }
}
