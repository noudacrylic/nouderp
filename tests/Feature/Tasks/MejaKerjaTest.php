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
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeChatService;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
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
 * pengerja ditentukan Manajer Admin (anggota meja), Mulai hanya untuk Desain, dan pending
 * dilanjutkan saat pelanggan membalas.
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
        $this->assertSame($this->desainer->id, $t->assignee_user_id, 'Ditugaskan ke anggota meja Desain.');
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

        $this->actingAs($this->desainer)->post(route('tasks.meja.mulai', $t->id))->assertRedirect();
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

        $this->assertSame($this->owner->id, $t->assignee_user_id);
        $this->actingAs($this->owner)->post(route('tasks.meja.mulai', $t->id))->assertStatus(422, 'Bukan skill ber-Mulai.');
        $this->actingAs($this->owner)->post(route('tasks.meja.selesai', $t->id))->assertStatus(422);

        $u->update(['status' => Usulan::DISETUJUI]);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_meja_peran_tanpa_anggota_tetap_terisi_lalu_ditugaskan_saat_meja_punya_anggota(): void
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

        $this->assertNull($desain->assignee_user_id);
        $this->assertSame($this->owner->id, Task::where('skill', 'persetujuan-usulan')->value('assignee_user_id'));

        // Meja Desain diberi anggota → skill Atur penugasan menugaskan task yang menunggu.
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->desainer->id, 'urutan' => 1]);
        app(MejaKerjaService::class)->aturPenugasan();
        $this->assertSame($this->desainer->id, $desain->fresh()->assignee_user_id);
    }

    public function test_task_dibagi_ke_anggota_meja_yang_bebannya_paling_ringan(): void
    {
        $kedua = User::factory()->create(['role' => 'user', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $kedua->id, 'urutan' => 2]);
        $meja = app(MejaKerjaService::class);

        $this->assertSame($this->desainer->id, $meja->pilihPengerja('desain'), 'Seri → urutan anggota.');
        Task::create(['title' => 'Lama', 'status' => 'open', 'priority' => 'normal', 'peran' => 'desain', 'assignee_user_id' => $this->desainer->id, 'creator_user_id' => $this->owner->id]);
        $this->assertSame($kedua->id, $meja->pilihPengerja('desain'));
    }

    public function test_desain_shopee_chat_shopee_lewat_id_pembeli_dan_chat_wa_bila_tertaut(): void
    {
        $this->nyalakan('desain', 'desain');
        $so = $this->soKs();
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'desain')->first();
        $aksi = fn () => collect($meja->skill('desain')->aksi($t));

        // Pembeli belum pernah chat → belum ada di CRM, cadangannya Seller Centre.
        $this->assertSame('ABC123', $aksi()->firstWhere('label', 'Chat di Seller Centre')['salin']);

        $sp = CrmConversation::findOrCreateFor('777', CrmConversation::KANAL_SHOPEE, '55');
        DB::table('shopee_orders')->insert(['env' => 'live', 'shop_id' => 55, 'order_sn' => 'ABC123', 'buyer_user_id' => 777, 'status' => 'READY_TO_SHIP', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shopee_chats')->insert(['env' => 'live', 'shop_id' => 55, 'conversation_id' => 1, 'to_id' => 777, 'crm_conversation_id' => $sp->id, 'created_at' => now(), 'updated_at' => now()]);

        $chat = $aksi()->firstWhere('label', 'Chat Shopee');
        $this->assertSame(route('crm.inbox.show', $sp->id), $chat['url']);
        $this->assertSame('oren', $chat['warna']);
        $this->assertNull($aksi()->firstWhere('label', 'Chat di Seller Centre'));
        $this->assertNull($aksi()->firstWhere('label', 'Chat WA'));

        // Desain dikirim lewat WA: pesanan Shopee yang tertaut chat WA dapat tombol hijau juga.
        $wa = CrmConversation::findOrCreateFor('628111222333');
        DB::table('crm_pesanan_chat')->insert(['conversation_id' => $wa->id, 'sales_order_id' => $so->id, 'created_at' => now(), 'updated_at' => now()]);
        $tombolWa = $aksi()->firstWhere('label', 'Chat WA');
        $this->assertSame(route('crm.inbox.show', $wa->id), $tombolWa['url']);
        $this->assertSame('hijau', $tombolWa['warna']);
        $this->assertNotNull($aksi()->firstWhere('label', 'Chat Shopee'), 'Chat Shopee tetap ada lewat ID pembeli.');
        $this->assertSame('Chat Shopee', $aksi()->firstWhere('utama')['label']);
    }

    public function test_mulai_chat_shopee_menyapa_pembeli_duluan_lalu_membuka_chat_crm(): void
    {
        $this->nyalakan('desain', 'desain');
        $so = $this->soKs();
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'desain')->first();
        ShopeeShop::create(['shop_id' => 55, 'env' => 'live', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now()]);
        DB::table('shopee_orders')->insert(['env' => 'live', 'shop_id' => 55, 'order_sn' => 'ABC123', 'buyer_user_id' => 777, 'status' => 'READY_TO_SHIP', 'created_at' => now(), 'updated_at' => now()]);

        $aksi = collect($meja->skill('desain')->aksi($t));
        $mulai = $aksi->firstWhere('label', 'Mulai Chat Shopee');
        $this->assertSame(route('tasks.meja.chat-shopee', $so->id), $mulai['kirim']);
        $this->assertSame('Mulai Chat Shopee', $aksi->firstWhere('utama')['label']);
        $this->assertSame('ABC123', $aksi->firstWhere('label', 'Seller Centre')['salin'], 'Seller Centre tinggal cadangan.');

        // Shopee: kartu pesanan lalu teks; sinkron chat menyalin percakapan barunya ke CRM.
        $terkirim = [];
        $client = \Mockery::mock(ShopeeClient::class);
        $client->shouldReceive('env')->andReturn('live');
        $client->shouldReceive('panggil')->twice()->andReturnUsing(function ($shop, $m, $path, $p) use (&$terkirim) {
            $terkirim[] = $p;
            return ['message_id' => (string) (900 + count($terkirim)), 'conversation_id' => '1'];
        });
        $sp = CrmConversation::findOrCreateFor('777', CrmConversation::KANAL_SHOPEE, '55');
        $sinkron = \Mockery::mock(ShopeeChatService::class);
        $sinkron->shouldReceive('tarikBaru')->once()->andReturnUsing(function () use ($sp) {
            DB::table('shopee_chats')->insert(['env' => 'live', 'shop_id' => 55, 'conversation_id' => 1, 'to_id' => 777, 'crm_conversation_id' => $sp->id, 'created_at' => now(), 'updated_at' => now()]);
            return [];
        });
        $this->app->instance(ShopeeClient::class, $client);
        $this->app->instance(ShopeeChatService::class, $sinkron);

        $this->actingAs($this->desainer)->post(route('tasks.meja.chat-shopee', $so->id), ['teks' => 'Halo kak, mau desain apa?'])
            ->assertRedirect(route('crm.inbox.show', $sp->id));
        $this->assertSame([['to_id' => 777, 'message_type' => 'order', 'content' => ['order_sn' => 'ABC123']],
                           ['to_id' => 777, 'message_type' => 'text', 'content' => ['text' => 'Halo kak, mau desain apa?']]], $terkirim);

        // Sudah ada chat → tidak menyapa dobel, langsung dibuka.
        $this->actingAs($this->desainer)->post(route('tasks.meja.chat-shopee', $so->id), ['teks' => 'Halo lagi'])
            ->assertRedirect(route('crm.inbox.show', $sp->id));
        $this->assertCount(2, $terkirim);
        $this->assertSame('Chat Shopee', collect($meja->skill('desain')->aksi($t))->firstWhere('utama')['label']);

        $orangLain = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->actingAs($orangLain)->post(route('tasks.meja.chat-shopee', $so->id), ['teks' => 'x'])->assertForbidden();
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
        // Riwayat task Meja Kerja ada di Daftar (filter Sumber), tidak di Daftar bawaan.
        $judul = Task::where('skill', 'desain')->value('title');
        $this->actingAs($this->owner)->get(route('tasks.list', ['sumber' => 'meja']))->assertOk()->assertSee($judul)->assertSee('Desain pesanan');
        $this->actingAs($this->owner)->get(route('tasks.list'))->assertOk()->assertDontSee($judul);
        $this->actingAs($this->desainer)->get(route('tasks.meja.index'))->assertSee('▶ Mulai')->assertDontSee('Ambil berikutnya');

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

    public function test_daftar_produk_desain_diatur_admin(): void
    {
        $this->nyalakan('desain', 'desain');
        $url = route('karyawan-virtual.admin.produk-desain', 'manajer-admin');
        $tambah = route('karyawan-virtual.admin.produk-desain.tambah', 'manajer-admin');
        Product::create(['sku' => 'GK-A5', 'name' => 'Gantungan Kunci', 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true]);

        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('KS-*');
        $this->actingAs($this->owner)->post($tambah, ['pola' => 'gk-*'])->assertSessionHas('success');
        $this->actingAs($this->owner)->post($tambah, ['pola' => 'XX-TYPO'])->assertSessionHas('error');
        $this->actingAs($this->owner)->delete(route('karyawan-virtual.admin.produk-desain.hapus', 'manajer-admin'), ['pola' => 'KS-%'])->assertSessionHas('success');
        $this->assertSame(['BC-%', 'NM-%TXT', 'FF-%Foto%', 'GK-%'], app(MejaKerjaService::class)->skill('desain')->polaSku());

        $this->soKs();
        $this->assertSame(0, app(MejaKerjaService::class)->sinkron()['dibuat'], 'KS-* dihapus dari daftar.');
        $this->actingAs($this->desainer)->get($url)->assertForbidden();
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
