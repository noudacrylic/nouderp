<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Models\ProductionCostComponent;
use App\Modules\Analysis\Models\ProductionQuotaExcludedDate;
use App\Modules\Analysis\Models\ProductionQuotaSlot;
use App\Modules\Analysis\Services\ProductionQuotaService;
use App\Modules\Production\Models\Department;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Invarian halaman Kuota Produksi.
 *
 * Yang dijaga di sini adalah hal-hal yang kalau salah tidak akan terlihat salah:
 *
 *  1. **Slot = eksekutor DAUN.** Operator penaung bukan slot, ia biaya. Kalau Andi ikut jadi
 *     baris, kapasitas CNC mengarang satu slot yang tidak ada.
 *  2. **Pembagi = jam TERSEDIA, bukan terpakai.** Ini keputusan sadar: jam yang dibayar tetap
 *     dibayar walau menganggur. Kalau suatu hari diam-diam berubah jadi "terpakai", HPP akan
 *     naik-turun ikut sepi-ramainya bulan tanpa ada yang sadar kenapa.
 *  3. **Asumsi hanya berlaku bila dicentang**, dan angka nyata tidak pernah ditimpa.
 *  4. **Hari rusak & hari tercemar dibuang, tapi dilaporkan.** Membuang hari diam-diam adalah
 *     cara termudah membuat utilisasi terlihat bagus tanpa bisa diperiksa lagi.
 */
class ProductionQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Selasa — hari kerja. Jendela pengamatan sengaja dibuat satu hari ini saja. */
    private const HARI = '2026-08-11';
    private const HARI_KERJA = 24;

    private Department $cnc;
    private int $mesin1;
    private int $mesin2;
    private int $andi;
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00'));

        $this->cnc = Department::create(['code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);

        $karyawanId  = $this->karyawan('Andi');
        $this->andi  = $this->executor('Andi', $karyawanId, null);
        $this->mesin1 = $this->executor('Mesin 1', null, $this->andi);
        $this->mesin2 = $this->executor('Mesin 2', null, $this->andi);

        $this->orderId = DB::table('production_orders')->insertGetId([
            'order_number' => 'OP/TEST/0001', 'type' => 'ready_stock', 'warehouse_id' => 1,
            'production_date' => self::HARI, 'status' => 'in_progress', 'planned_cycles' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ==========================================================
    // SLOT
    // ==========================================================

    public function test_operator_penaung_tidak_jadi_slot(): void
    {
        $nama = array_column($this->build()['slots'], 'name');

        $this->assertSame(['Mesin 1', 'Mesin 2'], $nama, 'Andi menunggui mesin — dia biaya, bukan kapasitas.');
    }

    public function test_jam_tersedia_dan_terpakai_dibaca_dari_kalender(): void
    {
        $this->kerja($this->mesin1, '08:00', '11:30');   // 3,5 jam dari 7 jam

        $slots = collect($this->build()['slots'])->keyBy('name');

        $this->assertEqualsWithDelta(7.0, $slots['Mesin 1']['hours_per_day_real'], 0.01);
        $this->assertEqualsWithDelta(3.5, $slots['Mesin 1']['used_per_day'], 0.01);
        $this->assertEqualsWithDelta(50.0, $slots['Mesin 1']['utilization'], 0.1);

        $this->assertEqualsWithDelta(0.0, $slots['Mesin 2']['used_per_day'], 0.01);
        $this->assertEqualsWithDelta(0.0, $slots['Mesin 2']['utilization'], 0.1);
    }

    // ==========================================================
    // PEMBAGI
    // ==========================================================

    public function test_kapasitas_memakai_jam_tersedia_bukan_terpakai(): void
    {
        $this->kerja($this->mesin1, '08:00', '11:30');

        $tot = $this->build()['totals'];

        // 2 slot × 7 jam × 24 hari = 336 slot-jam tersedia, apa pun yang terjadi hari itu.
        $this->assertEqualsWithDelta(2 * 7 * self::HARI_KERJA, $tot['available_month'], 0.1);
        $this->assertEqualsWithDelta(3.5 * self::HARI_KERJA, $tot['used_month'], 0.1);
        $this->assertEqualsWithDelta(25.0, $tot['utilization'], 0.1);
    }

    public function test_tarif_dibagi_kapasitas_dan_sisanya_dilaporkan_sebagai_tidak_terserap(): void
    {
        $this->kerja($this->mesin1, '08:00', '11:30');
        ProductionCostComponent::create([
            'group_key' => 'non_produksi', 'name' => 'Sewa Gedung', 'source' => 'manual',
            'amount_monthly' => 3_360_000, 'is_active' => true,
        ]);

        $d    = $this->build();
        $cost = $d['cost'];

        $this->assertEqualsWithDelta($cost['fixed_total'] / $d['totals']['available_month'], $cost['rate_per_slot_hour'], 0.01);
        $this->assertEqualsWithDelta($cost['rate_per_slot_hour'] * $d['totals']['used_month'], $cost['absorbed'], 0.01);
        $this->assertEqualsWithDelta($cost['fixed_total'] - $cost['absorbed'], $cost['unabsorbed'], 0.01);

        // Utilisasi 25% → tiga perempat biaya tidak terserap. Itu memang maunya.
        $this->assertEqualsWithDelta(75.0, $cost['unabsorbed_percent'], 0.1);
    }

    // ==========================================================
    // ASUMSI
    // ==========================================================

    public function test_asumsi_jam_diabaikan_selama_belum_dicentang(): void
    {
        ProductionQuotaSlot::create([
            'executor_id' => $this->mesin1, 'department_id' => $this->cnc->id,
            'assumed_hours_per_day' => 12, 'use_assumption' => false,
        ]);

        $slot = collect($this->build()['slots'])->firstWhere('name', 'Mesin 1');

        $this->assertEqualsWithDelta(7.0, $slot['hours_per_day'], 0.01, 'Tanpa centang, jam nyatanya yang dipakai.');
        $this->assertEqualsWithDelta(12.0, $slot['assumed_hours_per_day'], 0.01, 'Tapi angkanya tetap tersimpan.');
    }

    public function test_asumsi_jam_yang_dicentang_mengubah_kapasitas_tanpa_menghapus_angka_nyata(): void
    {
        // "Kalau Mesin 1 dijalankan 10 jam, 26 hari?"
        ProductionQuotaSlot::create([
            'executor_id' => $this->mesin1, 'department_id' => $this->cnc->id,
            'assumed_hours_per_day' => 10, 'assumed_working_days' => 26, 'use_assumption' => true,
        ]);

        $d    = $this->build();
        $slot = collect($d['slots'])->firstWhere('name', 'Mesin 1');

        $this->assertEqualsWithDelta(260.0, $slot['available_month'], 0.1, '10 jam × 26 hari.');
        $this->assertEqualsWithDelta(7.0, $slot['hours_per_day_real'], 0.01, 'Angka nyata tetap utuh di sebelahnya.');

        // Mesin 2 tidak ikut berubah: asumsinya per slot, bukan per divisi.
        $this->assertEqualsWithDelta(7 * self::HARI_KERJA, collect($d['slots'])->firstWhere('name', 'Mesin 2')['available_month'], 0.1);
        $this->assertEqualsWithDelta(260 + 7 * self::HARI_KERJA, $d['totals']['available_month'], 0.1);
    }

    public function test_slot_pengandaian_menambah_kapasitas_tanpa_menambah_pemakaian(): void
    {
        $this->kerja($this->mesin1, '08:00', '11:30');

        ProductionQuotaSlot::create([
            'executor_id' => null, 'department_id' => $this->cnc->id, 'label' => 'Mesin 4',
            'assumed_hours_per_day' => 7, 'assumed_working_days' => 24, 'use_assumption' => true,
        ]);

        $d = $this->build();

        $this->assertEqualsWithDelta(3 * 7 * self::HARI_KERJA, $d['totals']['available_month'], 0.1, 'Mesin keempat menambah 168 slot-jam.');
        $this->assertEqualsWithDelta(3.5 * self::HARI_KERJA, $d['totals']['used_month'], 0.1, 'Tapi mesin yang belum ada tidak pernah mengerjakan apa pun.');

        // Menambah mesin menurunkan tarif — itulah gunanya pengandaian ini.
        $this->assertTrue((bool) collect($d['slots'])->firstWhere('name', 'Mesin 4')['is_virtual']);
    }

    // ==========================================================
    // HARI YANG DIBUANG
    // ==========================================================

    public function test_hari_yang_dikecualikan_tidak_ikut_merata_rata_dan_tetap_dilaporkan(): void
    {
        $this->kerja($this->mesin1, '08:00', '11:30');
        ProductionQuotaExcludedDate::create(['tanggal' => self::HARI, 'reason' => 'produksi jalan tapi tidak terekam']);

        $d = $this->build();

        $this->assertSame(0, $d['window']['days'], 'Satu-satunya hari di jendela dibuang, jadi tidak ada yang diamati.');
        $this->assertCount(1, $d['excluded']);
        $this->assertSame('produksi jalan tapi tidak terekam', $d['excluded'][0]['reason'],
            'Alasannya ikut tampil — membuang hari tanpa keterangan tidak bisa diperiksa lagi nanti.');
    }

    public function test_hari_dengan_timer_menggantung_dibuang_otomatis_dan_dilaporkan(): void
    {
        // Timer dimulai lalu tidak pernah ditutup: bloknya membentang sepanjang hari dan akan
        // membuat slot yang menganggur terbaca sibuk penuh.
        $stepId = $this->step($this->mesin1, 'in_progress');
        $this->log($stepId, 'started', self::HARI . ' 08:00:00');
        DB::table('production_order_steps')->where('id', $stepId)->update(['started_at' => self::HARI . ' 08:00:00']);

        $d = $this->build();

        $this->assertSame(0, $d['window']['days']);
        $this->assertCount(1, $d['contaminated']);
        $this->assertContains('Mesin 1', $d['contaminated'][0]['slots']);
    }

    // ==========================================================
    // Bantuan
    // ==========================================================

    /** Jendela sengaja satu hari supaya angkanya bisa dihitung di kepala. */
    private function build(): array
    {
        return app(ProductionQuotaService::class)->build(['to' => self::HARI, 'window_days' => 1]);
    }

    private function kerja(int $executorId, string $dari, string $sampai): void
    {
        $stepId = $this->step($executorId, 'completed');
        $this->log($stepId, 'started', self::HARI . ' ' . $dari . ':00');
        $this->log($stepId, 'completed', self::HARI . ' ' . $sampai . ':00');
        DB::table('production_order_steps')->where('id', $stepId)->update(['started_at' => self::HARI . ' ' . $dari . ':00']);
    }

    private function step(int $executorId, string $status): int
    {
        static $n = 0;
        $n++;

        $stepId = DB::table('production_order_steps')->insertGetId([
            'production_order_id' => $this->orderId, 'step_number' => $n, 'name' => 'CNC',
            'department_id' => $this->cnc->id, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('production_order_step_executors')->insert([
            'step_id' => $stepId, 'executor_id' => $executorId, 'joined_at' => now(),
        ]);

        return $stepId;
    }

    private function log(int $stepId, string $type, string $at): void
    {
        DB::table('production_step_time_logs')->insert([
            'production_order_step_id' => $stepId, 'event_type' => $type, 'occurred_at' => $at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function karyawan(string $nama): int
    {
        $id = DB::table('sdm_karyawan')->insertGetId([
            'staf_code' => 'K-' . $nama, 'name' => $nama, 'department_id' => $this->cnc->id,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 08:00–16:00 dipotong istirahat 1 jam = 7 jam bersih, Senin–Sabtu.
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            DB::table('sdm_karyawan_schedule')->insert([
                'karyawan_id' => $id, 'day_of_week' => $day,
                'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00',
                'jam_istirahat_start' => '11:30:00', 'jam_istirahat_end' => '12:30:00',
                'is_off' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('sdm_karyawan_schedule')->insert([
            'karyawan_id' => $id, 'day_of_week' => 0, 'is_off' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $periodeId = DB::table('sdm_periode_penggajian')->insertGetId([
            'code' => 'PG-TEST', 'bulan' => 8, 'tahun' => 2026, 'label' => 'Agustus 2026',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'finalized',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sdm_slip_gaji')->insert([
            'periode_id' => $periodeId, 'karyawan_id' => $id, 'code' => 'SLIP-' . $nama,
            'total_gaji' => 3_000_000, 'hari_kerja_periode' => self::HARI_KERJA,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function executor(string $nama, ?int $karyawanId, ?int $parentId): int
    {
        return DB::table('production_department_executors')->insertGetId([
            'department_id' => $this->cnc->id, 'name' => $nama,
            'karyawan_id' => $karyawanId, 'parent_executor_id' => $parentId,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
