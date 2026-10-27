<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan as Usulan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Meja Kerja Admin: Skill Admin membuat task sendiri, task selesai/batal mengikuti sumbernya,
 * pending kembali Dikerjakan saat pelanggan membalas, dan cadangan menggantikan yang tak hadir.
 */
class MejaKerjaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $desainer;
    private int $gudang;
    private int $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner    = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->desainer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->gudang   = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->pelanggan = Customer::create(['code' => 'C1', 'name' => 'Puskesmas', 'is_active' => true])->id;

        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->desainer->id, 'urutan' => 1]);
    }

    private function nyalakan(string $skill, string $peran): void
    {
        MejaKerjaSkill::updateOrCreate(['skill' => $skill], ['aktif' => true, 'peran' => $peran, 'bobot' => 8, 'sla_menit' => 120, 'aktif_sejak' => now()->subDay()]);
    }

    private function soKs(): SalesOrder
    {
        $p = Product::create(['sku' => 'KS-K3-M4', 'name' => 'Kotak Saran', 'sale_type' => 'bundle', 'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true]);
        $so = SalesOrder::create(['order_number' => 'SO-1', 'customer_po_number' => 'SP-ABC123', 'customer_id' => $this->pelanggan, 'warehouse_id' => $this->gudang,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0, 'notes' => 'Logo terlampir']);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'qty' => 1, 'unit_price' => 100000, 'net_unit_price' => 100000,
            'line_subtotal' => 100000, 'line_discount' => 0, 'line_total' => 100000]);
        return $so;
    }

    public function test_desain_dibuat_sekali_lalu_batal_saat_so_void(): void
    {
        $this->nyalakan('desain', 'desain');
        $so = $this->soKs();
        $meja = app(MejaKerjaService::class);

        $this->assertSame(1, $meja->sinkron()['dibuat']);
        $this->assertSame(0, $meja->sinkron()['dibuat'], 'Satu pesanan tidak membuat task kembar.');

        $t = Task::where('skill', 'desain')->first();
        $this->assertSame($this->desainer->id, $t->assignee_user_id);
        $this->assertStringContainsString('KS-K3-M4', $t->title);
        $this->assertStringContainsString('Logo terlampir', $t->description);
        $this->assertSame('ABC123', collect($meja->skill('desain')->aksi($t))->firstWhere('salin')['salin']);

        $so->update(['status' => 'void']);
        $meja->sinkron();
        $this->assertSame('cancelled', $t->fresh()->status);
    }

    public function test_skill_mati_atau_pesanan_lama_tidak_membuat_task(): void
    {
        $this->soKs();
        $this->assertSame(0, app(MejaKerjaService::class)->sinkron()['dibuat'], 'Skill bawaan nonaktif.');

        MejaKerjaSkill::updateOrCreate(['skill' => 'desain'], ['aktif' => true, 'peran' => 'desain', 'bobot' => 8, 'sla_menit' => 120, 'aktif_sejak' => now()->addMinute()]);
        $this->assertSame(0, app(MejaKerjaService::class)->sinkron()['dibuat'], 'Pesanan sebelum skill dinyalakan tidak masuk.');
    }

    public function test_alur_mulai_pending_dibalas_pelanggan_lalu_selesai(): void
    {
        $this->nyalakan('desain', 'desain');
        $so = $this->soKs();
        $chat = CrmConversation::findOrCreateFor('628111222333');
        DB::table('crm_pesanan_chat')->insert(['conversation_id' => $chat->id, 'sales_order_id' => $so->id, 'created_at' => now(), 'updated_at' => now()]);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'desain')->first();

        $this->actingAs($this->desainer)->post(route('tasks.meja.ambil'))->assertRedirect();
        $this->assertSame('in_progress', $t->fresh()->status);
        $this->assertNotNull($t->fresh()->mulai_at);

        $this->actingAs($this->desainer)->post(route('tasks.meja.pending', $t->id), ['alasan' => 'Menunggu balasan pelanggan'])->assertRedirect();
        $this->assertSame('pending', $t->fresh()->status);

        $this->travel(10)->minutes();
        $chat->forceFill(['last_inbound_at' => now()])->save();
        $this->assertSame(1, $meja->sinkron()['lanjut']);
        $t->refresh();
        $this->assertSame('in_progress', $t->status, 'Dibalas → kembali Dikerjakan, bukan Antre.');
        $this->assertGreaterThanOrEqual(590, $t->pending_detik);

        $this->actingAs($this->desainer)->post(route('tasks.meja.selesai', $t->id))->assertRedirect();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_persetujuan_usulan_ke_owner_dan_selesai_sendiri(): void
    {
        $this->nyalakan('persetujuan-usulan', 'owner');
        $u = Usulan::create(['karyawan' => 'bnm', 'skill' => 'produksi.isi-antrean-cnc', 'kunci' => 'k1', 'judul' => 'Buat OP RP-3x5', 'data' => [], 'status' => Usulan::MENUNGGU]);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'persetujuan-usulan')->first();
        $this->assertSame($this->owner->id, $t->assignee_user_id);

        $this->actingAs($this->owner)->post(route('tasks.meja.mulai', $t->id));
        $this->actingAs($this->owner)->post(route('tasks.meja.selesai', $t->id))->assertStatus(422);

        $u->update(['status' => Usulan::DISETUJUI]);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_antre_dialihkan_ke_cadangan_bila_utama_belum_absen(): void
    {
        $karyawan = DB::table('sdm_karyawan')->insertGetId(['staf_code' => 'K-9', 'name' => 'Admin Desain', 'created_at' => now(), 'updated_at' => now()]);
        $this->desainer->update(['karyawan_id' => $karyawan]);
        $cadangan = User::factory()->create(['role' => 'user', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $cadangan->id, 'urutan' => 2]);

        $this->nyalakan('desain', 'desain');
        $this->soKs();
        app(MejaKerjaService::class)->sinkron();

        $this->assertSame($cadangan->id, Task::where('skill', 'desain')->value('assignee_user_id'));
    }

    public function test_qc_tinggal_langkah_qc_lalu_selesai_saat_qc_tersimpan(): void
    {
        $this->nyalakan('qc', 'layanan');
        MejaKerjaPeran::create(['peran' => 'layanan', 'user_id' => $this->owner->id, 'urutan' => 1]);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $op = DB::table('production_orders')->insertGetId(['order_number' => 'OP-QC-1', 'type' => 'ready_stock', 'warehouse_id' => $this->gudang,
            'status' => 'in_progress', 'production_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('production_order_steps')->insert(['production_order_id' => $op, 'step_number' => 1, 'department_id' => 1, 'name' => 'CNC', 'status' => 'completed', 'is_qc' => false, 'created_at' => now(), 'updated_at' => now()]);
        $qc = DB::table('production_order_steps')->insertGetId(['production_order_id' => $op, 'step_number' => 2, 'department_id' => 1, 'name' => 'QC', 'status' => 'pending', 'is_qc' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'qc')->first();
        $this->assertNotNull($t);
        $this->assertStringContainsString('op=' . $op, collect($meja->skill('qc')->aksi($t))->firstWhere('utama')['url']);

        DB::table('production_order_steps')->where('id', $qc)->update(['status' => 'completed']);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_halaman_meja_dan_pengaturan(): void
    {
        $this->nyalakan('desain', 'desain');
        $this->soKs();
        app(MejaKerjaService::class)->sinkron();

        $this->actingAs($this->desainer)->get(route('tasks.meja.index'))->assertOk()->assertSee('Meja Kerja')->assertSee('Chat di Seller Centre');
        $this->actingAs($this->owner)->get(route('tasks.meja.pengaturan'))->assertOk()->assertSee('Skill Admin');
        $this->actingAs($this->desainer)->get(route('tasks.meja.pengaturan'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('tasks.index'))->assertOk()->assertDontSee('Desain SO-1');
    }
}
