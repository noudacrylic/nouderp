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

    private function scan(string $waktu): void
    {
        $machineId = DB::table('sdm_fingerprint_machines')->insertGetId([
            'code' => 'FP-01', 'name' => 'Mesin Absen', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sdm_fingerprint_logs')->insert([
            'machine_id'          => $machineId,
            'karyawan_id'         => $this->karyawanId,
            'user_id_fingerprint' => '1',
            'scan_at'             => $waktu,
            'verify_type'         => 'check_in',
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
