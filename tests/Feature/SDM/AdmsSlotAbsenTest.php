<?php

namespace Tests\Feature\SDM;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Services\AdmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Slot absensi dari scan mesin: jenis scan ditentukan jendela jadwal (sama dengan timer
 * produksi), bukan tombol status mesin dan bukan batas tetap 16:30.
 *
 * Yang dijaga: pulang telat (scan 16:39 tanpa scan pulang) dulu dibayar lembur 1 jam
 * (19 Okt 2026), dan scan lembur 16:21 malah tercatat sebagai jam pulang.
 */
class AdmsSlotAbsenTest extends TestCase
{
    use RefreshDatabase;

    private const SELASA = '2026-08-11';

    private int $karyawanId;
    private int $machineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karyawanId = DB::table('sdm_karyawan')->insertGetId([
            'staf_code' => 'K-001', 'name' => 'Andi', 'user_id_fingerprint' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Jadwal seperti di layar Karyawan.
        foreach (range(0, 6) as $dow) {
            DB::table('sdm_karyawan_schedule')->insert([
                'karyawan_id' => $this->karyawanId,
                'day_of_week' => $dow,
                'jam_masuk'   => $dow === 0 ? null : '08:00:00',
                'jam_pulang'  => $dow === 0 ? null : '16:00:00',
                'awal_absen_pulang' => 120, 'akhir_absen_pulang' => 15,
                'has_lembur'  => $dow === 0 ? 0 : 1,
                'jam_masuk_lembur'  => $dow === 0 ? null : '16:30:00',
                'awal_absen_lembur_masuk' => 15, 'akhir_absen_lembur_masuk' => 120,
                'jam_pulang_lembur' => $dow === 0 ? null : '20:00:00',
                'is_off'      => $dow === 0 ? 1 : 0,
                'created_at'  => now(), 'updated_at' => now(),
            ]);
        }

        $this->machineId = DB::table('sdm_fingerprint_machines')->insertGetId([
            'code' => 'FP-01', 'name' => 'Mesin Absen', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_pulang_lalu_lembur_lalu_pulang_lembur_masuk_slotnya_masing_masing(): void
    {
        $att = $this->scans(['07:30:00', '16:00:20', '16:21:30', '20:00:40']);

        $this->assertSame('07:30:00', $att->on_work1);
        $this->assertSame('16:00:20', $att->off_work1, 'Scan lembur 16:21 tidak boleh menimpa jam pulang.');
        $this->assertSame('16:21:30', $att->on_work2);
        $this->assertSame('20:00:40', $att->off_work2);
    }

    public function test_scan_lewat_1615_tanpa_scan_pulang_adalah_pulang_bukan_lembur(): void
    {
        $att = $this->scans(['07:30:00', '16:39:16']);

        $this->assertSame('16:39:16', $att->off_work1);
        $this->assertNull($att->on_work2);
        $this->assertNull($att->off_work2);
        $this->assertEquals(0, (float) $att->overtime_hours, 'Pulang telat tidak dibayar lembur.');
    }

    public function test_tombol_lembur_di_mesin_diabaikan(): void
    {
        $att = $this->scans(['07:38:30', '16:02:00'], 'overtime_in');

        $this->assertSame('07:38:30', $att->on_work1);
        $this->assertSame('16:02:00', $att->off_work1);
        $this->assertNull($att->off_work2);
    }

    private function scans(array $jam, string $jenisMesin = 'check_in'): Attendance
    {
        $svc = app(AdmsService::class);

        foreach ($jam as $j) {
            $log = FingerprintLog::create([
                'machine_id'          => $this->machineId,
                'user_id_fingerprint' => '1',
                'karyawan_id'         => $this->karyawanId,
                'scan_at'             => self::SELASA . " $j",
                'verify_type'         => $jenisMesin,
            ]);
            $svc->mergeIntoAttendance($log);
        }

        return Attendance::where('karyawan_id', $this->karyawanId)->whereDate('tanggal', self::SELASA)->firstOrFail();
    }
}
