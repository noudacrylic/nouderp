<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\JedaMesinService;
use App\Modules\SDM\Models\FingerprintLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penilaian operator CNC Tahap 0 (24 Nov 2026): mesin diam di jam kerja operator padahal ada
 * OP CNC yang siap dikerjakan.
 */
class JedaMesinTest extends TestCase
{
    use RefreshDatabase;

    private const HARI = '2026-11-18';

    private int $gudang;
    private Department $cnc;
    private DepartmentExecutor $andi;
    private DepartmentExecutor $mesin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::HARI . ' 17:00');

        $karyawan = DB::table('sdm_karyawan')->insertGetId(['staf_code' => 'K-005', 'name' => 'Andi',
            'user_id_fingerprint' => '5', 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(0, 6) as $dow) {
            DB::table('sdm_karyawan_schedule')->insert(['karyawan_id' => $karyawan, 'day_of_week' => $dow,
                'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00', 'jam_istirahat_start' => '12:00:00', 'jam_istirahat_end' => '13:00:00',
                'awal_absen_pulang' => 120, 'akhir_absen_pulang' => 15, 'has_lembur' => 0, 'is_off' => 0,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $mesinAbsen = DB::table('sdm_fingerprint_machines')->insertGetId(['code' => 'FP-01', 'name' => 'Absen', 'created_at' => now(), 'updated_at' => now()]);
        FingerprintLog::create(['machine_id' => $mesinAbsen, 'karyawan_id' => $karyawan, 'user_id_fingerprint' => '5',
            'scan_at' => self::HARI . ' 07:50:00', 'verify_type' => 'check_in']);

        $this->gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->andi = DepartmentExecutor::create(['department_id' => $this->cnc->id, 'name' => 'Andi', 'karyawan_id' => $karyawan, 'is_active' => true]);
        $this->mesin = DepartmentExecutor::create(['department_id' => $this->cnc->id, 'name' => 'Mesin A', 'parent_executor_id' => $this->andi->id, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function op(string $no, string $dibuat, ?string $mulai, ?string $selesai, string $status = 'in_progress'): void
    {
        $op = ProductionOrder::create(['order_number' => $no, 'type' => 'ready_stock', 'warehouse_id' => $this->gudang,
            'planned_cycles' => 1, 'planned_qty' => 1, 'production_date' => self::HARI, 'status' => $status]);
        $op->forceFill(['created_at' => Carbon::parse($dibuat)])->save();
        $s = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => $this->cnc->id,
            'name' => 'Potong', 'status' => $selesai ? 'completed' : ($mulai ? 'in_progress' : 'pending'),
            'started_at' => $mulai ? self::HARI . " $mulai" : null, 'completed_at' => $selesai ? self::HARI . " $selesai" : null]);
        if ($mulai) $s->executors()->attach($this->mesin->id);
    }

    private function skenario(): void
    {
        $this->op('OP-1', '2026-11-17 09:00', '08:00', '10:00');
        $this->op('OP-2', '2026-11-17 09:00', '10:30', '11:30');            // menunggu 10:00–10:30
        $this->op('OP-3', self::HARI . ' 11:00', '13:30', '14:00');         // siap 11:00; mesin diam 11:30–13:30 (istirahat 12–13)
        $this->op('OP-4', '2026-11-17 09:00', null, null, 'draft');          // draft bukan antrean
    }

    public function test_diam_saat_ada_antrean_dihitung_di_jam_kerja_saja(): void
    {
        $this->skenario();
        $h = app(JedaMesinService::class)->bulan($this->andi, Carbon::parse('2026-11-01'));

        $this->assertSame(420, $h['jam_kerja'], '08–12 + 13–16, hanya hari ada scan masuk.');
        $m = $h['mesin'][0];
        $this->assertSame([210, 90, 120, 3], [$m['jalan'], $m['diam_antrean'], $m['diam_kosong'], $m['langkah']]);

        $this->assertCount(2, $m['jeda']);
        [$baru, $lama] = $m['jeda'];
        $this->assertSame(['11:30', '13:30', 60, 'OP-2', 'OP-3'],
            [$baru['dari']->format('H:i'), $baru['sampai']->format('H:i'), $baru['menit'], $baru['sebelum']['op'], $baru['sesudah']['op']]);
        $this->assertSame(['OP-3'], array_column($baru['menunggu'], 'op'), 'Diam mulai dihitung saat OP-3 siap.');
        $this->assertSame([30, 'OP-1', ['OP-2']], [$lama['menit'], $lama['sebelum']['op'], array_column($lama['menunggu'], 'op')]);
    }

    public function test_waktu_tanggap_dari_sinyal_mesin(): void
    {
        $this->skenario();
        $baris = fn ($k, $a, $b) => DB::table('production_mesin_keadaan')->insert(['executor_id' => $this->mesin->id, 'keadaan' => $k,
            'mulai_at' => self::HARI . " $a", 'sampai_at' => $b ? self::HARI . " $b" : null, 'dilihat_at' => self::HARI . ' ' . ($b ?? '16:30:00')]);
        $baris('potong', '08:00:00', '09:00:00');
        $baris('diam',   '09:00:00', '09:03:00');   // tanggap 3 mnt
        $baris('potong', '09:03:00', '09:30:00');
        $baris('diam',   '09:30:00', '09:30:10');   // kedip 10 dtk → satu pekerjaan
        $baris('potong', '09:30:10', '11:58:00');
        $baris('diam',   '11:58:00', '13:02:00');   // melewati istirahat: dihitung 2 + 2 mnt
        $baris('potong', '13:02:00', '14:00:00');
        $baris('diam',   '14:00:00', null);         // masih diam

        $s = app(JedaMesinService::class)->bulan($this->andi, Carbon::parse('2026-11-01'))['mesin'][0]['sinyal'];
        $this->assertSame([2, 180, 240, 210], [$s['n'], $s['median'], $s['tanggap'][0]['detik'], $s['rata']]);
        $this->assertSame('11:58:00', $s['tanggap'][0]['selesai']->format('H:i:s'));
        $this->assertSame(['diam', true], [$s['terakhir']['keadaan'], $s['terakhir']['berjalan']]);
        $this->assertSame(60 + 175 + 58, $s['potong'], 'Memotong di jam kerja: 08–09, 09:03–11:58 (kedip digabung), 13:02–14.');
    }

    public function test_halaman_khusus_owner(): void
    {
        $this->skenario();
        foreach ([['potong', '08:00:00', '09:00:00'], ['diam', '09:00:00', '09:03:00'], ['potong', '09:03:00', null]] as [$k, $a, $b]) {
            DB::table('production_mesin_keadaan')->insert(['executor_id' => $this->mesin->id, 'keadaan' => $k,
                'mulai_at' => self::HARI . " $a", 'sampai_at' => $b ? self::HARI . " $b" : null, 'dilihat_at' => self::HARI . ' ' . ($b ?? '16:59:00')]);
        }
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($owner)->get(route('production.waktu-standar.cnc', ['bulan' => '2026-11']))
            ->assertOk()
            ->assertSee('Penilaian Operator CNC')
            ->assertSee('Diam antar-OP saat ada antrean — 2 kali')
            ->assertSee('OP-3')
            ->assertSee('tengah <b>3 mnt </b>', false)
            ->assertSee('Memotong</span> sejak 18/11 09:03', false);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('production.waktu-standar.cnc'))->assertForbidden();
    }

    public function test_aritmetika_interval(): void
    {
        $this->assertSame([[0, 10], [20, 30]], JedaMesinService::selisih([[0, 30]], [[10, 20]]));
        $this->assertSame([[5, 10]], JedaMesinService::irisan([[0, 10]], [[5, 15]]));
        $this->assertSame([[0, 15]], JedaMesinService::gabung([[5, 15], [0, 6]]));
    }
}
