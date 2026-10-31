<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skill pembelian Meja Kerja: Purchasing ready stok (stok ≤ min & belum ada PO ditunggu → task, selesai
 * saat PO dibuat) dan Kedatangan barang (PO lewat tanggal datang → task, selesai saat difakturkan
 * atau tanggal datang dimundurkan), plus halaman saran min stok & isian PO dari task.
 */
class MejaKerjaPembelianTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private Supplier $supplier;
    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner    = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->admin    = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Kranindo', 'phone' => '0812-3456-7890', 'is_active' => true]);
        $this->gudang   = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true]);

        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);
        MejaKerjaPeran::create(['peran' => 'layanan', 'user_id' => $this->admin->id, 'urutan' => 1]);
    }

    private function nyalakan(string $skill, $sejak = null): void
    {
        MejaKerjaSkill::updateOrCreate(['skill' => $skill], ['peran' => 'layanan', 'bobot' => 6, 'sla_menit' => 240]);
        KaryawanVirtualSkill::updateOrCreate(['skill' => "admin.$skill"], ['mode' => 'otomatis', 'mode_diubah_at' => $sejak ?? now()->subDays(10)]);
    }

    private function bahan(string $sku, ?float $min, float $stok, string $jenis = 'ready'): Product
    {
        $p = Product::create(['sku' => $sku, 'name' => "Bahan $sku", 'sale_type' => $jenis, 'base_unit' => 'lembar', 'base_price' => 0, 'is_active' => true, 'min_stock' => $min]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    private function po(Product $p, string $tanggal, float $qty = 10, float $difakturkan = 0, ?string $datang = null): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO/TES/' . uniqid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->gudang->id,
            'po_date' => $tanggal, 'expected_date' => $datang, 'subtotal' => 0, 'total' => 0, 'status' => 'posted',
        ]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $p->id, 'description' => $p->name,
            'qty' => $qty, 'unit' => 'lembar', 'price' => 250000, 'subtotal' => 0, 'qty_invoiced' => $difakturkan]);
        return $po;
    }

    public function test_ready_stok_dibuat_sekali_lalu_selesai_saat_po_dibuat(): void
    {
        $this->nyalakan('purchasing-ready');
        $akrilik = $this->bahan('AKR-3', 50, 20);
        $this->po($akrilik, now()->subDays(40)->toDateString(), 10, 10); // pembelian lama, sudah datang semua
        $lem = $this->bahan('LEM', 5, 0, 'non_stock');
        $this->po($lem, now()->subDays(40)->toDateString(), 1, 1);
        $meja = app(MejaKerjaService::class);

        $this->assertSame(1, $meja->sinkron()['dibuat'], 'Barang non-stok tidak dipantau.');
        $this->assertSame(0, $meja->sinkron()['dibuat'], 'Satu bahan tidak membuat task kembar.');

        $t = Task::where('skill', 'purchasing-ready')->first();
        $this->assertSame('layanan', $t->peran);
        $this->assertSame($this->admin->id, $t->assignee_user_id, 'Ditugaskan Manajer Admin ke anggota meja Layanan.');
        $this->assertStringContainsString('AKR-3', $t->title);
        $this->assertStringContainsString('Kranindo', $t->description);

        $this->travel(1)->minutes();
        $this->po($akrilik, now()->toDateString());
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame(0, Task::where('skill', 'purchasing-ready')->whereIn('status', ['open', 'in_progress'])->count(), 'PO masih ditunggu → tidak ada task baru.');
    }

    public function test_ready_stok_batal_bila_stok_naik_tanpa_pembelian(): void
    {
        $this->nyalakan('purchasing-ready');
        $akrilik = $this->bahan('AKR-3', 50, 20);
        $this->po($akrilik, now()->subDays(40)->toDateString(), 10, 10);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();

        ProductStock::where('product_id', $akrilik->id)->update(['qty_on_hand' => 80]);
        $meja->sinkron();
        $this->assertSame('cancelled', Task::where('skill', 'purchasing-ready')->first()->status);
    }

    public function test_kedatangan_terlambat_lalu_selesai_saat_tanggal_dimundurkan_atau_difakturkan(): void
    {
        $this->nyalakan('kedatangan');
        $akrilik = $this->bahan('AKR-3', null, 0);
        $po = $this->po($akrilik, now()->subDays(5)->toDateString());                     // tempo = +3 hari → telat
        $this->po($akrilik, now()->subDays(1)->toDateString());                           // belum jatuh tempo
        $this->po($akrilik, now()->subDays(30)->toDateString());                          // telat sebelum skill dinyalakan
        $meja = app(MejaKerjaService::class);

        $this->assertSame(1, $meja->sinkron()['dibuat']);
        $t = Task::where('skill', 'kedatangan')->first();
        $this->assertSame($po->id, $t->taskable_id);
        $this->assertSame(now()->subDays(2)->toDateString(), $t->meta['jatuh_tempo']);
        $this->assertSame('https://wa.me/6281234567890', collect($meja->skill('kedatangan')->aksi($t))->firstWhere('label', 'WA supplier')['url']);

        $po->update(['expected_date' => now()->addDays(2)->toDateString()]);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status, 'Tanggal datang dimundurkan setelah konfirmasi supplier.');

        $this->travel(4)->days();
        $this->assertSame(2, $meja->sinkron()['dibuat'], 'Lewat lagi → task baru; PO kedua kini ikut terlambat.');
        $baru = Task::where('skill', 'kedatangan')->where('status', 'open')->where('taskable_id', $po->id)->first();
        $this->assertNotNull($baru);

        PurchaseOrderItem::where('purchase_order_id', $po->id)->update(['qty_invoiced' => 10]);
        $meja->sinkron();
        $this->assertSame('done', $baru->fresh()->status);
    }

    public function test_saran_min_stok_disimpan_dan_po_terisi_dari_task(): void
    {
        $akrilik = $this->bahan('AKR-3', null, 20);
        $this->po($akrilik, now()->subDays(40)->toDateString(), 10, 10);
        $lain = $this->bahan('BUKAN-BELI', null, 0);

        $this->actingAs($this->owner)->get(route('karyawan-virtual.admin.min-stok', 'manajer-admin'))->assertOk()->assertSee('AKR-3')->assertDontSee('BUKAN-BELI');

        $this->actingAs($this->owner)->post(route('karyawan-virtual.admin.min-stok.simpan', 'manajer-admin'), ['min' => [$akrilik->id => '12,5', $lain->id => '9']])->assertRedirect();
        $this->assertEquals(12.5, (float) $akrilik->fresh()->min_stock);
        $this->assertNull($lain->fresh()->min_stock, 'Hanya bahan beli yang boleh diubah dari halaman ini.');

        $this->actingAs($this->admin)->get(route('karyawan-virtual.admin.min-stok', 'manajer-admin'))->assertForbidden();

        $this->actingAs($this->owner)->get(route('purchasing.orders.create', ['product_id' => $akrilik->id]))
            ->assertOk()->assertSee('Kranindo (SUP-1)')->assertSee('"sku":"AKR-3"', false);
    }

    public function test_non_stok_cek_sesuai_pola_beli_masih_ada_lalu_perlu_beli(): void
    {
        $this->nyalakan('purchasing-nonstok');
        $lem = $this->bahan('LEM', null, 0, 'non_stock');
        foreach ([40, 26, 12] as $h) $this->po($lem, now()->subDays($h)->toDateString(), 1, 1); // tiap 14 hari → cek di hari ke-12
        $sekali = $this->bahan('KARDUS', null, 0, 'non_stock');
        $this->po($sekali, now()->subDays(60)->toDateString(), 1, 1);
        $meja = app(MejaKerjaService::class);

        $this->assertSame(1, $meja->sinkron()['dibuat'], 'Baru sekali dibeli → belum ada pola.');
        $t = Task::where('skill', 'purchasing-nonstok')->first();
        $this->assertStringStartsWith('Cek persediaan', $t->title);
        $this->assertSame([], $meja->skill('purchasing-nonstok')->aksi($t), 'Tahap cek: bukan tombol beli.');

        $this->actingAs($this->admin)->post(route('tasks.meja.mulai', $t->id))->assertStatus(422);
        $this->actingAs($this->admin)->post(route('tasks.meja.jawab', $t->id), ['jawaban' => 'ada-7'])->assertRedirect();
        $t->refresh();
        $this->assertSame('done', $t->status);
        $this->assertSame('masih_ada', $t->meta['jawaban']);

        $this->assertSame(0, $meja->sinkron()['dibuat'], 'Masih ada → belum dicek lagi.');
        $this->travel(7)->days();
        $this->assertSame(1, $meja->sinkron()['dibuat'], 'Tujuh hari kemudian dicek lagi.');
        $lagi = Task::where('skill', 'purchasing-nonstok')->where('status', 'open')->first();

        $this->actingAs($this->admin)->post(route('tasks.meja.jawab', $lagi->id), ['jawaban' => 'beli'])->assertRedirect();
        $lagi->refresh();
        $this->assertSame('open', $lagi->status, 'Tahap beli tetap di Antre sampai PO dibuat.');
        $this->assertStringStartsWith('Beli LEM', str_replace('Bahan ', '', $lagi->title));
        $this->assertTrue(collect($meja->skill('purchasing-nonstok')->aksi($lagi))->contains('label', 'Buat PO'));

        $this->travel(1)->minutes();
        $this->po($lem, now()->toDateString());
        $meja->sinkron();
        $this->assertSame('done', $lagi->fresh()->status);
        $this->assertSame(0, Task::where('skill', 'purchasing-nonstok')->whereIn('status', ['open', 'in_progress'])->count(), 'Baru dibeli → siklus mulai lagi.');
    }
}
