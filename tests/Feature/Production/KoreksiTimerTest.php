<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\KoreksiTimerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Koreksi timer oleh owner (20 Nov 2026): karyawan lembur bongkar akrilik tapi lupa Pending,
 * timer OP terus jalan → owner mengeluarkan rentang jamnya dari halaman OP.
 */
class KoreksiTimerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private int $gudang;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00');
        Department::forceCreate(['id' => 2, 'code' => 'ASM', 'name' => 'Assembling']);
        $this->gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<int, array{0: string, 1: string}> $log [event, 'Y-m-d H:i'] */
    private function langkah(string $status, array $log): ProductionOrderStep
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create(['order_number' => "OP-KT-$n", 'type' => 'ready_stock', 'warehouse_id' => $this->gudang,
            'planned_cycles' => 1, 'planned_qty' => 0, 'production_date' => '2026-11-19', 'status' => 'in_progress']);
        $step = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => 2,
            'name' => 'Rakit', 'status' => $status, 'started_at' => $log[0][1]]);
        foreach ($log as [$ev, $jam]) {
            ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => $ev, 'occurred_at' => $jam]);
        }

        return $step;
    }

    private function menit(ProductionOrderStep $s): float
    {
        return $s->fresh()->elapsed_working_seconds / 60;
    }

    public function test_lembur_lupa_pending_dikeluarkan_lalu_bisa_dibatalkan(): void
    {
        // Kerja 08:00–16:00, lembur jalan otomatis 16:20–17:31, besok 08:00 lanjut sampai sekarang (10:00).
        $s = $this->langkah('in_progress', [
            ['started', '2026-11-19 08:00'], ['auto_paused', '2026-11-19 16:00'],
            ['auto_resumed', '2026-11-19 16:20'], ['auto_paused', '2026-11-19 17:31'],
            ['auto_resumed', '2026-11-20 08:00'],
        ]);
        $this->assertEqualsWithDelta(480 + 71 + 120, $this->menit($s), 0.01);

        // Owner memilih rentang lebih lebar dari lemburnya — hanya waktu timer berjalan yang dihitung.
        $detik = app(KoreksiTimerService::class)->keluarkan($s->id, Carbon::parse('2026-11-19 16:00'),
            Carbon::parse('2026-11-19 18:00'), 'bongkar_barang', 'lembur bongkar akrilik', $this->owner->id);
        $this->assertSame(71 * 60, $detik);
        $this->assertEqualsWithDelta(480 + 120, $this->menit($s), 0.01);
        $this->assertSame('in_progress', $s->fresh()->status, 'Status langkah tidak berubah.');

        $p = ProductionStepTimeLog::where('production_order_step_id', $s->id)->whereNotNull('koreksi_grup')->sole();
        $this->assertSame(['paused', 'bongkar_barang', 'Koreksi owner: lembur bongkar akrilik', $this->owner->id],
            [$p->event_type, $p->alasan, $p->notes, (int) $p->koreksi_by]);

        // Log terakhir (urut jam) tetap auto_resumed asli — penjadwal otomatis tidak terkecoh.
        $akhir = ProductionStepTimeLog::where('production_order_step_id', $s->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $this->assertSame('auto_resumed', $akhir->event_type);

        app(KoreksiTimerService::class)->batalkan($s->id, $p->koreksi_grup);
        $this->assertEqualsWithDelta(480 + 71 + 120, $this->menit($s), 0.01);
    }

    public function test_rentang_di_tengah_segmen_disisipi_pause_dan_lanjut(): void
    {
        $s = $this->langkah('completed', [['started', '2026-11-19 13:00'], ['completed', '2026-11-19 18:00']]);
        app(KoreksiTimerService::class)->keluarkan($s->id, Carbon::parse('2026-11-19 16:20'),
            Carbon::parse('2026-11-19 17:31'), 'bongkar_barang', null, $this->owner->id);

        $this->assertEqualsWithDelta(300 - 71, $this->menit($s), 0.01);
        $this->assertSame(['paused', 'resumed'],
            ProductionStepTimeLog::whereNotNull('koreksi_grup')->orderBy('occurred_at')->pluck('event_type')->all());
    }

    public function test_rentang_tanpa_timer_berjalan_ditolak(): void
    {
        $s = $this->langkah('completed', [['started', '2026-11-19 08:00'], ['auto_paused', '2026-11-19 16:00'],
            ['auto_resumed', '2026-11-20 08:00'], ['completed', '2026-11-20 09:00']]);

        $this->expectException(InvalidArgumentException::class);
        app(KoreksiTimerService::class)->keluarkan($s->id, Carbon::parse('2026-11-19 16:20'),
            Carbon::parse('2026-11-19 17:31'), 'bongkar_barang', null, $this->owner->id);
    }

    public function test_hanya_owner_dan_panel_tampil_di_halaman_op(): void
    {
        $s = $this->langkah('completed', [['started', '2026-11-19 13:00'], ['completed', '2026-11-19 18:00']]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $isi = ['dari' => '2026-11-19T16:20', 'sampai' => '2026-11-19T17:31', 'alasan' => 'bongkar_barang'];

        $this->actingAs($admin)->post(route('production.waktu-standar.timer.koreksi', $s->id), $isi)->assertForbidden();

        $this->actingAs($this->owner)->from(route('production.orders.show', $s->production_order_id))
            ->post(route('production.waktu-standar.timer.koreksi', $s->id), $isi)
            ->assertRedirect(route('production.orders.show', $s->production_order_id))
            ->assertSessionHas('success', '1 jam 11 mnt dikeluarkan dari timer.');

        $this->actingAs($this->owner)->get(route('production.orders.show', $s->production_order_id))
            ->assertOk()->assertSee('Riwayat & koreksi timer', false)->assertSee('1 koreksi');
    }
}
