<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OP pre-order skor Otomatis = flat 300 (tanpa skor BOM / demand), supaya antrean
 * pre-order urut siapa yang pesan duluan. Prioritas manual tetap berlaku.
 */
class SkorPreorderTest extends TestCase
{
    use RefreshDatabase;

    private function op(string $type, float $skorBom, string $scoreType = 'auto', ?string $level = null): ProductionOrder
    {
        static $n = 0;
        $n++;
        $gudang = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        $bom = Bom::create(['bom_number' => "BOM-SK-{$n}", 'name' => 'BOM', 'auto_production' => true, 'typical_cycles' => 1]);
        $bom->forceFill(['score' => $skorBom])->save();

        return ProductionOrder::create([
            'order_number' => "OP-SK-{$n}", 'type' => $type, 'bom_id' => $bom->id, 'warehouse_id' => $gudang->id,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => 'confirmed',
            'score_type' => $scoreType, 'priority_level' => $level, 'created_via' => 'auto_preorder',
        ])->load('bom');
    }

    public function test_preorder_otomatis_flat_300_walau_skor_bom_lebih_tinggi(): void
    {
        $this->assertEquals(300, $this->op('custom', 310)->effective_score);
        $this->assertEquals(300, $this->op('custom', 0)->effective_score);
    }

    public function test_prioritas_manual_dan_ready_stock_tidak_berubah(): void
    {
        $this->assertEquals(0, $this->op('custom', 310, 'priority', 'low')->effective_score);
        $this->assertEquals(204.05, $this->op('ready_stock', 204.05)->effective_score);
    }
}
