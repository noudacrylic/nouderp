<?php

namespace Tests\Feature\Production;

use App\Modules\Production\Services\ExecutorScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jadwal kerja efektif yang dipakai timer produksi.
 *
 * Bug yang dikunci di sini: jadwal mingguan saja tidak tahu bahwa seseorang menukar harinya
 * atau masuk di hari liburnya. Karena `assertExecutorsReady()` menolak begitu `is_off` true,
 * orang yang benar-benar bekerja hari Minggu tidak bisa menekan Mulai sama sekali — dan
 * SELURUH pekerjaan hari itu tidak terekam. Terjadi tiga kali di data nyata: 28 Juni,
 * 19 Juli, dan 9 Agustus 2026.
 */
class ExecutorScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    private const MINGGU = '2026-08-09';
    private const SELASA = '2026-08-11';
    private const JUMAT  = '2026-08-07';

    private ExecutorScheduleResolver $resolver;
    private int $karyawanId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(ExecutorScheduleResolver::class);

        $this->karyawanId = DB::table('sdm_karyawan')->insertGetId([
            'staf_code' => 'K-001', 'name' => 'Andi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (range(0, 6) as $dow) {
            DB::table('sdm_karyawan_schedule')->insert([
                'karyawan_id' => $this->karyawanId,
                'day_of_week' => $dow,
                'jam_masuk'   => $dow === 0 ? null : '08:00:00',
                'jam_pulang'  => $dow === 0 ? null : '16:00:00',
                'is_off'      => $dow === 0 ? 1 : 0,
                'created_at'  => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_hari_kerja_biasa_memakai_jadwalnya_sendiri(): void
    {
        $sched = $this->resolver->forKaryawan($this->karyawanId, Carbon::parse(self::SELASA));

        $this->assertNotNull($sched);
        $this->assertFalse((bool) $sched->is_off);
        $this->assertSame('08:00:00', $sched->jam_masuk);
    }

    public function test_hari_libur_tanpa_siapa_pun_masuk_tetap_libur(): void
    {
        $sched = $this->resolver->forKaryawan($this->karyawanId, Carbon::parse(self::MINGGU));

        $this->assertTrue((bool) $sched->is_off, 'Hari libur yang benar-benar libur harus tetap menolak timer.');
    }

    public function test_hari_libur_yang_ada_scan_membuka_timer_tapi_bukan_kapasitas(): void
    {
        // Inilah 19 Juli 2026: empat orang masuk hari Minggu, tanpa surat apa pun, dan
        // seharian penuh produksi tidak terekam karena timer menolak.
        $this->scan(self::MINGGU . ' 07:30:00');

        $sched = $this->resolver->forKaryawan($this->karyawanId, Carbon::parse(self::MINGGU));

        $this->assertFalse((bool) $sched->is_off, 'Orang yang sudah scan berarti bekerja — timer harus boleh jalan.');
        $this->assertSame('08:00:00', $sched->jam_masuk, 'Jam kerjanya dipinjam dari hari kerja, supaya auto-pause tahu kapan berhenti.');

        // Tapi mampir dan menempelkan jari BUKAN kapasitas terjadwal. Kalau ini ikut jadi
        // kapasitas, tiap orang yang lewat hari Minggu menambah lubang palsu 7 jam.
        $resmi = $this->resolver->scheduledFor($this->karyawanId, Carbon::parse(self::MINGGU));
        $this->assertTrue((bool) $resmi->is_off, 'Kapasitas hanya lahir dari hari yang dijadwalkan.');
    }

    public function test_tukar_hari_memindahkan_hari_kerjanya(): void
    {
        $this->tukarHari(self::JUMAT, self::MINGGU);

        // Hari kerja yang dilepas → libur, timer ditolak.
        $jumat = $this->resolver->forKaryawan($this->karyawanId, Carbon::parse(self::JUMAT));
        $this->assertTrue((bool) $jumat->is_off);

        // Hari libur pasangannya → boleh, walau belum ada scan sama sekali. Tukar hari itu
        // keputusan resmi, jadi kapasitasnya ikut pindah ke sana.
        $minggu = $this->resolver->forKaryawan($this->karyawanId, Carbon::parse(self::MINGGU));
        $this->assertFalse((bool) $minggu->is_off);
        $this->assertSame('16:00:00', $minggu->jam_pulang);

        $this->assertTrue((bool) $this->resolver->scheduledFor($this->karyawanId, Carbon::parse(self::JUMAT))->is_off);
        $this->assertFalse((bool) $this->resolver->scheduledFor($this->karyawanId, Carbon::parse(self::MINGGU))->is_off);
    }

    public function test_jadwal_asli_di_database_tidak_ikut_berubah(): void
    {
        $this->tukarHari(self::JUMAT, self::MINGGU);
        $this->resolver->forKaryawan($this->karyawanId, Carbon::parse(self::JUMAT));

        // Yang dikembalikan hanya salinan untuk hari itu; jadwal mingguannya tidak boleh ternoda.
        $this->assertSame(0, (int) DB::table('sdm_karyawan_schedule')
            ->where('karyawan_id', $this->karyawanId)->where('day_of_week', 5)->value('is_off'));
    }

    public function test_scan_pagi_bertombol_lembur_tetap_dihitung_masuk(): void
    {
        // 27 Okt 2026: tombol status mesin tertahan di "Lembur Masuk" sejak sore sebelumnya.
        // Semua scan pagi terekam overtime_in → timer menganggap belum check-in dan mem-pause
        // semua task tiap menit, walau operator menekan Resume berkali-kali.
        $this->pakaiJadwalLembur();
        $this->scan(self::SELASA . ' 07:38:30', 'overtime_in');

        $hari = Carbon::parse(self::SELASA . ' 09:00:00');

        $this->assertSame(self::SELASA . ' 07:38:30', $this->resolver->hasCheckedIn($this->karyawanId, $hari)?->format('Y-m-d H:i:s'));
        $this->assertNull($this->resolver->hasOvertimeIn($this->karyawanId, $hari), 'Scan pagi tidak boleh membuka lembur malam nanti.');
    }

    public function test_jenis_scan_sore_ditentukan_jendela_jadwal(): void
    {
        $this->pakaiJadwalLembur();
        $this->scan(self::SELASA . ' 07:30:00');
        $this->scan(self::SELASA . ' 16:02:00', 'overtime_in');  // pulang, tombol mesin diabaikan
        $this->scan(self::SELASA . ' 16:03:00');                 // tap ulang → tetap pulang
        $this->scan(self::SELASA . ' 16:20:00');                 // lembur (sudah ada scan pulang)
        $this->scan(self::SELASA . ' 19:55:00');                 // pulang lembur

        $jenis = fn ($jam) => $this->resolver->jenisScan($this->karyawanId, Carbon::parse(self::SELASA . " $jam"));

        $this->assertSame('check_in', $jenis('07:30:00'));
        $this->assertSame('check_out', $jenis('16:02:00'));
        $this->assertSame('check_out', $jenis('16:03:00'));
        $this->assertSame('overtime_in', $jenis('16:20:00'));
        $this->assertSame('overtime_out', $jenis('19:55:00'));
        $this->assertSame(self::SELASA . ' 19:55:00', $this->resolver->hasCheckedOut($this->karyawanId, Carbon::parse(self::SELASA))?->format('Y-m-d H:i:s'));
    }

    public function test_scan_lewat_1615_tanpa_scan_pulang_adalah_pulang_bukan_lembur(): void
    {
        $this->pakaiJadwalLembur();
        $this->scan(self::SELASA . ' 07:30:00');
        $this->scan(self::SELASA . ' 16:20:00');

        $hari = Carbon::parse(self::SELASA);

        $this->assertSame('check_out', $this->resolver->jenisScan($this->karyawanId, Carbon::parse(self::SELASA . ' 16:20:00')));
        $this->assertNull($this->resolver->hasOvertimeIn($this->karyawanId, $hari));
    }

    /** Jadwal seperti di layar Karyawan: pulang 16:00 (awal 120, akhir 15), lembur 16:30 (awal 15, akhir 120). */
    private function pakaiJadwalLembur(): void
    {
        DB::table('sdm_karyawan_schedule')->where('karyawan_id', $this->karyawanId)->where('is_off', 0)->update([
            'awal_absen_pulang' => 120, 'akhir_absen_pulang' => 15,
            'has_lembur' => 1, 'jam_masuk_lembur' => '16:30:00',
            'awal_absen_lembur_masuk' => 15, 'akhir_absen_lembur_masuk' => 120,
            'jam_pulang_lembur' => '20:00:00',
        ]);
    }

    private function scan(string $waktu, string $jenis = 'check_in'): void
    {
        $machineId = DB::table('sdm_fingerprint_machines')->where('code', 'FP-01')->value('id')
            ?? DB::table('sdm_fingerprint_machines')->insertGetId([
                'code' => 'FP-01', 'name' => 'Mesin Absen', 'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('sdm_fingerprint_logs')->insert([
            'machine_id'          => $machineId,
            'karyawan_id'         => $this->karyawanId,
            'user_id_fingerprint' => '1',
            'scan_at'             => $waktu,
            'verify_type'         => $jenis,
            'created_at'          => now(), 'updated_at' => now(),
        ]);
    }

    private function tukarHari(string $tanggal, string $pairedDate): void
    {
        DB::table('sdm_attendance_overrides')->insert([
            'karyawan_id' => $this->karyawanId,
            'tanggal'     => $tanggal,
            'paired_date' => $pairedDate,
            'type'        => 'tukar_hari',
            'notes'       => 'ganti hari',
            'created_at'  => now(), 'updated_at' => now(),
        ]);
    }
}
