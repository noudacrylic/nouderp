<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\TugasOwner\Models\TugasOwner as Tugas;
use App\Modules\Tasks\TugasOwner\Models\TugasOwnerLampiran as Lampiran;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tugas dari Owner: admin menulis ulang yang ia tangkap sebelum mulai, tugas selesai karena owner
 * menyetujui, dan brief hanya terlihat oleh yang berkepentingan.
 */
class TugasOwnerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $lain;
    private string $kerja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kerja = sys_get_temp_dir() . '/tugas-owner-' . uniqid();
        File::makeDirectory($this->kerja . '/nas', 0775, true);
        config(['tugas_owner.root' => $this->kerja . '/nas', 'galeri.wajib_mount' => false, 'galeri.tmp_dir' => $this->kerja . '/tmp']);

        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->lain  = User::factory()->create(['role' => 'user', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->admin->id, 'urutan' => 1]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->kerja);
        parent::tearDown();
    }

    private function tugas(array $tambahan = []): Tugas
    {
        return Tugas::create($tambahan + ['judul' => 'Upload Kotak Saran', 'jenis' => 'upload_produk', 'dibuat_oleh' => $this->owner->id, 'pengerja_id' => $this->admin->id, 'tahap' => Tugas::DRAF]);
    }

    private function aksi(User $u, Tugas $t, string $aksi, array $data = [])
    {
        return $this->actingAs($u)->post(route('tasks.meja.tugas.aksi', [$t->id, $aksi]), $data);
    }

    private function unggah(User $u, Tugas $t, string $isi = 'abcdef', string $nama = 'Brief-layar.webm', array $tambahan = [])
    {
        return $this->actingAs($u)->postJson(route('tasks.meja.tugas.unggah', $t->id), $tambahan + [
            'id' => md5($nama . uniqid()), 'offset' => 0, 'total' => strlen($isi), 'nama' => $nama,
            'potongan' => UploadedFile::fake()->createWithContent('potongan', $isi),
        ]);
    }

    public function test_alur_penuh_dari_brief_sampai_disetujui(): void
    {
        $this->actingAs($this->owner)->post(route('tasks.meja.tugas.store'), ['judul' => 'Upload KS', 'jenis' => 'upload_produk', 'pengerja_id' => $this->admin->id])->assertRedirect();
        $t = Tugas::sole();
        $this->assertSame(Tugas::DRAF, $t->tahap);
        $this->assertTrue($t->wajib_periksa);

        $id = $this->unggah($this->owner, $t)->assertOk()->assertJson(['selesai' => true])->json('lampiran.id');
        $this->aksi($this->owner, $t, 'brief', ['isi' => 'Lihat rekaman', 'lampiran' => [$id]])->assertRedirect(route('tasks.meja.tugas.show', $t->id));
        $this->assertSame(Tugas::RINGKASAN, $t->fresh()->tahap);
        $this->assertNotNull(Lampiran::find($id)->pesan_id);

        // Admin tak bisa langsung kirim hasil: wajib menulis pemahaman dulu.
        $this->aksi($this->admin, $t, 'hasil', ['isi' => 'sudah'])->assertSessionHas('error');
        $this->aksi($this->admin, $t, 'ringkasan', ['isi' => ''])->assertSessionHas('error');
        $this->aksi($this->admin, $t, 'ringkasan', ['isi' => 'Upload ke Shopee, selesai Jumat']);
        $this->assertSame(Tugas::CEK_RINGKASAN, $t->fresh()->tahap);

        // Koreksi kembali ke admin, lalu disetujui.
        $this->aksi($this->owner, $t, 'koreksi', ['isi' => 'Tokopedia juga']);
        $this->assertSame(Tugas::RINGKASAN, $t->fresh()->tahap);
        $this->aksi($this->admin, $t, 'ringkasan', ['isi' => 'Shopee dan Tokopedia']);
        $this->aksi($this->owner, $t, 'setujui_ringkasan');
        $this->assertSame(Tugas::DIKERJAKAN, $t->fresh()->tahap);

        // Revisi tercatat; admin tak bisa menyetujui sendiri.
        $this->aksi($this->admin, $t, 'hasil', ['isi' => 'link hasil']);
        $this->assertSame(Tugas::PERIKSA, $t->fresh()->tahap);
        $this->aksi($this->admin, $t, 'setujui_hasil')->assertForbidden();
        $this->aksi($this->owner, $t, 'revisi', ['isi' => 'foto kurang terang']);
        $this->assertSame(Tugas::DIKERJAKAN, $t->fresh()->tahap);
        $this->assertSame(1, $t->fresh()->jumlah_revisi);

        $this->aksi($this->admin, $t, 'hasil', ['isi' => 'sudah diperbaiki']);
        $this->aksi($this->owner, $t, 'setujui_hasil');
        $this->assertSame(Tugas::SELESAI, $t->fresh()->tahap);
        $this->assertNotNull($t->fresh()->selesai_at);

        $this->actingAs($this->owner)->get(route('tasks.meja.tugas.show', $t->id))->assertOk()
            ->assertSee('Yang ditangkap admin')->assertSee('foto kurang terang')->assertSee('<video', false);
    }

    public function test_tugas_kecil_selesai_tanpa_diperiksa(): void
    {
        $t = $this->tugas(['tahap' => Tugas::DIKERJAKAN, 'wajib_periksa' => false]);
        $this->aksi($this->admin, $t, 'hasil', ['isi' => 'beres']);
        $this->assertSame(Tugas::SELESAI, $t->fresh()->tahap);
    }

    public function test_akses_draf_tersembunyi_dan_orang_lain_ditolak(): void
    {
        $t = $this->tugas();
        $this->actingAs($this->admin)->get(route('tasks.meja.tugas.show', $t->id))->assertForbidden();
        $this->actingAs($this->admin)->get(route('tasks.meja.tugas.index'))->assertOk()->assertDontSee('Upload Kotak Saran')->assertDontSee('Buat tugas baru');

        $this->aksi($this->owner, $t, 'brief', ['isi' => 'Tolong upload']);
        $this->actingAs($this->admin)->get(route('tasks.meja.tugas.show', $t->id))->assertOk()->assertSee('Tolong upload')->assertSee('Tulis apa yang kamu tangkap');
        $this->actingAs($this->admin)->get(route('tasks.meja.tugas.index'))->assertOk()->assertSee('Upload Kotak Saran');

        $this->actingAs($this->lain)->get(route('tasks.meja.tugas.show', $t->id))->assertForbidden();
        $this->unggah($this->lain, $t)->assertForbidden();
        $this->aksi($this->lain, $t, 'komentar', ['isi' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('tasks.meja.tugas.store'), ['judul' => 'x', 'jenis' => 'lain', 'pengerja_id' => $this->admin->id])->assertForbidden();

        $this->actingAs($this->owner)->get(route('tasks.meja.tugas.index'))->assertOk()->assertSee('Buat tugas baru')->assertSee($this->admin->name);
    }

    public function test_lampiran_hanya_untuk_yang_berhak_dan_tak_bisa_dipinjam(): void
    {
        $t = $this->tugas();
        $id = $this->unggah($this->owner, $t, 'isi-video')->json('lampiran.id');
        $this->assertFileExists($this->kerja . '/nas/' . Lampiran::find($id)->path);
        $this->assertStringStartsWith($t->created_at->format('Y-m') . '/tugas-' . $t->id . '/', Lampiran::find($id)->path);

        $this->actingAs($this->owner)->get(route('tasks.meja.tugas.lampiran', $id))->assertOk();
        $this->actingAs($this->lain)->get(route('tasks.meja.tugas.lampiran', $id))->assertForbidden();

        // Lampiran tugas lain atau milik orang lain tidak ikut menempel.
        $lain = $this->tugas(['judul' => 'Tugas lain']);
        $this->aksi($this->owner, $lain, 'brief', ['isi' => 'x', 'lampiran' => [$id]]);
        $this->assertNull(Lampiran::find($id)->pesan_id);

        // Brief kosong ditolak, tahap tidak berubah.
        $this->aksi($this->owner, $t, 'brief')->assertSessionHas('error');
        $this->assertSame(Tugas::DRAF, $t->fresh()->tahap);
    }

    public function test_jenis_lampiran_dan_penolakan_saat_nas_putus(): void
    {
        $t = $this->tugas();
        $this->assertSame('video', $this->unggah($this->owner, $t, 'a', 'a.webm')->json('lampiran.jenis'));
        $this->assertSame('suara', $this->unggah($this->owner, $t, 'a', 'b.webm', ['rekaman' => 'suara'])->json('lampiran.jenis'));
        $this->assertSame('suara', $this->unggah($this->owner, $t, 'a', 'c.m4a')->json('lampiran.jenis'));
        $this->assertSame('gambar', $this->unggah($this->owner, $t, 'a', 'd.jpg')->json('lampiran.jenis'));
        $this->unggah($this->owner, $t, 'a', 'e.exe')->assertStatus(422);

        config(['galeri.wajib_mount' => true]);
        $this->unggah($this->owner, $t, 'a', 'f.webm')->assertStatus(422)->assertJson(['pesan' => 'NAS tidak tersambung. Hubungi admin — foto belum bisa disimpan.']);
        $this->actingAs($this->owner)->get(route('tasks.meja.tugas.show', $t->id))->assertOk()->assertSee('NAS belum tersambung');
    }

    public function test_tombol_dan_lencana_di_meja_kerja(): void
    {
        $this->actingAs($this->admin)->get(route('tasks.meja.index'))->assertOk()->assertSee('Tugas dari Owner')->assertSee(route('tasks.meja.tugas.index'), false);

        $t = $this->tugas(['tahap' => Tugas::RINGKASAN]);
        $layanan = app(\App\Modules\Tasks\TugasOwner\TugasOwnerService::class);
        $this->assertSame(1, $layanan->jumlahMenunggu($this->admin));
        $this->assertSame(0, $layanan->jumlahMenunggu($this->owner));
        $this->aksi($this->admin, $t, 'ringkasan', ['isi' => 'paham']);
        $this->assertSame(0, $layanan->jumlahMenunggu($this->admin));
        $this->assertSame(1, $layanan->jumlahMenunggu($this->owner));
    }

    public function test_rekaman_gagal_tidak_memblokir_pesan_teks_dan_batal(): void
    {
        $t = $this->tugas(['tahap' => Tugas::DIKERJAKAN]);
        $this->aksi($this->admin, $t, 'komentar', ['isi' => 'Fotonya yang mana pak?']);
        $this->assertSame(Tugas::DIKERJAKAN, $t->fresh()->tahap, 'Pesan tidak menggeser tahap.');
        $this->aksi($this->admin, $t, 'batal')->assertForbidden();
        $this->aksi($this->owner, $t, 'batal');
        $this->assertSame(Tugas::BATAL, $t->fresh()->tahap);
        $this->aksi($this->owner, $t, 'komentar', ['isi' => 'x'])->assertSessionHas('error');
    }
}
