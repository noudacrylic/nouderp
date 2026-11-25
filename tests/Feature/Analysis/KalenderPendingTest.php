<?php

namespace Tests\Feature\Analysis;

use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Analysis\Services\ProductionCalendarService;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pending beralasan di Kalender Produksi (permintaan owner 25 Nov 2026): waktu Pause dengan
 * alasan tampil sebagai blok berlabel, bukan lubang.
 */
class KalenderPendingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_beralasan_bernama_dan_bukan_lubang(): void
    {
        Carbon::setTestNow('2026-11-25 17:00');
        $asm = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $novan = DepartmentExecutor::create(['department_id' => $asm->id, 'name' => 'Novan', 'is_active' => true]);
        $gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;

        $langkah = function (string $no, array $log, string $status) use ($asm, $novan, $gudang) {
            $op = ProductionOrder::create(['order_number' => $no, 'type' => 'ready_stock', 'warehouse_id' => $gudang,
                'planned_cycles' => 1, 'planned_qty' => 1, 'production_date' => '2026-11-25', 'status' => 'in_progress']);
            $s = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => $asm->id,
                'name' => 'Rakit', 'status' => $status]);
            DB::table('production_order_step_executors')->insert(['step_id' => $s->id, 'executor_id' => $novan->id]);
            foreach ($log as [$e, $jam, $alasan]) {
                ProductionStepTimeLog::create(['production_order_step_id' => $s->id, 'event_type' => $e, 'alasan' => $alasan,
                    'occurred_at' => "2026-11-25 $jam", 'notes' => $alasan === 'bantu_divisi' ? 'bantu CNC' : null]);
            }
        };
        // 08–10 kerja, 10:00–10:30 bongkar barang, 10:30–12 kerja.
        $langkah('OP-P1', [['started', '08:00', null], ['paused', '10:00', 'bongkar_barang'], ['resumed', '10:30', null], ['completed', '12:00', null]], 'completed');
        // 13:00 mulai, 14:00 Pending bantu divisi dan masih Pending sampai sekarang (17:00) → digambar s/d 16:00.
        $langkah('OP-P2', [['started', '13:00', null], ['paused', '14:00', 'bantu_divisi']], 'paused');
        // Pause tanpa alasan (data lama) tidak dianggap Pending.
        $langkah('OP-P3', [['started', '12:00', null], ['paused', '12:30', null]], 'paused');

        $data = app(ProductionCalendarService::class)->build(Carbon::parse('2026-11-25'));
        $row = collect($data['departments'])->firstWhere('name', 'Assembling')['rows'][0];

        $this->assertSame(30 * 60 + 2 * 3600, $row['pending_seconds'], '30 mnt bongkar + 14–16 bantu divisi (jam kerja).');
        $this->assertSame(['📦 Bongkar / turunkan barang datang', '🤝 Bantu rekan / divisi lain'], array_column($row['pendings_tampil'], 'label'));
        $this->assertSame('16:00', $row['pendings_tampil'][1]['end']->format('H:i'));
        $this->assertSame(30 * 60, $row['gap_seconds'], 'Sisa lubang hanya 12:30–13:00 (pause tanpa alasan).');
        $this->assertSame($row['pending_seconds'], $data['totals']['pending_seconds']);

        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($owner)->get(route('analisa.kalender.index', ['date' => '2026-11-25']))
            ->assertOk()
            ->assertSee('Pending beralasan')
            ->assertSee('Pending: 🤝 Bantu rekan / divisi lain', false)
            ->assertSee('pending 2j 30m');
        Carbon::setTestNow();
    }
}
