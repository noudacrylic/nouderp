<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
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
 * pending kembali Dikerjakan saat pelanggan membalas, dan task menumpuk di meja peran sampai diambil.
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

    private function nyalakan(string $skill, string $peran, string $mode = 'otomatis', $sejak = null): void
    {
        MejaKerjaSkill::updateOrCreate(['skill' => $skill], ['peran' => $peran, 'bobot' => 8, 'sla_menit' => 120]);
        KaryawanVirtualSkill::updateOrCreate(['skill' => "admin.$skill"], ['mode' => $mode, 'mode_diubah_at' => $sejak ?? now()->subDay()]);
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
        $this->assertSame('desain', $t->peran);
        $this->assertNull($t->assignee_user_id, 'Masuk meja Desain, belum dipegang.');
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

        $this->nyalakan('desain', 'desain', 'otomatis', now()->addMinute());
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
        $this->assertSame('owner', $t->peran);

        $this->actingAs($this->owner)->post(route('tasks.meja.mulai', $t->id));
        $this->actingAs($this->owner)->post(route('tasks.meja.selesai', $t->id))->assertStatus(422);

        $u->update(['status' => Usulan::DISETUJUI]);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_meja_peran_tanpa_anggota_tetap_terisi_dan_diambil_dari_tab_yang_dibuka(): void
    {
        MejaKerjaPeran::where('peran', 'desain')->delete();
        $this->nyalakan('desain', 'desain');
        $this->nyalakan('persetujuan-usulan', 'owner');
        $this->soKs();
        Usulan::create(['karyawan' => 'bnm', 'skill' => 'produksi.isi-antrean-cnc', 'kunci' => 'k1', 'judul' => 'Buat OP RP-3x5', 'data' => [], 'status' => Usulan::MENUNGGU]);
        app(MejaKerjaService::class)->sinkron();
        $desain = Task::where('skill', 'desain')->first();

        $this->assertSame('desain', $desain->peran, 'Meja Desain belum punya anggota — task tetap masuk.');
        $this->actingAs($this->owner)->get(route('tasks.meja.index', ['lihat' => 'desain']))->assertOk()->assertSee($desain->title);
        $this->actingAs($this->desainer)->get(route('tasks.meja.index', ['lihat' => 'desain']))->assertOk()->assertDontSee($desain->title, false);

        // Owner membuka tab Desain → yang diambil task Desain, bukan task meja Owner miliknya.
        $this->actingAs($this->owner)->post(route('tasks.meja.ambil'), ['lihat' => 'desain'])->assertRedirect();
        $this->assertSame('in_progress', $desain->fresh()->status);
        $this->assertSame($this->owner->id, $desain->fresh()->assignee_user_id);
        $this->assertSame('open', Task::where('skill', 'persetujuan-usulan')->value('status'));
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
        $this->actingAs($this->owner)->get(route('tasks.meja.pengaturan'))->assertRedirect(route('karyawan-virtual.admin.pengaturan', 'manajer-admin'));
        $this->actingAs($this->owner)->get(route('karyawan-virtual.admin.pengaturan', 'manajer-admin'))->assertOk()->assertSee('Desain pesanan')->assertSee('Otomatis');
        $this->actingAs($this->desainer)->get(route('karyawan-virtual.admin.pengaturan', 'manajer-admin'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('karyawan-virtual.show', 'manajer-admin'))->assertOk()->assertSee('Purchasing ready stok')->assertSee('Peran & Pengaturan', false);
        $this->actingAs($this->owner)->get(route('tasks.index'))->assertOk()->assertDontSee('Desain SO-1');

        $this->actingAs($this->owner)->put(route('karyawan-virtual.admin.pengaturan.simpan', 'manajer-admin'), [
            'peran' => ['desain' => [$this->owner->id, $this->desainer->id]],
            'skill' => ['desain' => ['peran' => 'desain', 'bobot' => 9, 'sla_menit' => 60]],
        ])->assertRedirect();
        $this->assertSame([$this->owner->id, $this->desainer->id], MejaKerjaPeran::where('peran', 'desain')->orderBy('urutan')->pluck('user_id')->all());
        $this->assertSame(9, MejaKerjaSkill::where('skill', 'desain')->value('bobot'));
    }

    public function test_calon_anggota_meja_hanya_admin_dan_bermenu_meja_kerja(): void
    {
        $cnc = User::factory()->create(['name' => 'Akun CNC Zz', 'role' => 'user', 'is_active' => true]);
        $admin = User::factory()->create(['name' => 'Admin Baru Zz', 'role' => 'user', 'is_active' => true]);
        $admin->menuPermissions()->create(['menu_key' => 'tasks.meja']);

        $this->actingAs($this->owner)->get(route('karyawan-virtual.admin.pengaturan', 'manajer-admin'))->assertOk()
            ->assertSee('Admin Baru Zz')->assertSee($this->desainer->name)->assertDontSee('Akun CNC Zz');
    }

    public function test_meja_peran_diatur_dari_form_user(): void
    {
        $ubah = fn (array $isi) => $this->actingAs($this->owner)->put(route('settings.users.update', $this->desainer->id), [
            'username' => 'desainer1', 'email' => '', 'karyawan_id' => '', 'name' => $this->desainer->name, 'role' => 'user', 'is_active' => 1,
        ] + $isi)->assertSessionHasNoErrors()->assertRedirect();

        $ubah(['permissions' => ['tasks.meja'], 'meja_peran' => ['layanan', 'desain']]);
        $this->assertEqualsCanonicalizing(['layanan', 'desain'], MejaKerjaPeran::where('user_id', $this->desainer->id)->pluck('peran')->all());

        $ubah(['permissions' => ['tasks.meja'], 'meja_peran' => ['layanan']]);
        $this->assertSame(['layanan'], MejaKerjaPeran::where('user_id', $this->desainer->id)->pluck('peran')->all());

        $ubah(['permissions' => ['tasks.board'], 'meja_peran' => ['layanan']]);
        $this->assertSame(0, MejaKerjaPeran::where('user_id', $this->desainer->id)->count(), 'Tanpa menu Meja Kerja = bukan anggota meja.');
    }

    public function test_dibuat_atas_nama_manajer_admin_dan_tercatat_di_jurnal(): void
    {
        $this->nyalakan('desain', 'desain');
        $this->soKs();
        app(MejaKerjaService::class)->sinkron();

        $t = Task::where('skill', 'desain')->first();
        $this->assertSame('Manajer Admin', User::find($t->creator_user_id)->name);
        $j = KaryawanVirtualJurnal::where('skill', 'admin.desain')->first();
        $this->assertSame('manajer-admin', $j->karyawan);
        $this->assertSame(1, $j->jumlah_tindakan);
    }

    public function test_bayangan_tidak_membuat_task_dan_tidak_mencatat_ulang(): void
    {
        $this->nyalakan('desain', 'desain', 'bayangan');
        $this->soKs();
        $meja = app(MejaKerjaService::class);

        $this->assertSame(0, $meja->sinkron()['dibuat']);
        $this->assertSame(0, Task::where('skill', 'desain')->count());
        $this->assertSame(1, KaryawanVirtualJurnal::where('skill', 'admin.desain')->count(), 'Bayangan mencatat yang AKAN dibuat.');

        $meja->sinkron();
        $this->assertSame(1, KaryawanVirtualJurnal::where('skill', 'admin.desain')->count(), 'Jalan berikutnya tidak menulis jurnal yang sama.');
    }
}
