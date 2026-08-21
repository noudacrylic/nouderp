<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Services\ProductionCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Invarian Kalender Produksi.
 *
 * Halaman ini dipakai untuk MEMUTUSKAN kuota jam mesin, jadi yang dilindungi di sini
 * adalah hal-hal yang bisa membuat gambarannya bohong tanpa terlihat bohong:
 *
 *  1. Waktu terpakai dihitung dari GABUNGAN interval, bukan penjumlahan blok. Dua langkah
 *     yang tercatat berjalan bersamaan di satu mesin tidak boleh jadi "8 jam dari shift 7 jam".
 *  2. Istirahat bukan lubang, dan celah pendek bukan lubang. Kalau keduanya ikut terhitung,
 *     tiap hari akan tampak bolong 1 jam lebih dan keputusan kuotanya jadi salah arah.
 *  3. Kerja di luar jam kerja dipisah, tidak menambah "terpakai" — kalau tidak, utilisasi
 *     bisa lewat 100% tanpa penjelasan.
 *  4. Operator penaung mesin tidak punya baris sama sekali, supaya jam yang sama tidak masuk
 *     dua kali — tapi catatan lama atas namanya tidak boleh menguap.
 *  5. Tanggal merah tidak menghasilkan kapasitas, jadi tidak menghasilkan lubang. Cuti orang
 *     sebaliknya: kapasitas tetap dihitung, hanya diberi keterangan.
 *  6. Timer yang belum ditutup tetap muncul apa adanya dan ditandai — justru itu penyakit
 *     yang sedang dicari, jangan dibersihkan diam-diam.
 */
class ProductionCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Selasa. Dipakai konsisten supaya jadwal day_of_week-nya pasti kena. */
    private const HARI = '2026-08-11';

    private ProductionCalendarService $svc;
    private int $cncId;
    private int $andiId;
    private int $mesin1Id;
    private int $mesin2Id;
    private int $orderId;
    private int $andiKaryawanId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(ProductionCalendarService::class);

        $this->cncId = DB::table('production_departments')->insertGetId([
            'code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Andi 08:00–16:00, istirahat 11:30–12:30 → shift bersih 7 jam.
        $karyawanId = $this->andiKaryawanId = DB::table('sdm_karyawan')->insertGetId([
            'staf_code' => 'K-001', 'name' => 'Andi', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(0, 6) as $dow) {
            DB::table('sdm_karyawan_schedule')->insert([
                'karyawan_id'         => $karyawanId,
                'day_of_week'         => $dow,
                'jam_masuk'           => $dow === 0 ? null : '08:00:00',
                'jam_pulang'          => $dow === 0 ? null : '16:00:00',
                'jam_istirahat_start' => $dow === 0 ? null : '11:30:00',
                'jam_istirahat_end'   => $dow === 0 ? null : '12:30:00',
                'is_off'              => $dow === 0 ? 1 : 0,
                'created_at'          => now(), 'updated_at' => now(),
            ]);
        }

        // Andi menaungi Mesin 1 & Mesin 2 → mesin mewarisi jadwal Andi, Andi bukan slot.
        $this->andiId   = $this->executor('Andi', $karyawanId, null);
        $this->mesin1Id = $this->executor('Mesin 1', null, $this->andiId);
        $this->mesin2Id = $this->executor('Mesin 2', null, $this->andiId);

        $this->orderId = DB::table('production_orders')->insertGetId([
            'order_number' => 'OP/TEST/0001', 'type' => 'ready_stock', 'warehouse_id' => 1,
            'production_date' => self::HARI, 'status' => 'in_progress', 'planned_cycles' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ==========================================================
    // GABUNGAN INTERVAL
    // ==========================================================

    public function test_dua_langkah_bertumpuk_di_satu_mesin_tidak_dihitung_dua_kali(): void
    {
        // 08:00–10:00 dan 09:00–11:00 di mesin yang sama: gabungannya 3 jam, bukan 4.
        $this->step([['08:00', '10:00']], [$this->mesin1Id]);
        $this->step([['09:00', '11:00']], [$this->mesin1Id]);

        $row = $this->row('Mesin 1');

        $this->assertSame(3 * 3600, $row['busy_seconds'], 'Terpakai harus gabungan interval, bukan jumlah blok.');
        $this->assertSame(3600, $row['overlap_seconds'], 'Satu jam tumpang tindih harus dilaporkan, bukan disembunyikan.');
        $this->assertSame(2, $row['block_count'], 'Kedua blok tetap ditampilkan supaya tumpang tindihnya terlihat.');
    }

    // ==========================================================
    // LUBANG
    // ==========================================================

    public function test_istirahat_bukan_lubang(): void
    {
        // Kerja penuh kecuali jam istirahat → tidak ada lubang sama sekali.
        $this->step([['08:00', '11:30'], ['12:30', '16:00']], [$this->mesin1Id]);

        $row = $this->row('Mesin 1');

        $this->assertSame(0, $row['gap_seconds'], 'Jam istirahat tidak boleh dihitung sebagai kapasitas hilang.');
        $this->assertSame(7 * 3600, $row['busy_seconds']);
        $this->assertEqualsWithDelta(100.0, $row['utilization'], 0.01);
    }

    public function test_celah_pendek_bukan_lubang_tapi_celah_panjang_iya(): void
    {
        // Celah 4 menit (ganti benda kerja) lalu celah 30 menit (kapasitas benar-benar kosong).
        $this->step([['08:00', '09:00'], ['09:04', '11:30'], ['12:30', '15:30']], [$this->mesin1Id]);

        $row = $this->row('Mesin 1');

        $this->assertCount(1, $row['gaps'], 'Hanya celah ≥ 5 menit yang dihitung lubang.');
        $this->assertSame(30 * 60, $row['gaps'][0]['seconds']);
        $this->assertSame('15:30', $row['gaps'][0]['start']->format('H:i'));
        $this->assertSame('16:00', $row['gaps'][0]['end']->format('H:i'));
        $this->assertSame(30 * 60, $row['gap_seconds']);
    }

    // ==========================================================
    // DI LUAR JAM KERJA
    // ==========================================================

    public function test_kerja_di_luar_jam_dipisah_dan_tidak_menaikkan_terpakai(): void
    {
        // Mesin ditinggal jalan sampai 17:00 — lewat 1 jam dari jam pulang.
        $this->step([['12:30', '17:00']], [$this->mesin1Id]);

        $row = $this->row('Mesin 1');

        $this->assertSame(3600 * 7 / 2, $row['busy_seconds'], 'Yang dihitung terpakai hanya bagian di dalam shift.');
        $this->assertSame(3600, $row['outside_seconds'], 'Satu jam lewat jam pulang harus dilaporkan terpisah.');
        $this->assertLessThanOrEqual(100.0, $row['utilization'], 'Utilisasi tidak boleh lewat 100% gara-gara jam di luar shift.');

        // Jendela tampilan wajib melebar supaya jam 16–17 itu terlihat, bukan terpotong sunyi.
        $this->assertSame('17:00', $this->build()['window']['end']->format('H:i'));
    }

    // ==========================================================
    // LINTAS HARI
    // ==========================================================

    public function test_langkah_lintas_hari_dipotong_ke_hari_yang_dibuka(): void
    {
        $this->step([['2026-08-10 14:00', '2026-08-11 09:00']], [$this->mesin1Id]);

        // Hari yang dibuka hanya menampilkan porsinya sendiri: 00:00–09:00.
        $row = $this->row('Mesin 1');
        $this->assertSame('00:00', $row['blocks'][0]['start']->format('H:i'));
        $this->assertSame('09:00', $row['blocks'][0]['end']->format('H:i'));
        $this->assertTrue($row['blocks'][0]['from_yesterday']);
        $this->assertSame(3600, $row['busy_seconds'], 'Hanya 08:00–09:00 yang jatuh di dalam shift.');

        // Hari sebelumnya menampilkan sisanya, tanpa dobel.
        $kemarin = $this->row('Mesin 1', '2026-08-10');
        $this->assertSame('14:00', $kemarin['blocks'][0]['start']->format('H:i'));
        $this->assertTrue($kemarin['blocks'][0]['into_tomorrow']);
        $this->assertSame(2 * 3600, $kemarin['busy_seconds'], '14:00–16:00 saja yang di dalam shift.');
    }

    // ==========================================================
    // SLOT KAPASITAS
    // ==========================================================

    public function test_operator_penaung_tidak_punya_baris_sama_sekali(): void
    {
        // Catatan lama masih mencantumkan Andi bersama mesinnya.
        $this->step([['08:00', '11:30']], [$this->andiId, $this->mesin1Id]);

        $dept  = $this->build()['departments'][0];
        $nama  = array_column($dept['rows'], 'name');

        $this->assertNotContains('Andi', $nama, 'Operator penaung tidak boleh punya baris — yang bekerja mesinnya.');
        $this->assertSame(1, $this->row('Mesin 1')['block_count']);

        // Kapasitas hanya dari 2 mesin, dan jamnya dihitung sekali.
        $this->assertSame(2, $dept['slot_count']);
        $this->assertSame(2 * 7 * 3600, $dept['capacity_seconds']);
        $this->assertSame(3.5 * 3600, (float) $dept['busy_seconds'], 'Jam Andi tidak boleh menambah jam mesin yang ditungguinya.');
        $this->assertSame(0, $dept['orphan_steps'], 'Mesinnya sudah jelas, jadi bukan yatim.');
    }

    public function test_langkah_lama_atas_nama_operator_jatuh_ke_tanpa_eksekutor_bukan_menguap(): void
    {
        // Catatan lama: Andi tanpa mesin mana pun. Mesinnya jelas jalan, tapi datanya tidak
        // bilang mesin yang mana — tidak boleh ditebak, dan tidak boleh hilang begitu saja.
        $this->step([['08:00', '11:30']], [$this->andiId]);

        $dept = $this->build()['departments'][0];

        $this->assertContains('Tanpa eksekutor', array_column($dept['rows'], 'name'));
        $this->assertSame(3.5 * 3600, (float) $this->row('Tanpa eksekutor')['blocks'][0]['seconds']);

        $this->assertSame(1, $dept['orphan_steps']);
        $this->assertSame(3.5 * 3600, (float) $dept['orphan_seconds']);
        $this->assertSame(0, $dept['busy_seconds'], 'Mesin yang tidak diketahui tidak boleh diam-diam mengisi kapasitas.');
    }

    // ==========================================================
    // TANGGAL MERAH & KETIDAKHADIRAN
    // ==========================================================

    public function test_libur_nasional_membuat_kapasitas_nol_dan_kerjanya_masuk_di_luar_jam(): void
    {
        DB::table('sdm_national_holidays')->insert([
            'tanggal' => self::HARI, 'nama' => 'Hari Kemerdekaan RI', 'is_cuti_bersama' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Ada yang tetap masuk dan mengerjakan sesuatu di hari libur.
        $this->step([['08:00', '11:30']], [$this->mesin1Id]);

        $data = $this->build();
        $dept = $data['departments'][0];

        $this->assertNotNull($data['holiday']);
        $this->assertSame('Hari Kemerdekaan RI', $data['holiday']->nama);

        // Pabrik tidak dijadwalkan buka → tidak ada kapasitas, jadi tidak ada yang bisa hilang.
        $this->assertSame(0, $dept['capacity_seconds']);
        $this->assertSame(0, $dept['gap_seconds'], 'Tanggal merah tidak boleh dihitung sebagai kapasitas hilang.');
        $this->assertSame(0, $dept['busy_seconds']);

        // Kerjanya tetap terlihat, dilaporkan sebagai di luar jam.
        $this->assertSame(3.5 * 3600, (float) $this->row('Mesin 1')['outside_seconds']);
        $this->assertNull($this->row('Mesin 1')['utilization'], 'Utilisasi tanpa kapasitas tidak punya arti — jangan dipaksa jadi angka.');
    }

    public function test_cuti_operator_menempel_ke_mesin_yang_ditungguinya(): void
    {
        DB::table('sdm_attendance_overrides')->insert([
            'karyawan_id' => $this->andiKaryawanId,
            'tanggal'     => self::HARI,
            'type'        => 'cuti',
            'notes'       => 'berduka',
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $row = $this->row('Mesin 1');

        $this->assertSame('Cuti', $row['absence']['label'], 'Mesin ikut alasan operator yang menungguinya.');
        $this->assertSame('berduka', $row['absence']['note']);

        // Kapasitas TETAP dihitung: cuti seorang operator tidak menghentikan sewa dan gaji,
        // jadi jam mesin yang menganggur karenanya memang biaya kapasitas menganggur.
        $this->assertSame(7 * 3600, $row['shift_seconds']);
        $this->assertSame(7 * 3600, $row['gap_seconds']);
    }

    public function test_tukar_hari_memindahkan_kapasitas_bukan_menghapusnya(): void
    {
        // Selasa 11 Agu (hari kerja) ditukar dengan Minggu 16 Agu (hari libur).
        DB::table('sdm_attendance_overrides')->insert([
            'karyawan_id' => $this->andiKaryawanId,
            'tanggal'     => self::HARI,
            'paired_date' => '2026-08-16',
            'type'        => 'tukar_hari',
            'notes'       => 'ganti hari',
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        // Hari kerja yang DILEPAS: tidak ada kapasitas, jadi tidak ada lubang — tapi tetap
        // diberi keterangan, kalau tidak, hari kerja yang tiba-tiba nol terbaca seperti data hilang.
        $lepas = $this->row('Mesin 1');
        $this->assertTrue($lepas['shift']['is_off']);
        $this->assertSame(0, $lepas['shift_seconds']);
        $this->assertSame(0, $lepas['gap_seconds'], 'Hari yang ditukar keluar tidak boleh dihitung kapasitas hilang.');
        $this->assertSame('Tukar hari', $lepas['absence']['label']);

        // Hari libur yang DIPAKAI bekerja: kapasitasnya muncul di sana, meminjam jam hari kerja.
        $pakai = $this->row('Mesin 1', '2026-08-16');
        $this->assertFalse($pakai['shift']['is_off']);
        $this->assertSame(7 * 3600, $pakai['shift_seconds'], 'Kapasitasnya pindah, bukan hilang.');
        $this->assertSame('08:00', $pakai['shift']['start']->format('H:i'));
    }

    // ==========================================================
    // HENTI MESIN
    // ==========================================================

    public function test_henti_mesin_yang_dicatat_berhenti_dihitung_sebagai_lubang(): void
    {
        // Mesin dirawat sepanjang sesi pagi, sore baru produksi.
        $this->step([['12:30', '16:00']], [$this->mesin1Id]);
        DB::table('production_machine_downtimes')->insert([
            'executor_id' => $this->mesin1Id,
            'started_at'  => self::HARI . ' 08:00:00',
            'ended_at'    => self::HARI . ' 11:30:00',
            'reason'      => 'perawatan',
            'notes'       => 'servis rutin',
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $row = $this->row('Mesin 1');

        $this->assertSame(3.5 * 3600, (float) $row['downtime_seconds']);
        $this->assertSame(0, $row['gap_seconds'], 'Lubang yang sudah punya nama bukan lubang lagi.');
        $this->assertSame(3.5 * 3600, (float) $row['busy_seconds'], 'Henti mesin tidak boleh dihitung sebagai waktu terpakai.');

        // Kapasitas TIDAK berkurang: mesin yang dirawat tetap menanggung sewa & penyusutan.
        $this->assertSame(7 * 3600, $row['shift_seconds']);
        $this->assertEqualsWithDelta(50.0, $row['utilization'], 0.01);
    }

    public function test_henti_mesin_di_luar_jam_kerja_tidak_mengurangi_lubang(): void
    {
        // Dirawat malam hari — tidak ada kapasitas yang terpakai maupun terselamatkan.
        DB::table('production_machine_downtimes')->insert([
            'executor_id' => $this->mesin1Id,
            'started_at'  => self::HARI . ' 18:00:00',
            'ended_at'    => self::HARI . ' 20:00:00',
            'reason'      => 'perawatan',
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $row = $this->row('Mesin 1');

        $this->assertSame(0, $row['downtime_seconds'], 'Yang dihitung hanya bagian di dalam jam kerja.');
        $this->assertSame(7 * 3600, $row['gap_seconds'], 'Jam kerjanya tetap kosong — perawatan malam tidak menjelaskannya.');
    }

    // ==========================================================
    // TIMER MENGGANTUNG
    // ==========================================================

    public function test_timer_yang_belum_ditutup_ditandai_dan_tetap_berjalan(): void
    {
        // Mulai kemarin, tidak pernah ada log penutup, langkahnya masih in_progress.
        $stepId = $this->step([], [$this->mesin1Id], 'in_progress');
        DB::table('production_step_time_logs')->insert([
            'production_order_step_id' => $stepId,
            'event_type'               => 'started',
            'occurred_at'              => '2026-08-10 14:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_order_steps')->where('id', $stepId)->update(['started_at' => '2026-08-10 14:00:00']);

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

        try {
            $row = $this->row('Mesin 1');

            $this->assertTrue($row['has_open_timer'], 'Timer menggantung wajib ditandai, bukan dibersihkan diam-diam.');
            $this->assertTrue($row['blocks'][0]['still_open']);
            // Hari itu terisi penuh dari 00:00 sampai 23:59:59 — dan itu memang yang mau dilihat.
            $this->assertSame(7 * 3600, $row['busy_seconds']);
            $this->assertSame(0, $row['gap_seconds']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==========================================================
    // Bantuan
    // ==========================================================

    private function build(?string $date = null): array
    {
        return $this->svc->build(Carbon::parse($date ?? self::HARI));
    }

    private function row(string $name, ?string $date = null): array
    {
        foreach ($this->build($date)['departments'] as $dept) {
            foreach ($dept['rows'] as $row) {
                if ($row['name'] === $name) {
                    return $row;
                }
            }
        }

        $this->fail("Baris \"{$name}\" tidak ada di kalender.");
    }

    private function executor(string $name, ?int $karyawanId, ?int $parentId): int
    {
        return DB::table('production_department_executors')->insertGetId([
            'department_id'      => $this->cncId,
            'name'               => $name,
            'karyawan_id'        => $karyawanId,
            'parent_executor_id' => $parentId,
            'is_active'          => 1,
            'created_at'         => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Satu langkah dengan sederet interval "jalan → jeda".
     *
     * @param  array<int,array{0:string,1:string}>  $intervals  jam saja ('08:00') atau tanggal+jam penuh
     * @param  array<int,int>                       $executorIds
     */
    private function step(array $intervals, array $executorIds, string $status = 'completed'): int
    {
        static $n = 0;
        $n++;

        $stepId = DB::table('production_order_steps')->insertGetId([
            'production_order_id' => $this->orderId,
            'step_number'         => $n,
            'name'                => 'CNC',
            'department_id'       => $this->cncId,
            'status'              => $status,
            'started_at'          => $intervals ? $this->moment($intervals[0][0]) : null,
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        foreach ($executorIds as $eid) {
            DB::table('production_order_step_executors')->insert([
                'step_id' => $stepId, 'executor_id' => $eid, 'joined_at' => now(),
            ]);
        }

        foreach ($intervals as $i => [$from, $to]) {
            DB::table('production_step_time_logs')->insert([
                [
                    'production_order_step_id' => $stepId,
                    'event_type'               => $i === 0 ? 'started' : 'auto_resumed',
                    'occurred_at'              => $this->moment($from),
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'production_order_step_id' => $stepId,
                    'event_type'               => 'auto_paused',
                    'occurred_at'              => $this->moment($to),
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);
        }

        return $stepId;
    }

    private function moment(string $raw): string
    {
        return str_contains($raw, '-')
            ? Carbon::parse($raw)->toDateTimeString()
            : Carbon::parse(self::HARI . ' ' . $raw)->toDateTimeString();
    }
}
