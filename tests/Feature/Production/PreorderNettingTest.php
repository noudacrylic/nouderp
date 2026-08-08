<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\PreorderAutoProductionService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pemicu order produksi preorder dulu cuma bertanya "berapa qty produk ini yang sudah
 * direncanakan OP UNTUK SO INI?" — tak pernah "apakah barangnya sudah ada di gudang?".
 * Jadi tiap pesanan baru selalu memicu produksi baru, dan unit sisa pesanan yang batal
 * mengendap jadi deadstock sambil produk penggantinya dibuat lagi.
 *
 * Nettingnya sekarang GLOBAL per produk: permintaan − stok − produksi yang sedang berjalan.
 * Global, bukan per-SO — kalau per-SO, dua pesanan yang datang hampir bersamaan sama-sama
 * melihat "stok sisa sudah dipesan orang lain" lalu sama-sama membuat OP.
 *
 * Produk yang dibuat mengikuti permintaan pembeli tidak ikut: unitnya tidak saling
 * menggantikan, jadi satu pesanan tetap satu OP.
 */
class PreorderNettingTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);
    }

    /** Produk preorder + BOM auto (syarat pemicu: auto_production & output utama 1/siklus). */
    private function produk(bool $dibuatKhusus): Product
    {
        $p = Product::create([
            'sku'           => ($dibuatKhusus ? 'CS-' : 'BC-') . uniqid(),
            'name'          => 'Produk Preorder',
            'sale_type'     => 'preorder',
            'made_to_order' => $dibuatKhusus,
            'base_unit'     => 'pcs',
            'base_price'    => 100000,
            'is_active'     => true,
        ]);

        $bom = Bom::create([
            'bom_number' => 'BOM-' . uniqid(), 'name' => 'BOM ' . $p->sku,
            'auto_production' => true, 'typical_cycles' => 1,
        ]);
        BomOutput::create([
            'bom_id' => $bom->id, 'product_id' => $p->id,
            'qty_per_cycle' => 1, 'output_type' => 'main', 'percentage' => 100,
        ]);

        return $p;
    }

    private function so(Product $p, float $qty = 1): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Toko Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);

        $so = SalesOrder::create([
            'order_number' => 'SO-NET-' . uniqid(),
            'customer_id'  => $cust->id,
            'warehouse_id' => $this->warehouse()->id,
            'order_date'   => now()->toDateString(),
            'status'       => 'confirmed',
            'grand_total'  => 100000 * $qty,
            'paid_amount'  => 100000 * $qty,
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $p->id,
            'qty'                => $qty,
            'conversion_to_base' => 1,
            'unit_price'         => 100000,
            'net_unit_price'     => 100000,
            'line_subtotal'      => 100000 * $qty,
            'line_discount'      => 0,
            'line_total'         => 100000 * $qty,
        ]);

        // SO confirmed mereservasi stok — reservasi inilah "permintaan" yang di-netting.
        StockReservation::create([
            'product_id'     => $p->id,
            'warehouse_id'   => $so->warehouse_id,
            'sales_order_id' => $so->id,
            'qty'            => $qty,
            'status'         => 'active',
        ]);

        return $so->refresh();
    }

    private function stok(Product $p, float $qty): void
    {
        ProductStock::updateOrCreate(
            ['product_id' => $p->id, 'warehouse_id' => $this->warehouse()->id],
            ['qty_on_hand' => $qty]
        );
    }

    private function picu(SalesOrder $so): array
    {
        return app(PreorderAutoProductionService::class)->runForSalesOrder($so->load('items.product', 'customer'));
    }

    private function opCount(Product $p): int
    {
        return ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))
            ->where('status', '!=', 'cancelled')->count();
    }

    // ───────────── Inti masalahnya ─────────────

    public function test_stok_sisa_menutup_pesanan_baru_tanpa_op_baru(): void
    {
        $p = $this->produk(dibuatKhusus: false);
        $this->stok($p, 1); // sisa dari pesanan yang batal
        $so = $this->so($p);

        $hasil = $this->picu($so);

        $this->assertSame(0, $this->opCount($p), 'Barangnya sudah ada — jangan produksi lagi.');
        $this->assertStringContainsString('sudah tertutup', $hasil[0]['reason']);
    }

    public function test_tanpa_stok_op_tetap_dibuat(): void
    {
        $p  = $this->produk(dibuatKhusus: false);
        $so = $this->so($p);

        $this->picu($so);

        $this->assertSame(1, $this->opCount($p));
    }

    /** Kekurangannya saja yang diproduksi, bukan seluruh qty pesanan. */
    public function test_stok_sebagian_hanya_memproduksi_kekurangannya(): void
    {
        $p = $this->produk(dibuatKhusus: false);
        $this->stok($p, 2);
        $so = $this->so($p, qty: 5);

        $this->picu($so);

        $op = ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))->first();
        $this->assertNotNull($op);
        $this->assertEquals(3, (int) $op->planned_cycles, '5 dipesan − 2 di rak = 3 yang perlu dibuat.');
    }

    /**
     * Dua pesanan berebut satu unit sisa. Netting per-SO akan membuat DUA OP (masing-masing
     * melihat unit itu sudah dipesan orang lain) → overproduksi. Netting global tidak.
     */
    public function test_dua_pesanan_berebut_satu_unit_tidak_overproduksi(): void
    {
        $p = $this->produk(dibuatKhusus: false);
        $this->stok($p, 1);

        $a = $this->so($p);
        $b = $this->so($p);

        $this->picu($a);
        $this->picu($b);

        // Permintaan 2, stok 1 → cukup satu OP berisi 1 unit.
        $this->assertSame(1, $this->opCount($p));
        $total = (float) ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))
            ->where('status', '!=', 'cancelled')->sum('planned_cycles');
        $this->assertEquals(1, $total);
    }

    /** OP yang sedang berjalan sudah menutup kebutuhan — pesanan kedua tak perlu OP lagi. */
    public function test_produksi_berjalan_ikut_menutup_kebutuhan(): void
    {
        $p = $this->produk(dibuatKhusus: false);
        $a = $this->so($p);
        $this->picu($a);
        $this->assertSame(1, $this->opCount($p));

        // Pesanan kedua datang, stok masih 0 tapi OP pertama sedang jalan untuk 1 unit.
        // Permintaan naik jadi 2 → masih kurang 1 → OP kedua memang perlu.
        $b = $this->so($p);
        $this->picu($b);

        $total = (float) ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))
            ->where('status', '!=', 'cancelled')->sum('planned_cycles');
        $this->assertEquals(2, $total, 'Dua pesanan tanpa stok = dua unit, tidak lebih.');
    }

    // ───────────── Produk dibuat khusus: perilaku lama ─────────────

    public function test_produk_dibuat_khusus_tetap_satu_pesanan_satu_op(): void
    {
        $p = $this->produk(dibuatKhusus: true);
        $this->stok($p, 5); // angka stok SKU custom tidak berarti barang yang sama
        $so = $this->so($p);

        $this->picu($so);

        $this->assertSame(1, $this->opCount($p),
            'Unit CS di rak milik spesifikasi orang lain — pesanan ini tetap harus dibuatkan.');
    }

    // ───────────── Void SO membatalkan produksinya ─────────────

    /**
     * Pembatalan otomatis dulu bersyarat `status === 'draft'`. Padahal OP preorder LAHIR
     * 'confirmed' (soft-confirm), jadi syarat itu tak pernah terpenuhi: OP dibiarkan jalan
     * setelah pesanannya batal, barangnya jadi, dan menumpuk sebagai deadstock. Inilah
     * sumber sisa produksi yang selama ini muncul entah dari mana.
     */
    public function test_void_so_membatalkan_op_preorder_yang_belum_dikerjakan(): void
    {
        $p  = $this->produk(dibuatKhusus: false);
        $so = $this->so($p);
        $this->picu($so);

        $op = ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))->firstOrFail();
        $this->assertSame('confirmed', $op->status, 'OP preorder memang lahir confirmed, bukan draft.');

        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)
            ->post(route('sales.orders.void', $so->id))
            ->assertRedirect();

        $this->assertSame('cancelled', $op->refresh()->status);
        $this->assertStringContainsString('Auto-cancel', (string) $op->notes);
    }

    /** OP yang sudah mulai dikerjakan tidak bisa dibatalkan — dan itu harus DISEBUT, bukan didiamkan. */
    public function test_op_yang_sudah_dikerjakan_dilaporkan_bukan_didiamkan(): void
    {
        $p  = $this->produk(dibuatKhusus: false);
        $so = $this->so($p);
        $this->picu($so);

        // BOM di fixture tidak punya langkah, jadi langkahnya dibuat langsung — yang diuji
        // adalah guard "sudah mulai dikerjakan", bukan cara langkah itu lahir.
        $op = ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))->firstOrFail();
        \App\Modules\Production\Models\ProductionOrderStep::create([
            'production_order_id' => $op->id,
            'step_number'         => 1,
            'name'                => 'Potong',
            'status'              => 'in_progress',
            'started_at'          => now(),
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)
            ->post(route('sales.orders.void', $so->id))
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($m) => str_contains($m, $op->order_number));

        $this->assertSame('confirmed', $op->refresh()->status);
        $this->assertSame('void', $so->refresh()->status, 'Void SO tidak boleh gagal hanya karena produksinya jalan.');
    }
}
