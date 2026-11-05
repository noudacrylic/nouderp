<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Desain gambar oleh Admin Desain": SO dicentang owner → kartu di meja Desain setelah dibayar,
 * selesai saat SEMUA barang preorder masuk OP, tidak dobel dengan kartu Desain biasa.
 */
class DesainGambarTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $desainer;
    private int $gudang;
    private int $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner     = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->desainer  = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->gudang    = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->pelanggan = Customer::create(['code' => 'C1', 'name' => 'Hanif', 'is_active' => true])->id;
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->desainer->id, 'urutan' => 1]);

        foreach (['desain-gambar', 'desain'] as $k) {
            MejaKerjaSkill::updateOrCreate(['skill' => $k], ['peran' => 'desain', 'bobot' => 8, 'sla_menit' => 480]);
            KaryawanVirtualSkill::updateOrCreate(['skill' => "admin.$k"], ['mode' => 'otomatis', 'mode_diubah_at' => now()->subDay()]);
        }
    }

    private function produk(string $sku, string $saleType = 'preorder'): Product
    {
        return Product::create(['sku' => $sku, 'name' => "Produk $sku", 'sale_type' => $saleType, 'base_unit' => 'pcs',
            'base_price' => 10000, 'is_active' => true, 'is_sellable' => true]);
    }

    /** @param array<int, array{0: Product, 1: int}> $baris */
    private function so(array $baris, array $atribut = []): SalesOrder
    {
        static $n = 0;
        $n++;
        $so = SalesOrder::create($atribut + ['order_number' => "SO-DG-$n", 'customer_id' => $this->pelanggan, 'warehouse_id' => $this->gudang,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 500000,
            'desain_gambar' => true, 'desain_brief' => 'Akrilik 2mm bening, logo PT dari chat']);
        foreach ($baris as [$p, $qty]) {
            SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'description' => "Akrilik Sign {$p->sku}",
                'qty' => $qty, 'unit_price' => 10000, 'net_unit_price' => 10000, 'line_subtotal' => 10000 * $qty, 'line_discount' => 0, 'line_total' => 10000 * $qty]);
        }
        return $so;
    }

    private function op(SalesOrder $so, Product $p, int $qty, string $status = 'confirmed'): ProductionOrder
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create(['order_number' => "OP-DG-$n", 'type' => 'custom', 'sales_order_id' => $so->id, 'warehouse_id' => $this->gudang,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => $status, 'score_type' => 'auto', 'created_via' => 'manual']);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $p->id, 'qty_planned' => $qty, 'output_type' => 'main', 'percentage' => 100]);
        return $op;
    }

    public function test_kartu_muncul_setelah_dibayar_lalu_selesai_saat_semua_barang_masuk_op(): void
    {
        $kecil = $this->produk('CS6');
        $besar = $this->produk('CS4');
        $so = $this->so([[$kecil, 100], [$besar, 3]]);
        $meja = app(MejaKerjaService::class);

        $meja->sinkron();
        $this->assertSame(0, Task::where('skill', 'desain-gambar')->count(), 'Belum dibayar → belum ada kartu.');

        $so->update(['paid_amount' => 250000]);
        $meja->sinkron();
        $t = Task::where('skill', 'desain-gambar')->firstOrFail();
        $this->assertSame('desain', $t->peran);
        $this->assertSame($this->desainer->id, $t->assignee_user_id);
        $this->assertStringContainsString('logo PT dari chat', (string) $t->description, 'Brief owner ikut di kartu.');
        $this->assertStringContainsString('Akrilik Sign CS6', (string) $t->description);
        $this->assertStringContainsString('type=custom', collect($meja->skill('desain-gambar')->aksi($t))->firstWhere('label', 'Buat OP')['url']);

        $meja->sinkron();
        $this->assertSame(1, Task::where('skill', 'desain-gambar')->count(), 'Tidak dobel.');

        $this->op($so, $kecil, 100);
        $meja->sinkron();
        $this->assertSame('open', $t->fresh()->status, 'SO campuran: OP pertama belum menutup kartu.');

        $this->op($so, $besar, 3, 'cancelled');
        $meja->sinkron();
        $this->assertSame('open', $t->fresh()->status, 'OP batal tidak dihitung.');

        $this->op($so, $besar, 3);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_so_tempo_langsung_masuk_dan_tidak_dobel_dengan_kartu_desain_biasa(): void
    {
        $ks = $this->produk('KS-K1-M3', 'bundle');
        $this->so([[$ks, 1]], ['is_tempo' => true]);
        app(MejaKerjaService::class)->sinkron();

        $this->assertSame(1, Task::where('skill', 'desain-gambar')->count(), 'Tempo = boleh jalan tanpa bayar.');
        $this->assertSame(0, Task::where('skill', 'desain')->count(), 'SO yang diserahkan tidak membuat kartu Desain biasa.');
    }

    public function test_centang_dilepas_atau_so_void_kartu_batal(): void
    {
        $so = $this->so([[$this->produk('CS2'), 24]], ['paid_amount' => 100000]);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'desain-gambar')->firstOrFail();

        $this->actingAs($this->owner)->post(route('sales.orders.desain-gambar', $so->id), ['desain_gambar' => 0])->assertRedirect();
        $this->assertFalse($so->fresh()->desain_gambar);
        $this->assertNull($so->fresh()->desain_brief, 'Brief ikut dibuang saat centang dilepas.');

        $meja->sinkron();
        $this->assertSame('cancelled', $t->fresh()->status);
    }

    public function test_dicentang_dari_halaman_so_menyimpan_brief(): void
    {
        $so = $this->so([[$this->produk('CS3'), 11]], ['desain_gambar' => false, 'desain_brief' => null]);

        $this->actingAs($this->owner)->post(route('sales.orders.desain-gambar', $so->id), [
            'desain_gambar' => 1, 'desain_brief' => '  Nama ruangan 10x30, huruf putih  ',
        ])->assertRedirect();

        $so->refresh();
        $this->assertTrue($so->desain_gambar);
        $this->assertSame('Nama ruangan 10x30, huruf putih', $so->desain_brief);
    }
}
