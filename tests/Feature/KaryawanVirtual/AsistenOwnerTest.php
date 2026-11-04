<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Models\TelegramSetting;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan as Usulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Skills\Owner\PencatatanTelegram;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Services\IzinKeputusanService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asisten Owner: mengisi Meja Owner (usulan, izin), keputusan izin hanya di tangan owner dari
 * ketiga pintunya (kartu, Telegram, halaman SDM), dan pencatatan Telegram mengikuti mode skill.
 */
class AsistenOwnerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private int $karyawanId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => 'TOKEN-UJI']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'telegram_chat_id' => '5001']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'telegram_chat_id' => '5002']);
        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);

        $this->karyawanId = DB::table('sdm_karyawan')->insertGetId([
            'staf_code' => 'K-001', 'name' => 'Andi', 'hak_cuti' => 12, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(0, 6) as $dow) {
            DB::table('sdm_karyawan_schedule')->insert([
                'karyawan_id' => $this->karyawanId, 'day_of_week' => $dow, 'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00',
                'is_off' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function nyalakan(string $skill, string $mode = 'otomatis'): void
    {
        KaryawanVirtualSkill::updateOrCreate(['skill' => "owner.$skill"], ['mode' => $mode, 'mode_diubah_at' => now()->subDay()]);
    }

    private function izin(): IzinRequest
    {
        return IzinRequest::create(['karyawan_id' => $this->karyawanId, 'type' => 'sakit', 'tanggal' => '2026-11-10', 'alasan' => 'demam', 'status' => 'pending']);
    }

    private function klik(string $data, string $fromId)
    {
        TelegramSetting::singleton()->forceFill(['webhook_secret' => 'rahasia-uji'])->save();

        return $this->postJson(route('telegram.webhook', 'rahasia-uji'), ['callback_query' => [
            'id' => 'cb-1', 'data' => $data, 'from' => ['id' => (int) $fromId],
            'message' => ['message_id' => 77, 'chat' => ['id' => (int) $fromId]],
        ]])->assertOk();
    }

    public function test_skill_dimiliki_asisten_owner_bukan_manajer_admin(): void
    {
        $kv = app(KaryawanVirtualService::class);

        $this->assertSame('asisten-owner', $kv->pemilik('owner.persetujuan-usulan'));
        $this->assertSame('asisten-owner', $kv->pemilik('owner.pengajuan-izin'));
        $this->assertSame('asisten-owner', $kv->pemilik(PencatatanTelegram::KEY));
        $this->assertArrayNotHasKey('admin.persetujuan-usulan', $kv->karyawan('manajer-admin')['skills']);

        // Skill baru mulai di Bayangan; pencatatan yang sudah dipakai tetap jalan.
        $this->assertSame('bayangan', $kv->mode('owner.pengajuan-izin'));
        $this->assertSame('otomatis', $kv->mode(PencatatanTelegram::KEY));
    }

    public function test_usulan_masuk_meja_owner_atas_nama_asisten_owner(): void
    {
        $this->nyalakan('persetujuan-usulan');
        Usulan::create(['karyawan' => 'manager-produksi', 'skill' => 'produksi.isi-antrean-cnc', 'kunci' => 'k1', 'judul' => 'Buat OP', 'data' => [], 'status' => Usulan::MENUNGGU]);

        app(MejaKerjaService::class)->sinkron();

        $t = Task::where('skill', 'persetujuan-usulan')->sole();
        $this->assertSame('owner', $t->peran);
        $this->assertSame($this->owner->id, $t->assignee_user_id);
        $this->assertSame(app(KaryawanVirtualService::class)->akun('asisten-owner')->id, $t->creator_user_id);
    }

    public function test_izin_bayangan_hanya_pratinjau_lalu_otomatis_jadi_kartu(): void
    {
        $this->izin();
        $meja = app(MejaKerjaService::class);

        $meja->sinkron();
        $this->assertSame(0, Task::where('skill', 'pengajuan-izin')->count());
        $this->actingAs($this->owner)->get(route('tasks.meja.index'))->assertOk()->assertSee('BAYANGAN')->assertSee('Izin karyawan - Andi');

        // Pengajuan yang lebih dulu masuk tetap ikut saat skill dinyalakan.
        $this->nyalakan('pengajuan-izin');
        $meja->sinkron();
        $t = Task::where('skill', 'pengajuan-izin')->sole();
        $this->assertSame('owner', $t->peran);
        $this->actingAs($this->owner)->get(route('tasks.meja.index'))->assertSee('Izin karyawan - Andi')->assertSee('✅ Setujui');
    }

    public function test_setujui_dari_kartu_menerapkan_izin_dan_menutup_kartu(): void
    {
        $this->nyalakan('pengajuan-izin');
        $izin = $this->izin();
        app(MejaKerjaService::class)->sinkron();
        $t = Task::where('skill', 'pengajuan-izin')->sole();

        $this->actingAs($this->owner)->post(route('tasks.meja.jawab', $t->id), ['jawaban' => 'setujui'])->assertSessionHas('success');

        $this->assertSame('approved', $izin->fresh()->status);
        $this->assertSame($this->owner->name, $izin->fresh()->reviewed_by);
        $this->assertSame('done', $t->fresh()->status);
    }

    public function test_keputusan_dari_halaman_sdm_ikut_menutup_kartu(): void
    {
        $this->nyalakan('pengajuan-izin');
        $izin = $this->izin();
        app(MejaKerjaService::class)->sinkron();

        $this->actingAs($this->owner)->post(route('sdm.pengajuan-izin.reject', $izin->id), ['review_notes' => 'salah tanggal'])->assertRedirect();

        $this->assertSame('rejected', $izin->fresh()->status);
        $this->assertSame('done', Task::where('skill', 'pengajuan-izin')->value('status'));
    }

    public function test_admin_tidak_bisa_membuka_maupun_memutus_izin(): void
    {
        $izin = $this->izin();

        $this->actingAs($this->admin)->get(route('sdm.pengajuan-izin.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('sdm.pengajuan-izin.show', $izin->id))->assertForbidden();
        $this->actingAs($this->admin)->post(route('sdm.pengajuan-izin.approve', $izin->id))->assertForbidden();

        $this->expectException(\RuntimeException::class);
        try {
            app(IzinKeputusanService::class)->putuskan($izin, true, $this->admin);
        } finally {
            $this->assertSame('pending', $izin->fresh()->status);
        }
    }

    public function test_telegram_izin_hanya_ke_owner_dengan_tombol_saat_otomatis(): void
    {
        $izin = $this->izin();

        app(IzinKeputusanService::class)->kirimTelegram($izin);
        $kirim = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage'));
        $this->assertCount(1, $kirim);
        $this->assertSame('5001', (string) $kirim[0][0]['chat_id']);
        $this->assertArrayNotHasKey('reply_markup', $kirim[0][0]->data(), 'Bayangan: tanpa tombol.');

        $this->nyalakan('pengajuan-izin');
        app(IzinKeputusanService::class)->kirimTelegram($izin);
        $kirim = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage'));
        $this->assertStringContainsString("iz:ok:{$izin->id}", $kirim[1][0]['reply_markup']);
        $this->assertSame([['5001', 77]], $izin->fresh()->telegram);
    }

    public function test_tombol_telegram_owner_memutus_dan_admin_ditolak(): void
    {
        $this->nyalakan('pengajuan-izin');
        $izin = $this->izin();
        app(IzinKeputusanService::class)->kirimTelegram($izin);

        $this->klik("iz:ok:{$izin->id}", '5002');
        $this->assertSame('pending', $izin->fresh()->status);

        $this->klik("iz:ok:{$izin->id}", '5001');
        $this->assertSame('approved', $izin->fresh()->status);
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/editMessageText')));

        // Klik kedua: sudah diputus, tidak diterapkan lagi.
        $this->klik("iz:no:{$izin->id}", '5001');
        $this->assertSame('approved', $izin->fresh()->status);
    }

    public function test_batalkan_persetujuan_memunculkan_kartu_lagi(): void
    {
        $this->nyalakan('pengajuan-izin');
        $izin = $this->izin();
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $this->actingAs($this->owner);
        app(IzinKeputusanService::class)->putuskan($izin, true, $this->owner);

        $this->post(route('sdm.pengajuan-izin.cancel', $izin->id))->assertRedirect();
        $this->assertSame('pending', $izin->fresh()->status);

        $meja->sinkron();
        $this->assertSame(2, Task::where('skill', 'pengajuan-izin')->count());
        $this->assertSame(1, Task::where('skill', 'pengajuan-izin')->where('status', 'open')->count());
    }

    public function test_pencatatan_telegram_mati_mengikuti_mode(): void
    {
        $this->nyalakan('pencatatan-telegram', 'nonaktif');
        TelegramSetting::singleton()->forceFill(['webhook_secret' => 'rahasia-uji'])->save();

        $this->postJson(route('telegram.webhook', 'rahasia-uji'), ['message' => ['chat' => ['id' => 5001], 'text' => 'catat bensin 50rb']])->assertOk();

        $kirim = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage'));
        $this->assertStringContainsString('dimatikan', $kirim[0][0]['text']);
        $this->assertSame(0, KaryawanVirtualJurnal::where('skill', PencatatanTelegram::KEY)->count());
    }

    public function test_bot_pencatatan_hanya_untuk_owner(): void
    {
        TelegramSetting::singleton()->forceFill(['webhook_secret' => 'rahasia-uji'])->save();

        $this->postJson(route('telegram.webhook', 'rahasia-uji'), ['message' => ['chat' => ['id' => 5002], 'text' => 'catat bensin 50rb']])->assertOk();

        $kirim = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage'));
        $this->assertCount(1, $kirim);
        $this->assertStringContainsString('hanya owner', $kirim[0][0]['text']);
    }

    public function test_halaman_asisten_owner_hanya_untuk_owner(): void
    {
        $this->admin->menuPermissions()->create(['menu_key' => 'karyawan-virtual']);

        // Admin dengan izin menu tetap melihat karyawan lain, tapi tidak Asisten Owner.
        $this->actingAs($this->admin)->get(route('karyawan-virtual.show', 'manajer-admin'))->assertOk();
        $this->actingAs($this->admin)->get(route('karyawan-virtual.show', 'asisten-owner'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('karyawan-virtual.index'))->assertOk()->assertDontSee('Asisten Owner');

        $this->actingAs($this->owner)->get(route('karyawan-virtual.show', 'asisten-owner'))->assertOk()->assertSee('Pencatatan via Telegram');
        $this->actingAs($this->owner)->get(route('karyawan-virtual.index'))->assertOk()->assertSee('Asisten Owner');
    }

    public function test_jurnal_pencatatan_tercatat_atas_nama_asisten_owner(): void
    {
        app(KaryawanVirtualService::class)->catat(PencatatanTelegram::KEY, [[
            'status' => 'dibuat', 'judul' => 'CD-001', 'alasan' => 'Bensin', 'tautan' => null, 'dokumen' => 'CD-001',
        ]], KaryawanVirtualService::PEMICU_PERISTIWA, 'Pesan Telegram', $this->owner->id, 'otomatis');

        $j = KaryawanVirtualJurnal::where('skill', PencatatanTelegram::KEY)->sole();
        $this->assertSame('asisten-owner', $j->karyawan);
        $this->assertSame(1, $j->jumlah_tindakan);
    }

    public function test_pengaturan_terpisah_dan_tidak_menghapus_anggota_meja_lain(): void
    {
        $bobot = fn (string $peran) => ['peran' => $peran, 'bobot' => 9, 'sla_menit' => 60];
        MejaKerjaPeran::create(['peran' => 'layanan', 'user_id' => $this->admin->id, 'urutan' => 1]);
        $this->actingAs($this->owner);

        $this->get(route('karyawan-virtual.admin.pengaturan', 'asisten-owner'))->assertOk()
            ->assertSee('Meja Owner')->assertDontSee('Meja Layanan')->assertSee('Pengajuan izin karyawan')->assertDontSee('QC timbang');
        $this->get(route('karyawan-virtual.admin.pengaturan', 'manajer-admin'))->assertOk()
            ->assertDontSee('Meja Owner')->assertDontSee('Pengajuan izin karyawan');

        // Simpan Asisten Owner tidak menyentuh anggota Meja Layanan.
        $this->put(route('karyawan-virtual.admin.pengaturan.simpan', 'asisten-owner'), [
            'peran' => ['owner' => [$this->owner->id]], 'skill' => ['pengajuan-izin' => $bobot('owner')],
        ])->assertRedirect();
        $this->assertTrue(MejaKerjaPeran::where('peran', 'layanan')->where('user_id', $this->admin->id)->exists());
        $this->assertSame(9, MejaKerjaSkill::where('skill', 'pengajuan-izin')->value('bobot'));

        // Simpan Manajer Admin tidak menghapus anggota Meja Owner & tak bisa mengarahkan skill ke sana.
        $this->put(route('karyawan-virtual.admin.pengaturan.simpan', 'manajer-admin'), [
            'peran' => ['layanan' => [$this->admin->id]], 'skill' => ['qc' => $bobot('layanan')],
        ])->assertRedirect();
        $this->assertTrue(MejaKerjaPeran::where('peran', 'owner')->where('user_id', $this->owner->id)->exists());

        $this->put(route('karyawan-virtual.admin.pengaturan.simpan', 'manajer-admin'), ['skill' => ['qc' => $bobot('owner')]])
            ->assertSessionHasErrors('skill.qc.peran');
    }
}
