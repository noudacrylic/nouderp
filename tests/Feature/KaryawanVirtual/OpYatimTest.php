<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Skill "Urus OP pre-order yang pesanannya batal": batalkan OP antre, lepas OP yang sudah
 * dikerjakan (yatim, Prioritas Rendah), lalu serahkan ke pesanan baru yang kekurangan barang.
 */
class OpYatimTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'produksi.op-yatim';

    private Warehouse $gudang;
    private Department $cnc;
    private Department $rakit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gudang = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        $this->cnc    = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->rakit  = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
    }

    private function produk(bool $madeToOrder = false, float $stok = 0): Product
    {
        $p = Product::create([
            'sku' => 'BC-' . uniqid(), 'name' => 'Box Charger', 'sale_type' => 'preorder',
            'made_to_order' => $madeToOrder, 'base_unit' => 'set', 'base_price' => 100000, 'is_active' => true,
        ]);
        $bom = Bom::create(['bom_number' => 'BOM-' . uniqid(), 'name' => 'BOM ' . $p->sku, 'auto_production' => true, 'typical_cycles' => 1]);
        BomOutput::create(['bom_id' => $bom->id, 'product_id' => $p->id, 'qty_per_cycle' => 1, 'output_type' => 'main', 'percentage' => 100]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    /** Pesanan confirmed + reservasi; DP diposting → OP pre-order lahir lewat observer. */
    private function pesan(Product $p, float $qty = 1, bool $dp = true): SalesOrder
    {
        static $n = 0;
        $n++;
        $cust = Customer::create(['code' => 'C-' . uniqid(), 'name' => 'Pelanggan', 'is_marketplace' => false, 'is_active' => true]);
        $so = SalesOrder::create([
            'order_number' => "SO-YT-{$n}", 'customer_id' => $cust->id, 'warehouse_id' => $this->gudang->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'grand_total' => $qty * 100000, 'paid_amount' => 0,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $p->id, 'qty' => $qty, 'conversion_to_base' => 1,
            'unit_price' => 100000, 'net_unit_price' => 100000, 'line_subtotal' => $qty * 100000,
            'line_discount' => 0, 'line_total' => $qty * 100000,
        ]);
        StockReservation::create(['product_id' => $p->id, 'warehouse_id' => $so->warehouse_id, 'sales_order_id' => $so->id, 'qty' => $qty, 'status' => 'active']);
        if ($dp) {
            SalesAdvance::create([
                'advance_number' => "DP-YT-{$n}", 'sales_order_id' => $so->id, 'customer_id' => $cust->id,
                'bank_account_id' => 1, 'advance_date' => now()->toDateString(), 'amount' => 50000, 'status' => 'posted',
            ]);
        }
        return $so;
    }

    /** OP milik SO, diberi langkah CNC → Assembling; $mulai = CNC sudah dikerjakan. */
    private function opDari(SalesOrder $so, bool $mulai): ProductionOrder
    {
        $op = ProductionOrder::where('sales_order_id', $so->id)->firstOrFail();
        ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => $this->cnc->id,
            'name' => 'CNC', 'status' => $mulai ? 'completed' : 'pending', 'started_at' => $mulai ? now() : null]);
        ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 2, 'department_id' => $this->rakit->id,
            'name' => 'Assembling', 'status' => 'pending']);
        return $op;
    }

    private function batalkan(SalesOrder $so): void
    {
        app(SalesOrderService::class)->voidConfirmed($so->refresh());
    }

    private function jalan(): array
    {
        return app(KaryawanVirtualService::class)->jalankan(self::SKILL, KaryawanVirtualService::PEMICU_MANUAL);
    }

    public function test_op_antre_milik_pesanan_batal_dibatalkan(): void
    {
        $p  = $this->produk();
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: false);

        // Jalur void yang terlewat (mis. status diubah langsung) — sapuan menangkapnya.
        $so->update(['status' => 'void']);
        $this->jalan();

        $this->assertSame('cancelled', $op->refresh()->status);
    }

    public function test_op_sudah_dikerjakan_dilepas_jadi_yatim_prioritas_rendah(): void
    {
        $p  = $this->produk();
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: true);

        $this->batalkan($so);
        $this->assertSame($so->id, $op->refresh()->sales_order_id, 'Void tak bisa membatalkan OP yang sudah dikerjakan.');

        $hasil = $this->jalan();
        $op->refresh();

        $this->assertNull($op->sales_order_id);
        $this->assertSame(self::SKILL, $op->kv_skill);
        $this->assertSame('priority', $op->score_type);
        $this->assertSame('low', $op->priority_level);
        $this->assertNotSame('cancelled', $op->status);
        $this->assertSame(Skill::DIUBAH, $hasil['rincian'][0]['status']);
        $this->assertDatabaseHas('production_order_so_changes', [
            'production_order_id' => $op->id, 'from_sales_order_id' => $so->id, 'to_sales_order_id' => null,
        ]);
    }

    public function test_pesanan_baru_yang_kekurangan_mengambil_alih_op_yatim(): void
    {
        $p  = $this->produk();
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: true);
        $this->batalkan($so);
        $this->jalan();

        $baru = $this->pesan($p);
        $this->assertSame(1, ProductionOrder::where('status', '!=', 'cancelled')->count(),
            'Netting pre-order menghitung OP yatim → pesanan baru tidak dibuatkan OP kedua.');

        $this->jalan();
        $op->refresh();

        $this->assertSame($baru->id, $op->sales_order_id);
        $this->assertNull($op->kv_skill);
        $this->assertSame('auto', $op->score_type);
        $this->assertNull($op->priority_level);
        $this->assertDatabaseHas('production_order_so_changes', [
            'production_order_id' => $op->id, 'from_sales_order_id' => null, 'to_sales_order_id' => $baru->id,
        ]);
    }

    public function test_stok_rak_didahulukan_op_tetap_yatim(): void
    {
        $p  = $this->produk();
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: true);
        $this->batalkan($so);
        $this->jalan();

        ProductStock::where('product_id', $p->id)->update(['qty_on_hand' => 1]);
        $this->pesan($p);
        $this->jalan();

        $this->assertNull($op->refresh()->sales_order_id, 'Pesanan tertutup stok rak → tidak mengambil alih.');
        $this->assertSame('low', $op->priority_level);
    }

    public function test_pesanan_ber_dp_paling_lama_yang_mengambil_alih(): void
    {
        $p  = $this->produk();
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: true);
        $this->batalkan($so);
        $this->jalan();

        // BOM auto dimatikan supaya pesanan berikut tidak dibuatkan OP sendiri oleh pemicu DP.
        Bom::query()->update(['auto_production' => false]);
        $this->pesan($p, dp: false); // paling lama tapi belum DP → bukan calon
        $lama = $this->pesan($p);
        $this->pesan($p);

        $this->jalan();

        $this->assertSame($lama->id, $op->refresh()->sales_order_id);
    }

    public function test_produk_dibuat_khusus_tidak_disentuh(): void
    {
        $p  = $this->produk(madeToOrder: true);
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: true);
        $this->batalkan($so);

        $hasil = $this->jalan();

        $this->assertSame($so->id, $op->refresh()->sales_order_id);
        $this->assertSame('auto', $op->score_type);
        $this->assertSame([], $hasil['hasil']);
    }

    public function test_mode_bayangan_tidak_mengubah_apa_pun(): void
    {
        app(KaryawanVirtualService::class)->ubahMode(self::SKILL, Skill::BAYANGAN, null);

        $p  = $this->produk();
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: true);
        $this->batalkan($so);

        $hasil = $this->jalan();

        $this->assertSame($so->id, $op->refresh()->sales_order_id);
        $this->assertSame(Skill::AKAN_DIBUAT, $hasil['rincian'][0]['status']);
        $this->assertSame(0, DB::table('production_order_so_changes')->count());
    }

    public function test_op_yatim_bisa_dipilihkan_so_manual_dari_halaman_op(): void
    {
        $p  = $this->produk();
        $so = $this->pesan($p);
        $op = $this->opDari($so, mulai: true);
        $this->batalkan($so);
        $this->jalan();

        $baru = $this->pesan($p);
        app(\App\Modules\Production\Services\ProductionOrderService::class)
            ->gantiSalesOrder($op->id, $baru->id, 'Dipilih manual');

        $op->refresh();
        $this->assertSame($baru->id, $op->sales_order_id);
        $this->assertSame('auto', $op->score_type);
    }
}
