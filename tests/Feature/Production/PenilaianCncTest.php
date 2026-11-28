<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\PenilaianCncService;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penilaian operator CNC (disetujui owner 28 Nov 2026): tanggap ≤3/3–8/>8 mnt = 2/1/0 poin,
 * aturan antre, >30 mnt ke Meja Owner, pencatatan ≥95% sebagai syarat.
 */
class PenilaianCncTest extends TestCase
{
    use RefreshDatabase;

    private const HARI = '2026-11-18';

    private DepartmentExecutor $andi;
    private DepartmentExecutor $a;
    private DepartmentExecutor $b;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::HARI . ' 17:00');

        $karyawan = DB::table('sdm_karyawan')->insertGetId(['staf_code' => 'K-005', 'name' => 'Andi',
            'user_id_fingerprint' => '5', 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(0, 6) as $dow) {
            DB::table('sdm_karyawan_schedule')->insert(['karyawan_id' => $karyawan, 'day_of_week' => $dow,
                'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00', 'jam_istirahat_start' => '12:00:00', 'jam_istirahat_end' => '13:00:00',
                'awal_absen_pulang' => 120, 'akhir_absen_pulang' => 15, 'has_lembur' => 0, 'is_off' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $absen = DB::table('sdm_fingerprint_machines')->insertGetId(['code' => 'FP-01', 'name' => 'Absen', 'created_at' => now(), 'updated_at' => now()]);
        FingerprintLog::create(['machine_id' => $absen, 'karyawan_id' => $karyawan, 'user_id_fingerprint' => '5',
            'scan_at' => self::HARI . ' 07:50:00', 'verify_type' => 'check_in']);

        $cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->andi = DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => 'Andi', 'karyawan_id' => $karyawan, 'is_active' => true]);
        $this->a = DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => 'Mesin A', 'parent_executor_id' => $this->andi->id,
            'is_active' => true, 'mesin_ip' => '192.168.1.91', 'mesin_protokol' => 'udp']);
        $this->b = DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => 'Mesin B', 'parent_executor_id' => $this->andi->id,
            'is_active' => true, 'mesin_ip' => '192.168.1.92', 'mesin_protokol' => 'udp']);

        $gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        // Langkah OP di ERP: A 08–16, B 08–14 (B memotong 14–15 tanpa langkah → pencatatan bolong).
        foreach ([[$this->a, 'OP-A', '08:00', null], [$this->b, 'OP-B', '08:00', '14:00']] as [$m, $no, $mulai, $selesai]) {
            $op = ProductionOrder::create(['order_number' => $no, 'type' => 'ready_stock', 'warehouse_id' => $gudang,
                'planned_cycles' => 1, 'planned_qty' => 1, 'production_date' => self::HARI, 'status' => 'in_progress']);
            $op->forceFill(['created_at' => Carbon::parse('2026-11-17 09:00')])->save();
            $s = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => $cnc->id,
                'name' => 'Potong', 'status' => $selesai ? 'completed' : 'in_progress',
                'started_at' => self::HARI . " $mulai", 'completed_at' => $selesai ? self::HARI . " $selesai" : null]);
            $s->executors()->attach($m->id);
        }

        $sinyal = function (DepartmentExecutor $m, array $potongan) {
            foreach ($potongan as [$k, $a, $b]) {
                DB::table('production_mesin_keadaan')->insert(['executor_id' => $m->id, 'keadaan' => $k,
                    'mulai_at' => self::HARI . " $a", 'sampai_at' => $b ? self::HARI . " $b" : null, 'dilihat_at' => self::HARI . ' ' . ($b ?? '16:59:50')]);
            }
        };
        $sinyal($this->a, [
            ['potong', '08:00:00', '09:00:00'], ['diam', '09:00:00', '09:02:00'],   // 2 mnt → 2 poin
            ['potong', '09:02:00', '10:00:00'], ['diam', '10:00:00', '10:10:00'],   // 10 mnt, tapi B selesai 09:58 & jalan 10:05 → 5 mnt → 1 poin
            ['potong', '10:10:00', '14:00:00'], ['diam', '14:00:00', '14:40:00'],   // 40 mnt → ditahan
            ['potong', '14:40:00', '15:30:00'], ['diam', '15:30:00', null],
        ]);
        $sinyal($this->b, [
            ['potong', '08:00:00', '09:58:00'], ['diam', '09:58:00', '10:05:00'],   // 7 mnt → 1 poin
            ['potong', '10:05:00', '15:00:00'], ['diam', '15:00:00', null],
        ]);

        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nilai(): array
    {
        return app(PenilaianCncService::class)->bulan($this->andi, Carbon::parse('2026-11-01'));
    }

    public function test_poin_aturan_antre_dan_syarat_pencatatan(): void
    {
        $h = $this->nilai();
        $k = $h['kejadian']->sortBy(fn ($x) => $x['selesai']->timestamp)->values();
        $this->assertSame(
            [['Mesin A', 120, 2, 'dinilai', 0], ['Mesin B', 420, 1, 'dinilai', 0], ['Mesin A', 300, 1, 'dinilai', 300], ['Mesin A', 2400, 0, 'cek', 0]],
            $k->map(fn ($x) => [$x['mesin'], $x['detik'], $x['poin'], $x['status'], $x['antre']])->all());

        // Pencatatan: A 398 mnt memotong semua tercatat; B 413 mnt, 60 mnt (14–15) bolong.
        $this->assertEqualsWithDelta(751 / 811, $h['pencatatan']['persen'], 0.0001);
        $faktor = (751 / 811) / 0.95;
        $this->assertSame([3, 1, 1.33], [$h['nilai']['n'], $h['nilai']['ditahan'], $h['nilai']['rata']]);
        $this->assertSame(round((4 / 3) * $faktor, 2), $h['nilai']['akhir']);
    }

    public function test_lebih_30_menit_ke_meja_owner_dan_putusan_menentukan_nilai(): void
    {
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', 'pengawasan-cnc')->sole();
        $this->assertSame('owner', $t->peran);
        $this->assertStringContainsString('Mesin dibiarkan: Mesin A — Andi (40 mnt)', $t->title);

        $meja->skill('pengawasan-cnc')->jawab($t, 'wajar', $this->owner);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame(1, Task::where('skill', 'pengawasan-cnc')->count(), 'Tidak muncul lagi setelah diputus.');
        $this->assertSame([4, 0, 1], [$this->nilai()['nilai']['n'], $this->nilai()['nilai']['ditahan'], $this->nilai()['nilai']['poin0']]);

        app(PenilaianCncService::class)->putuskan($t->taskable_id, 'abaikan', $this->owner->id);
        $this->assertSame([3, 1], [$this->nilai()['nilai']['n'], $this->nilai()['nilai']['diabaikan']]);
    }

    public function test_rapor_di_halaman_operator_cnc(): void
    {
        $this->actingAs($this->owner)->get(route('production.waktu-standar.cnc', ['bulan' => '2026-11']))
            ->assertOk()
            ->assertSee('Rapor Andi')
            ->assertSee('(1 × 2 + 2 × 1 + 0 × 0) ÷ 3 kejadian = 1,33', false)
            ->assertSee('antre 5 mnt')
            ->assertSee('Ditahan — lebih dari 30 menit');
    }
}
