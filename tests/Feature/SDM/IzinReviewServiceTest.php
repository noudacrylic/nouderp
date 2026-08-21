<?php

namespace Tests\Feature\SDM;

use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Services\IzinReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bahan peninjauan pengajuan izin.
 *
 * Yang dijaga: pola-pola yang biasanya berarti karyawan salah pilih tanggal harus MUNCUL
 * sebagai peringatan, bukan lolos diam-diam. Peringatan tidak memblokir apa pun — tapi kalau
 * ia hilang, penyetuju kembali buta seperti sebelumnya, dan salahnya baru ketahuan setelah
 * gaji dihitung.
 */
class IzinReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Selasa (hari kerja). */
    private const SELASA = '2026-08-11';
    /** Minggu (libur jadwal). */
    private const MINGGU = '2026-08-09';

    private IzinReviewService $svc;
    private int $karyawanId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(IzinReviewService::class);

        $this->karyawanId = DB::table('sdm_karyawan')->insertGetId([
            'staf_code' => 'K-001', 'name' => 'Andi', 'hak_cuti' => 12,
            'created_at' => now(), 'updated_at' => now(),
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

    public function test_izin_di_tanggal_yang_justru_ada_scan_diberi_peringatan_keras(): void
    {
        // Inilah kasus yang mau ditangkap: minta cuti untuk hari yang dia sebenarnya masuk.
        $this->scan(self::SELASA . ' 07:58:00');
        $req = $this->request('cuti', self::SELASA);

        $bahaya = $this->levels($req, 'danger');

        $this->assertCount(1, $bahaya);
        $this->assertStringContainsString('TERCATAT SCAN', $bahaya[0]);
        $this->assertStringContainsString('07:58', $bahaya[0], 'Jam scannya ikut ditulis supaya bisa langsung dicocokkan.');
    }

    public function test_izin_untuk_hari_libur_diberi_peringatan(): void
    {
        $req = $this->request('cuti', self::MINGGU);

        $this->assertNotEmpty(
            array_filter($this->levels($req, 'warn'), fn ($t) => str_contains($t, 'memang libur')),
            'Mengajukan cuti untuk hari libur hampir selalu salah tanggal.'
        );
    }

    public function test_pengajuan_yang_wajar_tidak_memunculkan_peringatan_apa_pun(): void
    {
        $req = $this->request('cuti', self::SELASA);

        $this->assertSame([], $this->svc->build($req)['warnings'], 'Peringatan yang muncul terus-menerus akan diabaikan orang.');
    }

    public function test_tukar_hari_dengan_pasangan_bukan_hari_libur_ditolak_lewat_peringatan(): void
    {
        $req = $this->request('tukar_hari', self::SELASA);
        $req->update(['paired_date' => '2026-08-12']); // Rabu — sama-sama hari kerja

        $bahaya = $this->levels($req->fresh(), 'danger');

        $this->assertNotEmpty(array_filter($bahaya, fn ($t) => str_contains($t, 'BUKAN hari libur')));
    }

    public function test_cuti_melebihi_sisa_hak_diberi_peringatan(): void
    {
        // 12 hari cuti sudah terpakai tahun ini.
        for ($i = 1; $i <= 12; $i++) {
            $tgl = '2026-03-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            DB::table('sdm_attendance')->insert([
                'periode_id'  => $this->periodeId($tgl),
                'karyawan_id' => $this->karyawanId,
                'tanggal'     => $tgl,
                'status'      => 'cuti',
                'created_at'  => now(), 'updated_at' => now(),
            ]);
        }

        $req = $this->request('cuti', self::SELASA);

        $this->assertNotEmpty(
            array_filter($this->levels($req, 'danger'), fn ($t) => str_contains($t, 'Sisa cuti'))
        );
        $this->assertSame(0, $this->svc->build($req)['sisa_cuti']);
    }

    public function test_hari_yang_tidak_ikut_diterapkan_ditandai(): void
    {
        // Cuti Sabtu–Senin: Minggu di tengahnya dilewati saat approval.
        $req = $this->request('cuti', '2026-08-08');
        $req->update(['tanggal_akhir' => '2026-08-10']);

        $dates = $this->svc->build($req->fresh())['dates'];

        $this->assertCount(3, $dates);
        $this->assertTrue($dates[0]['applied']);
        $this->assertFalse($dates[1]['applied'], 'Minggu dilewati oleh approval — harus terlihat, bukan jadi kejutan.');
        $this->assertTrue($dates[2]['applied']);
    }

    public function test_fakta_hari_itu_ikut_dibawa_bukan_cuma_penilaian(): void
    {
        $this->scan(self::SELASA . ' 07:58:00');
        $this->scan(self::SELASA . ' 16:02:00');
        DB::table('sdm_attendance')->insert([
            'periode_id' => $this->periodeId(self::SELASA),
            'karyawan_id' => $this->karyawanId, 'tanggal' => self::SELASA,
            'status' => 'hadir', 'on_work1' => '07:58:00', 'off_work1' => '16:02:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $hari = $this->svc->build($this->request('cuti', self::SELASA))['dates'][0];

        $this->assertCount(2, $hari['scans'], 'Scan mentah ditampilkan apa adanya, bukan disimpulkan.');
        $this->assertSame('07:58:00', $hari['scans'][0]['jam']);
        $this->assertSame('hadir', $hari['attendance']['status']);
        $this->assertFalse($hari['is_off']);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function levels(IzinRequest $req, string $level): array
    {
        return array_values(array_map(
            fn ($w) => $w['text'],
            array_filter($this->svc->build($req)['warnings'], fn ($w) => $w['level'] === $level)
        ));
    }

    private function request(string $type, string $tanggal): IzinRequest
    {
        return IzinRequest::create([
            'karyawan_id' => $this->karyawanId,
            'type'        => $type,
            'tanggal'     => $tanggal,
            'alasan'      => 'keperluan keluarga',
            'status'      => 'pending',
        ]);
    }

    private function periodeId(string $tanggal): int
    {
        $d = \Carbon\Carbon::parse($tanggal);

        $existing = DB::table('sdm_periode_penggajian')
            ->where('bulan', $d->month)->where('tahun', $d->year)->value('id');

        return $existing ?: DB::table('sdm_periode_penggajian')->insertGetId([
            'code'       => 'PG-' . $d->format('Ym'),
            'bulan'      => $d->month,
            'tahun'      => $d->year,
            'label'      => $d->translatedFormat('F Y'),
            'start_date' => $d->copy()->startOfMonth()->toDateString(),
            'end_date'   => $d->copy()->endOfMonth()->toDateString(),
            'status'     => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function scan(string $waktu): void
    {
        $machineId = DB::table('sdm_fingerprint_machines')->insertGetId([
            'code' => 'FP-' . substr(md5($waktu), 0, 6), 'name' => 'Mesin Absen',
            'created_at' => now(), 'updated_at' => now(),
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
}
