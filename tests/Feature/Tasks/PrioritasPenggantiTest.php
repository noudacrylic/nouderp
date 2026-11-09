<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\Tasks\MejaKerja\KehadiranAdmin;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\PrioritasMeja;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\TugasOwner\Models\TugasOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Skill "Prioritas & pengganti admin" (6 Nov 2026): urutan kartu per kategori, dan oper chat +
 * kartu tertentu saat admin tidak berangkat (izin / belum scan fingerprint jam 09:00).
 */
class PrioritasPenggantiTest extends TestCase
{
    use RefreshDatabase;

    private User $saiful;
    private User $farid;
    private int $kSaiful;
    private int $kFarid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kSaiful = DB::table('sdm_karyawan')->insertGetId(['staf_code' => 'MNF', 'name' => 'Munif', 'created_at' => now(), 'updated_at' => now()]);
        $this->kFarid  = DB::table('sdm_karyawan')->insertGetId(['staf_code' => 'FRD', 'name' => 'Farid', 'created_at' => now(), 'updated_at' => now()]);
        $this->saiful = User::factory()->create(['name' => 'Saiful Munif', 'role' => 'admin', 'is_active' => true, 'karyawan_id' => $this->kSaiful]);
        $this->farid  = User::factory()->create(['name' => 'Farid', 'role' => 'admin', 'is_active' => true, 'karyawan_id' => $this->kFarid]);
        MejaKerjaPeran::create(['peran' => 'layanan', 'user_id' => $this->saiful->id, 'urutan' => 1]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->farid->id, 'urutan' => 1]);
        $this->travelTo(now()->setTime(10, 0));
    }

    private function scan(int $karyawanId, string $jam = '07:30'): void
    {
        $mesin = DB::table('sdm_fingerprint_machines')->value('id')
            ?? DB::table('sdm_fingerprint_machines')->insertGetId(['code' => 'FP-01', 'name' => 'Mesin', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sdm_fingerprint_logs')->insert(['machine_id' => $mesin, 'karyawan_id' => $karyawanId, 'user_id_fingerprint' => '1',
            'scan_at' => now()->setTimeFromTimeString($jam), 'verify_type' => 'check_in', 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetInstance(KehadiranAdmin::class);
    }

    private function kartu(string $skill, User $u, string $peran, array $isi = []): Task
    {
        return Task::create($isi + [
            'title' => "Kartu $skill", 'status' => 'open', 'priority' => 'normal', 'source' => 'skill', 'skill' => $skill,
            'peran' => $peran, 'assignee_user_id' => $u->id, 'creator_user_id' => $u->id, 'kunci_sumber' => uniqid("$skill:"),
            'taskable_id' => 1,   // tombol kartu butuh id dokumen untuk dirender
        ]);
    }

    private function returMendesak(): Task
    {
        return $this->kartu('retur', $this->saiful, 'layanan', ['title' => 'Retur terima/banding', 'taskable_type' => \App\Modules\Sales\Models\SalesReturn::class, 'taskable_id' => 999, 'tenggat_at' => now()->addHours(3), 'meta' => ['fase' => 'periksa', 'tenggat_luar' => true]]);
    }

    private function otomatis(): void
    {
        app(KaryawanVirtualService::class)->ubahMode('admin.prioritas', Skill::OTOMATIS, null);
    }

    private function jalankan(): void
    {
        app()->forgetInstance(KehadiranAdmin::class);
        app(KaryawanVirtualService::class)->jalankan('admin.prioritas', KaryawanVirtualService::PEMICU_JADWAL);
    }

    public function test_urutan_meja_layanan_per_kategori(): void
    {
        $k = fn (string $s, array $isi = []) => $this->kartu($s, $this->saiful, 'layanan', $isi);
        $ulasan    = $k('ulasan-jelek');
        $finish    = $k('retur', ['title' => 'Finish retur', 'meta' => ['fase' => 'hasil']]);
        $lama      = $k('retur', ['title' => 'Periksa, batas masih 5 hari', 'tenggat_at' => now()->addDays(5), 'meta' => ['fase' => 'periksa', 'tenggat_luar' => true]]);
        $selesai   = $k('selesaikan-pesanan');
        $beli      = $k('purchasing-ready');
        $qc        = $k('qc');
        $mendesak  = $this->returMendesak();
        $ownerR    = $k('tugas-owner', ['meta' => ['prioritas' => 'rendah']]);
        $ownerU    = $k('tugas-owner', ['meta' => ['prioritas' => 'utama']]);

        $urut = PrioritasMeja::urutkan(Task::all(), 'layanan')->pluck('id')->all();

        $this->assertSame([$ownerU->id, $mendesak->id, $qc->id, $beli->id, $selesai->id, $lama->id, $finish->id, $ulasan->id, $ownerR->id], $urut);
        $this->assertSame('retur', PrioritasMeja::kategori($lama), 'periksa yang batasnya > 24 jam = finish retur');
    }

    public function test_kehadiran_dari_izin_dan_scan_fingerprint(): void
    {
        $h = app(KehadiranAdmin::class);
        $this->assertFalse($h->tidakMasuk($this->saiful->id, now()->setTime(8, 30)), 'sebelum jam 09:00 belum dianggap tidak masuk');
        $this->assertTrue($h->tidakMasuk($this->saiful->id, now()->setTime(9, 5)));

        $this->scan($this->kSaiful, '09:20');
        $this->assertFalse(app(KehadiranAdmin::class)->tidakMasuk($this->saiful->id, now()->setTime(9, 30)));

        IzinRequest::create(['karyawan_id' => $this->kFarid, 'type' => 'cuti', 'tanggal' => today()->subDay(), 'tanggal_akhir' => today()->addDay(),
                             'alasan' => 'cuti', 'status' => 'approved']);
        $this->assertTrue(app(KehadiranAdmin::class)->tidakMasuk($this->farid->id, now()->setTime(7, 0)), 'cuti disetujui = tidak masuk sejak pagi');

        // Hari libur di jadwal SDM / libur nasional: belum scan bukan berarti tidak berangkat.
        $hari = (int) now()->format('w');
        DB::table('sdm_karyawan_schedule')->insert(['karyawan_id' => $this->kFarid, 'day_of_week' => $hari, 'is_off' => true, 'created_at' => now(), 'updated_at' => now()]);
        $kLibur = DB::table('sdm_karyawan')->insertGetId(['staf_code' => 'LBR', 'name' => 'Libur', 'created_at' => now(), 'updated_at' => now()]);
        $uLibur = User::factory()->create(['karyawan_id' => $kLibur]);
        DB::table('sdm_karyawan_schedule')->insert(['karyawan_id' => $kLibur, 'day_of_week' => $hari, 'is_off' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(app(KehadiranAdmin::class)->tidakMasuk($uLibur->id, now()->setTime(10, 0)), 'jadwal libur = bukan tidak masuk');
        $this->assertTrue(app(KehadiranAdmin::class)->tidakMasuk($this->farid->id, now()->setTime(10, 0)), 'cuti tetap menang atas jadwal libur');

        $kNas = DB::table('sdm_karyawan')->insertGetId(['staf_code' => 'NAS', 'name' => 'Nasional', 'created_at' => now(), 'updated_at' => now()]);
        $uNas = User::factory()->create(['karyawan_id' => $kNas]);
        $this->assertTrue(app(KehadiranAdmin::class)->tidakMasuk($uNas->id, now()->setTime(10, 0)));
        DB::table('sdm_national_holidays')->insert(['tanggal' => today()->toDateString(), 'nama' => 'Libur', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(app(KehadiranAdmin::class)->tidakMasuk($uNas->id, now()->setTime(10, 0)), 'libur nasional = bukan tidak masuk');

        $tanpaData = User::factory()->create(['karyawan_id' => null]);
        $this->assertFalse(app(KehadiranAdmin::class)->tidakMasuk($tanpaData->id), 'tanpa data kehadiran dianggap hadir');
    }

    public function test_layanan_tidak_masuk_chat_retur24j_qc_dioper_lalu_dikembalikan(): void
    {
        $this->otomatis();
        $this->scan($this->kFarid);
        $mendesak = $this->returMendesak();
        $qc       = $this->kartu('qc', $this->saiful, 'layanan');
        $beli     = $this->kartu('purchasing-ready', $this->saiful, 'layanan');
        $chat     = CrmConversation::create(['channel' => 'whatsapp', 'contact_key' => '628111', 'display_name' => 'Budi',
                                             'status' => CrmConversation::STATUS_AKTIF, 'unread_count' => 0, 'owner_user_id' => $this->saiful->id]);

        $this->jalankan();

        $this->assertSame($this->farid->id, $mendesak->refresh()->assignee_user_id);
        $this->assertSame($this->farid->id, $qc->refresh()->assignee_user_id);
        $this->assertSame($this->saiful->id, $beli->refresh()->assignee_user_id, 'kartu lain tetap menunggu Saiful');
        $this->assertSame($this->farid->id, $chat->refresh()->owner_user_id);
        $this->assertSame($this->saiful->id, $chat->dioper_dari_user_id);
        $this->assertSame(0, $chat->unread_count, 'oper karena tidak masuk tidak menandai chat lama belum dibaca');

        // Atur penugasan tidak boleh menarik kartu pindahan kembali ke meja asalnya.
        app(MejaKerjaService::class)->aturPenugasan();
        $this->assertSame($this->farid->id, $qc->refresh()->assignee_user_id);

        // Kartu pindahan tampil di Meja Desain.
        $this->actingAs($this->farid)->get(route('tasks.meja.index', ['lihat' => 'desain']))->assertOk()->assertSee('terima/banding')->assertSee('Kartu qc');   // lencana "Retur" memotong kata depan judul

        // Di meja Farid: retur < 24 jam paling atas, QC setelah desain.
        $desain = $this->kartu('desain', $this->farid, 'desain');
        $gambar = $this->kartu('desain-gambar', $this->farid, 'desain');
        $urut = PrioritasMeja::urutkan(Task::where('assignee_user_id', $this->farid->id)->get(), 'desain')->pluck('id')->all();
        $this->assertSame([$mendesak->id, $desain->id, $qc->id, $gambar->id], $urut);

        // Saiful datang → semuanya kembali.
        $this->scan($this->kSaiful, '09:45');
        $this->jalankan();
        $this->assertSame($this->saiful->id, $mendesak->refresh()->assignee_user_id);
        $this->assertArrayNotHasKey('dioper_dari', $mendesak->meta ?? []);
        $this->assertSame($this->saiful->id, $chat->refresh()->owner_user_id);
        $this->assertNull($chat->dioper_dari_user_id);
    }

    public function test_desain_tidak_masuk_hanya_kartu_desain_dioper(): void
    {
        $this->otomatis();
        $this->scan($this->kSaiful);
        $desain = $this->kartu('desain', $this->farid, 'desain');
        $gambar = $this->kartu('desain-gambar', $this->farid, 'desain');
        $qc     = $this->kartu('qc', $this->saiful, 'layanan');

        $this->jalankan();

        $this->assertSame($this->saiful->id, $desain->refresh()->assignee_user_id);
        $this->assertSame($this->farid->id, $gambar->refresh()->assignee_user_id);
        // Di meja Saiful, desain pindahan paling atas.
        $urut = PrioritasMeja::urutkan(Task::where('assignee_user_id', $this->saiful->id)->get(), 'layanan')->pluck('id')->all();
        $this->assertSame([$desain->id, $qc->id], $urut);
    }

    public function test_keduanya_tidak_masuk_tidak_ada_yang_dipindah_dan_bayangan_tidak_mengubah(): void
    {
        $qc = $this->kartu('qc', $this->saiful, 'layanan');
        $this->otomatis();
        $this->jalankan();   // tak ada yang scan (mis. hari libur)
        $this->assertSame($this->saiful->id, $qc->refresh()->assignee_user_id);

        app(KaryawanVirtualService::class)->ubahMode('admin.prioritas', Skill::BAYANGAN, null);
        $this->scan($this->kFarid);
        $this->jalankan();
        $this->assertSame($this->saiful->id, $qc->refresh()->assignee_user_id, 'Bayangan hanya mencatat');
    }

    public function test_tugas_owner_jadi_kartu_pengerja_dengan_prioritas(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $t = TugasOwner::create(['judul' => 'Foto katalog baru', 'jenis' => 'lain', 'dibuat_oleh' => $owner->id, 'pengerja_id' => $this->farid->id,
                                 'tahap' => TugasOwner::DIKERJAKAN, 'prioritas' => 'utama']);
        app(KaryawanVirtualService::class)->ubahMode('admin.tugas-owner', Skill::OTOMATIS, null);

        app(MejaKerjaService::class)->sinkron();

        $kartu = Task::where('skill', 'tugas-owner')->first();
        $this->assertSame($this->farid->id, $kartu->assignee_user_id);
        $this->assertSame('desain', $kartu->peran);
        $this->assertSame('owner-utama', PrioritasMeja::kategori($kartu));

        // Owner menurunkan prioritas dari layar tugas.
        $this->actingAs($owner)->post(route('tasks.meja.tugas.prioritas', $t->id), ['prioritas' => 'rendah'])->assertSessionHas('success');
        app(MejaKerjaService::class)->sinkron();
        $this->assertSame('owner-rendah', PrioritasMeja::kategori($kartu->refresh()));

        // Pindah tahap ke pemeriksaan owner → kartu admin selesai.
        $t->update(['tahap' => TugasOwner::PERIKSA]);
        app(MejaKerjaService::class)->sinkron();
        $this->assertSame('done', $kartu->refresh()->status);
    }
}
