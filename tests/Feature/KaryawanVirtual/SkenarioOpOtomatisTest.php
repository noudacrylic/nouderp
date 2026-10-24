<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skenario OP otomatis dari sisi pengguna — hanya memakai jalur yang sudah ada
 * SEBELUM Karyawan Virtual (DP diposting + production:run-auto), supaya berkas
 * yang sama bisa dijalankan di kode lama dan kode baru dan hasilnya dibandingkan.
 */
class SkenarioOpOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private function gudang(): Warehouse
    {
        return Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
    }

    private function produk(string $saleType, bool $madeToOrder, float $stok, ?float $minStok = null, bool $bomAuto = true, float $perSiklus = 1): Product
    {
        $p = Product::create([
            'sku' => strtoupper($saleType) . '-' . uniqid(), 'name' => 'Produk', 'sale_type' => $saleType,
            'made_to_order' => $madeToOrder, 'base_unit' => 'pcs', 'base_price' => 100000,
            'is_active' => true, 'min_stock' => $minStok,
        ]);
        $bom = Bom::create(['bom_number' => 'BOM-' . uniqid(), 'name' => 'BOM ' . $p->sku, 'auto_production' => $bomAuto, 'typical_cycles' => 1]);
        BomOutput::create(['bom_id' => $bom->id, 'product_id' => $p->id, 'qty_per_cycle' => $perSiklus, 'output_type' => 'main', 'percentage' => 100]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang()->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    /** Pesanan confirmed + reservasi stok; $dp = DP langsung diposting (pemicu OP pre-order). */
    private function pesan(Product $p, float $qty, bool $dp = true): SalesOrder
    {
        static $n = 0;
        $n++;
        $cust = Customer::create(['code' => 'C-' . uniqid(), 'name' => 'Pelanggan', 'is_marketplace' => false, 'is_active' => true]);
        $so = SalesOrder::create([
            'order_number' => "SO-SK-{$n}", 'customer_id' => $cust->id, 'warehouse_id' => $this->gudang()->id,
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
                'advance_number' => "DP-SK-{$n}", 'sales_order_id' => $so->id, 'customer_id' => $cust->id,
                'bank_account_id' => 1, 'advance_date' => now()->toDateString(), 'amount' => 50000, 'status' => 'posted',
            ]);
        }
        return $so;
    }

    /** [jumlah OP, total qty rencana] untuk produk ini. */
    private function op(Product $p): array
    {
        $ops = ProductionOrder::with('outputs')->whereHas('outputs', fn ($q) => $q->where('product_id', $p->id)->where('output_type', 'main'))->get();
        $qty = $ops->sum(fn ($o) => (float) $o->outputs->where('product_id', $p->id)->where('output_type', 'main')->sum('qty_planned'));
        return [$ops->count(), (float) $qty];
    }

    private function cron(): void
    {
        $this->artisan('production:run-auto')->assertSuccessful();
    }

    public function test_a_produk_custom_stok_masih_ada_pesanan_masuk_tetap_dibuatkan_op(): void
    {
        $p = $this->produk('preorder', true, stok: 5);

        $this->pesan($p, 2);
        $this->assertSame([1, 2.0], $this->op($p), 'Custom: 1 pesanan = 1 OP sebesar pesanan, stok tidak dipakai.');

        $this->pesan($p, 1);
        $this->assertSame([2, 3.0], $this->op($p), 'Pesanan custom kedua = OP sendiri lagi.');

        $this->cron();
        $this->assertSame([2, 3.0], $this->op($p), 'Sapuan 30 menit tidak membuat OP dobel.');
    }

    public function test_b_produk_custom_belum_dp_belum_dibuatkan_op_sampai_dp_masuk(): void
    {
        $p = $this->produk('preorder', true, stok: 0);

        $so = $this->pesan($p, 2, dp: false);
        $this->cron();
        $this->assertSame([0, 0.0], $this->op($p), 'Tanpa DP tidak ada OP, sapuan pun tidak.');

        SalesAdvance::create([
            'advance_number' => 'DP-B', 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id,
            'bank_account_id' => 1, 'advance_date' => now()->toDateString(), 'amount' => 50000, 'status' => 'posted',
        ]);
        $this->assertSame([1, 2.0], $this->op($p));
    }

    public function test_c_produk_custom_tanpa_bom_auto_tidak_dibuatkan_op(): void
    {
        $p = $this->produk('preorder', true, stok: 0, bomAuto: false);
        $this->pesan($p, 2);
        $this->cron();
        $this->assertSame([0, 0.0], $this->op($p));
    }

    public function test_d_preorder_umum_stok_menutup_lalu_kurang(): void
    {
        $p = $this->produk('preorder', false, stok: 5);

        $this->pesan($p, 2);
        $this->assertSame([0, 0.0], $this->op($p), 'Preorder umum: stok 5 menutup pesanan 2 → tanpa OP.');

        $this->pesan($p, 4);
        $this->assertSame([1, 1.0], $this->op($p), 'Dipesan 6, stok 5 → OP hanya untuk kekurangan 1.');

        $this->cron();
        $this->assertSame([1, 1.0], $this->op($p));
    }

    public function test_e_produk_ready_stok_aman_lalu_pesanan_membuatnya_menipis(): void
    {
        $p = $this->produk('ready', false, stok: 10, minStok: 5, perSiklus: 10);

        $this->pesan($p, 3, dp: false);
        $this->cron();
        $this->assertSame([0, 0.0], $this->op($p), 'Tersedia 10-3=7 > min 5 → aman.');

        $this->pesan($p, 3, dp: false);
        $this->cron();
        $this->assertSame([1, 10.0], $this->op($p), 'Tersedia 4 ≤ min 5 → OP ready stock 1 siklus.');

        $this->pesan($p, 3, dp: false);
        $this->cron();
        $this->assertSame([1, 10.0], $this->op($p), 'Sudah ada OP berjalan → tidak dobel.');
    }

    public function test_f_produk_custom_tidak_disentuh_pemeriksa_stok_menipis(): void
    {
        $p = $this->produk('preorder', true, stok: 0, minStok: 5);
        $this->cron();
        $this->assertSame([0, 0.0], $this->op($p), 'Stok custom 0 tanpa pesanan → tidak ada OP.');
    }
}
