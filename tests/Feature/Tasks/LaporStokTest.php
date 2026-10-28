<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Purchasing\Models\LaporStok;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lapor Stok: laporan divisi → task Beli (digabung, "habis" lebih mendesak), menempel ke task
 * Purchasing yang sudah ada (cek non stok naik jadi Beli), dan barang ready stok yang di ERP
 * masih banyak → task Cek stok (opname).
 */
class LaporStokTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $operator;
    private Warehouse $gudang;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin    = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->operator = User::factory()->create(['role' => 'user', 'is_active' => true, 'name' => 'Operator Packing']);
        $this->gudang   = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Toko Lem', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'layanan', 'user_id' => $this->admin->id, 'urutan' => 1]);
        foreach (['purchasing-laporan', 'purchasing-nonstok'] as $s) {
            MejaKerjaSkill::create(['skill' => $s, 'peran' => 'layanan', 'bobot' => 7, 'sla_menit' => 240]);
            KaryawanVirtualSkill::create(['skill' => "admin.$s", 'mode' => 'otomatis', 'mode_diubah_at' => now()->subDays(30)]);
        }
    }

    private function barang(string $sku, string $jenis = 'non_stock', float $stok = 0, ?float $min = null): Product
    {
        $p = Product::create(['sku' => $sku, 'name' => "Barang $sku", 'sale_type' => $jenis, 'base_unit' => 'pcs', 'base_price' => 0, 'is_active' => true, 'min_stock' => $min]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    private function po(Product $p, string $tanggal): void
    {
        $po = PurchaseOrder::create(['po_number' => 'PO/' . uniqid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->gudang->id,
            'po_date' => $tanggal, 'subtotal' => 0, 'total' => 0, 'status' => 'posted']);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $p->id, 'description' => $p->name,
            'qty' => 1, 'unit' => 'pcs', 'price' => 1000, 'subtotal' => 1000, 'qty_invoiced' => 1]);
    }

    private function lapor(array $isi): void
    {
        $this->actingAs($this->operator)->post(route('lapor-stok.simpan'), $isi + ['divisi' => 'Packing', 'kondisi' => 'habis'])->assertRedirect();
    }

    public function test_laporan_digabung_jadi_satu_task_beli_lalu_selesai_saat_po_dibuat(): void
    {
        $lakban = $this->barang('LAKBAN');
        $this->actingAs($this->operator)->getJson(route('lapor-stok.pilihan', ['divisi' => 'Packing']))
            ->assertOk()->assertJsonFragment(['sku' => 'LAKBAN', 'jenis' => 'non stok']);

        $this->lapor(['product_id' => $lakban->id, 'catatan' => 'tinggal 1 rol']);
        $this->lapor(['product_id' => $lakban->id, 'divisi' => 'Assembling', 'kondisi' => 'hampir_habis']);
        $meja = app(MejaKerjaService::class);

        $this->assertSame(1, $meja->sinkron()['dibuat']);
        $t = Task::where('skill', 'purchasing-laporan')->first();
        $this->assertStringContainsString('sudah habis', $t->title);
        $this->assertSame(120, (int) $t->created_at->diffInMinutes($t->tenggat_at), 'Sudah habis → tenggat separuh.');
        $this->assertSame(2, LaporStok::where('task_id', $t->id)->count());
        $this->assertSame('Diproses Purchasing', LaporStok::first()->statusLabel()[0]);
        $this->actingAs($this->operator)->getJson(route('lapor-stok.pilihan', ['divisi' => 'Packing']))->assertJsonFragment(['barang' => 'Barang LAKBAN']);

        $this->travel(1)->minutes();
        $this->po($lakban, now()->toDateString());
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame('Sudah ditangani', LaporStok::first()->fresh()->statusLabel()[0]);
    }

    public function test_laporan_menempel_ke_task_cek_non_stok_dan_menaikkannya_jadi_beli(): void
    {
        $lem = $this->barang('LEM');
        foreach ([40, 26, 12] as $h) $this->po($lem, now()->subDays($h)->toDateString());
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $cek = Task::where('skill', 'purchasing-nonstok')->first();
        $this->assertSame('cek', $cek->meta['tahap']);

        $this->lapor(['product_id' => $lem->id]);
        $cek->refresh();
        $this->assertSame('beli', $cek->meta['tahap']);
        $this->assertStringStartsWith('Beli', $cek->title);
        $this->assertSame($cek->id, LaporStok::first()->task_id);
        $this->assertSame(0, $meja->sinkron()['dibuat'], 'Tidak ada task kembar.');
    }

    public function test_ready_stok_masih_banyak_jadi_task_opname(): void
    {
        $akrilik = $this->barang('AKR', 'ready', 80, 20);
        $this->po($akrilik, now()->subDays(20)->toDateString());
        $this->lapor(['product_id' => $akrilik->id]);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();

        $t = Task::where('skill', 'purchasing-laporan')->first();
        $this->assertSame('opname', $t->meta['tahap']);
        $this->assertStringContainsString('ERP mencatat 80', $t->title);

        $this->travel(1)->minutes();
        DB::table('inventory_ledgers')->insert(['product_id' => $akrilik->id, 'transaction_type' => 'adjustment_out', 'transaction_id' => 1,
            'qty_in' => 0, 'qty_out' => 80, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_barang_belum_terdaftar_dijawab_manual(): void
    {
        $this->actingAs($this->operator)->post(route('lapor-stok.simpan'), ['divisi' => 'Packing', 'kondisi' => 'habis'])->assertSessionHasErrors('product_id');
        $this->lapor(['nama_barang' => 'Tali rafia']);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'purchasing-laporan')->first();
        $this->assertNull($t->taskable_id);
        $this->assertStringContainsString('Tali rafia', $t->title);

        $this->actingAs($this->admin)->post(route('tasks.meja.mulai', $t->id));
        $this->actingAs($this->admin)->post(route('tasks.meja.jawab', $t->id), ['jawaban' => 'dibeli'])->assertRedirect();
        $this->assertSame('done', $t->fresh()->status);
    }
}
