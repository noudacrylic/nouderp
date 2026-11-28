<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\ProductionSetting;
use App\Models\User;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\KomponenLaporan;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Komponen kurang/rusak (owner 28 Nov 2026): Pending Assembling "komponen" + penyebab + daftar
 * komponen (nama, jumlah, foto) → permintaan potong susulan CNC; selesai dengan sumber bahan.
 */
class KomponenLaporanTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrderStep $step;
    private User $admin;
    private Department $cnc;
    private Department $asm;
    private int $produk;
    private int $gudang;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        ProductionSetting::create(['testing_mode' => true, 'alasan_pending_wajib' => true]);

        $this->gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->produk = Product::create(['sku' => 'TB-1', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
        $this->cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->asm = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $this->step = $this->opBaru('OP-UJI-1');
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function opBaru(string $no): ProductionOrderStep
    {
        $ex = DepartmentExecutor::firstOrCreate(['department_id' => $this->asm->id, 'name' => 'Budi'], ['is_active' => true]);
        $order = ProductionOrder::create(['order_number' => $no, 'type' => 'ready_stock', 'warehouse_id' => $this->gudang,
            'planned_cycles' => 1, 'planned_qty' => 1, 'production_date' => now()->toDateString(), 'status' => 'confirmed']);
        ProductionOrderOutput::create(['production_order_id' => $order->id, 'product_id' => $this->produk,
            'qty_planned' => 1, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 100]);
        $step = ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 1,
            'department_id' => $this->asm->id, 'name' => 'Assembling', 'status' => 'pending']);
        app(ProductionOrderService::class)->startStep($step->id, [$ex->id]);

        return $step;
    }

    private function pause(ProductionOrderStep $step, array $data)
    {
        return $this->actingAs($this->admin)->from(route('production.process.index', ['department_id' => $this->asm->id]))
            ->post(route('production.process.steps.pause', $step->id), $data);
    }

    public function test_pending_komponen_mencatat_laporan_dan_foto(): void
    {
        $this->pause($this->step, [
            'alasan'   => 'komponen',
            'penyebab' => 'belum_dipotong',
            'komp'     => [3 => ['baris' => 3, 'nama' => 'Sekat', 'jumlah' => 2], 5 => ['baris' => 5, 'nama' => '  ', 'jumlah' => 1],
                           7 => ['baris' => 7, 'nama' => 'Tutup belakang', 'jumlah' => 1]],
            'komp_foto' => [7 => UploadedFile::fake()->image('tutup.jpg')],
        ])->assertSessionHas('success');

        $this->assertSame('paused', $this->step->fresh()->status);
        $log = ProductionStepTimeLog::where('production_order_step_id', $this->step->id)->where('event_type', 'paused')->sole();
        $this->assertSame(['komponen', 'Kurang dari CNC: Sekat ×2, Tutup belakang ×1'], [$log->alasan, $log->notes]);

        $lap = KomponenLaporan::with('items')->sole();
        $this->assertSame(['belum_dipotong', 'menunggu', $log->id], [$lap->penyebab, $lap->status, $lap->time_log_id]);
        $this->assertSame([['Sekat', 2, false], ['Tutup belakang', 1, true]],
            $lap->items->map(fn ($i) => [$i->nama, $i->jumlah, (bool) $i->foto_path])->all(), 'Foto ikut baris aslinya.');
        Storage::disk('public')->assertExists($lap->items[1]->foto_path);
    }

    public function test_komponen_tanpa_penyebab_atau_tanpa_isi_ditolak(): void
    {
        $this->pause($this->step, ['alasan' => 'komponen', 'komp' => [1 => ['baris' => 1, 'nama' => 'Sekat', 'jumlah' => 1]]])->assertSessionHasErrors('penyebab');
        $this->pause($this->step, ['alasan' => 'komponen', 'penyebab' => 'salah_potong', 'komp' => [1 => ['baris' => 1, 'nama' => '', 'jumlah' => 1]]])
            ->assertSessionHasErrors('komp');
        $this->assertSame('in_progress', $this->step->fresh()->status);
        $this->assertSame(0, KomponenLaporan::count());
    }

    public function test_bahan_beli_tidak_jadi_susulan(): void
    {
        $this->pause($this->step, ['alasan' => 'komponen', 'penyebab' => 'bahan_beli', 'komp' => [1 => ['baris' => 1, 'nama' => 'Magnet', 'jumlah' => 4]]]);
        $this->assertSame('tanpa_susulan', KomponenLaporan::sole()->status);
    }

    public function test_alur_susulan_cnc_sisa_dan_stok_serta_saran_nama(): void
    {
        $this->pause($this->step, ['alasan' => 'komponen', 'penyebab' => 'salah_potong', 'komp' => [1 => ['baris' => 1, 'nama' => 'Sekat', 'jumlah' => 2]]]);
        $lap = KomponenLaporan::sole();

        // Layar CNC: banner + daftar susulan.
        $this->actingAs($this->admin)->get(route('production.process.index', ['department_id' => $this->cnc->id]))
            ->assertOk()->assertSee('1 permintaan potong susulan dari Assembling');
        $this->actingAs($this->admin)->get(route('production.process.susulan', ['department_id' => $this->cnc->id]))
            ->assertOk()->assertSee('OP-UJI-1')->assertSee('Salah potong')->assertSee('×2');

        // Selesai dari bahan sisa → tanpa penambahan bahan; layar Assembling memberi tahu.
        $this->actingAs($this->admin)->post(route('production.process.susulan.selesai', ['id' => $lap->id, 'department_id' => $this->cnc->id]), ['sumber' => 'sisa'])
            ->assertRedirect(route('production.process.susulan', ['department_id' => $this->cnc->id]));
        $this->assertSame(['selesai', 'sisa'], [$lap->fresh()->status, $lap->fresh()->sumber_bahan]);
        $this->actingAs($this->admin)->get(route('production.process.index', ['department_id' => $this->asm->id]))
            ->assertOk()->assertSee('komponen susulan sudah dipotong CNC');

        // OP lain produk sama: saran nama "Sekat" muncul; selesai dari stok → form Penambahan Bahan terisi.
        $step2 = $this->opBaru('OP-UJI-2');
        $this->assertSame([$step2->production_order_id => ['Sekat']],
            app(\App\Modules\Production\Services\KomponenLaporanService::class)->saranUntukOrder([$step2->production_order_id]));
        $this->actingAs($this->admin)->get(route('production.process.index', ['department_id' => $this->asm->id]))
            ->assertOk()->assertSee('OP-UJI-2')->assertSee('Sekat');
        $this->pause($step2, ['alasan' => 'komponen', 'penyebab' => 'rusak_rakit', 'komp' => [1 => ['baris' => 1, 'nama' => 'Kaki', 'jumlah' => 1]]]);
        $lap2 = KomponenLaporan::where('production_order_step_id', $step2->id)->sole();
        $r = $this->actingAs($this->admin)->post(route('production.process.susulan.selesai', ['id' => $lap2->id]), ['sumber' => 'stok']);
        $r->assertRedirect(route('production.material-additions.create', ['department_id' => $this->asm->id, 'step_id' => $step2->id, 'komponen_laporan_id' => $lap2->id]));
        $this->actingAs($this->admin)->get($r->headers->get('Location'))
            ->assertOk()->assertSee('Potong susulan (Rusak saat dirakit): Kaki ×1')->assertSee('name="komponen_laporan_id"', false);
    }
}
