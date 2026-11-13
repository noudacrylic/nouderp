<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Langkah Desain di OP (cetak/tempel stiker oleh Admin Desain, tanpa timer) + kartu "Desain
 * gambar" untuk OP pesanan yang memuat langkah Desain (tanpa centang). Contoh nyata: Rahmatan CNC → Desain → CNC, Telkomsel
 * CNC → Assembling → Desain.
 */
class LangkahDesainTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $desainer;
    private int $gudang;
    private int $pelanggan;
    private Department $cnc;
    private Department $asm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner     = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->desainer  = User::factory()->create(['role' => 'user', 'is_active' => true, 'name' => 'Farid']);
        $this->gudang    = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->pelanggan = Customer::create(['code' => 'C1', 'name' => 'Rahmatan', 'is_active' => true])->id;
        $this->cnc = Department::create(['code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->asm = Department::create(['code' => 'PRD-002', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->desainer->id, 'urutan' => 1]);

        foreach (['langkah-desain', 'desain-gambar-op', 'desain', 'desain-gambar'] as $k) {
            MejaKerjaSkill::updateOrCreate(['skill' => $k], ['peran' => 'desain', 'bobot' => 8, 'sla_menit' => 240]);
            KaryawanVirtualSkill::updateOrCreate(['skill' => "admin.$k"], ['mode' => 'otomatis', 'mode_diubah_at' => now()->subDay()]);
        }
    }

    private function so(array $atribut = []): SalesOrder
    {
        static $n = 0;
        $n++;
        $p = Product::create(['sku' => "CS4-$n", 'name' => 'Tempat Kartu Perdana', 'sale_type' => 'preorder', 'base_unit' => 'pcs',
            'base_price' => 18000, 'is_active' => true, 'is_sellable' => true]);
        $so = SalesOrder::create($atribut + ['order_number' => "SO-LD-$n", 'customer_id' => $this->pelanggan, 'warehouse_id' => $this->gudang,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 900000]);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'description' => 'Tempat Kartu Perdana',
            'qty' => 50, 'unit_price' => 18000, 'net_unit_price' => 18000, 'line_subtotal' => 900000, 'line_discount' => 0, 'line_total' => 900000]);
        return $so;
    }

    /** OP custom berlangkah (pilihan kotak divisi: id divisi atau "desain:<jenis>"). */
    private function op(SalesOrder $so, array $langkah, array $atribut = []): ProductionOrder
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create($atribut + ['order_number' => "OP-LD-$n", 'type' => 'custom', 'sales_order_id' => $so->id, 'warehouse_id' => $this->gudang,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => 'confirmed', 'score_type' => 'auto', 'created_via' => 'manual']);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $so->items->first()->product_id,
            'qty_planned' => 50, 'output_type' => 'main', 'percentage' => 100]);
        $status = $op->status;
        $op->update(['status' => 'draft']);
        app(ProductionOrderService::class)->updateSteps($op->id, collect($langkah)->map(fn ($x) => ['department_id' => $x])->all());
        $op->update(['status' => $status]);
        return $op->fresh('steps');
    }

    private function langkah(ProductionOrder $op, int $no): ProductionOrderStep
    {
        return $op->steps()->where('step_number', $no)->firstOrFail();
    }

    private function selesai(ProductionOrderStep $s): void
    {
        $s->update(['status' => 'completed', 'started_at' => now()->subHour(), 'completed_at' => now()]);
    }

    public function test_langkah_desain_disimpan_tanpa_divisi(): void
    {
        $op = $this->op($this->so(), [(string) $this->cnc->id, 'desain:tempel', (string) $this->cnc->id]);

        $d = $this->langkah($op, 2);
        $this->assertSame('tempel', $d->desain);
        $this->assertNull($d->department_id);
        $this->assertSame(ProductionOrderStep::DESAIN['tempel'], $d->name);
        $this->assertSame('desain:tempel', $d->pilihan, 'Form edit memuat ulang pilihan yang sama.');
        $this->assertSame([1, 2, 3], $op->steps()->orderBy('step_number')->pluck('step_number')->all());
    }

    public function test_giliran_desain_kartu_lalu_tombol_menutup_langkah_dan_produksi_lanjut(): void
    {
        $so = $this->so(['paid_amount' => 450000]);
        $op = $this->op($so, [(string) $this->cnc->id, 'desain:tempel', (string) $this->cnc->id], ['status' => 'in_progress']);
        $meja = app(MejaKerjaService::class);

        $meja->sinkron();
        $this->assertSame(0, Task::where('skill', 'langkah-desain')->count(), 'CNC pertama belum selesai → belum giliran Desain.');

        $this->selesai($this->langkah($op, 1));
        $meja->sinkron();
        $t = Task::where('skill', 'langkah-desain')->firstOrFail();
        $this->assertSame('desain', $t->peran);
        $this->assertSame($this->desainer->id, $t->assignee_user_id);
        $this->assertSame(2, app(ProductionOrderService::class)->activeStepNumber($op->fresh('steps')));

        $this->actingAs($this->desainer)->post(route('tasks.meja.jawab', $t->id), ['jawaban' => 'selesai'])
            ->assertSessionHas('success');

        $this->assertSame('completed', $this->langkah($op, 2)->status);
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame(3, app(ProductionOrderService::class)->activeStepNumber($op->fresh('steps')), 'CNC kedua kini giliran.');
        $this->assertSame('in_progress', $op->fresh()->status);
        $this->assertSame(0, $this->langkah($op, 2)->timeLogs()->count(), 'Tanpa timer.');
    }

    public function test_desain_di_akhir_menutup_op_dengan_qty_rencana(): void
    {
        $op = $this->op($this->so(), [(string) $this->cnc->id, (string) $this->asm->id, 'desain:tempel'], ['status' => 'in_progress']);
        $this->selesai($this->langkah($op, 1));
        $this->selesai($this->langkah($op, 2));

        $this->assertTrue(app(ProductionOrderService::class)->selesaikanLangkahDesain($this->langkah($op, 3)->id));
        $this->assertSame('completed', $op->fresh()->status);
        $this->assertEquals(50, (float) $op->outputs()->first()->qty_produced);
    }

    public function test_tidak_bisa_ditutup_sebelum_gilirannya(): void
    {
        $op = $this->op($this->so(), [(string) $this->cnc->id, 'desain:cetak', (string) $this->asm->id]);

        $this->expectExceptionMessage('belum selesai');
        app(ProductionOrderService::class)->selesaikanLangkahDesain($this->langkah($op, 2)->id);
    }

    public function test_sisip_langkah_di_tengah_op_berjalan(): void
    {
        // Telkomsel/Rahmatan: OP sudah jalan tanpa langkah Desain.
        $op = $this->op($this->so(), [(string) $this->cnc->id, (string) $this->cnc->id, (string) $this->asm->id], ['status' => 'in_progress']);
        $this->selesai($this->langkah($op, 1));

        $this->actingAs($this->owner)->post(route('production.orders.steps.add', $op->id), ['department_id' => 'desain:tempel', 'setelah' => 1])
            ->assertSessionHas('success');

        $urut = $op->steps()->orderBy('step_number')->get();
        $this->assertSame(['CNC', ProductionOrderStep::DESAIN['tempel'], 'CNC', 'Assembling'], $urut->pluck('name')->all());
        $this->assertSame('completed', $urut[0]->status, 'Langkah yang sudah jalan tak tersentuh.');

        // Tak boleh disisipkan di depan langkah yang sudah dikerjakan.
        $this->langkah($op, 3)->update(['status' => 'in_progress']);
        $this->actingAs($this->owner)->post(route('production.orders.steps.add', $op->id), ['department_id' => (string) $this->asm->id, 'setelah' => 1])
            ->assertSessionHas('error');
        $this->assertSame(4, $op->steps()->count());

        // Tanpa posisi → di urutan akhir seperti dulu.
        $this->actingAs($this->owner)->post(route('production.orders.steps.add', $op->id), ['department_id' => 'desain:cetak'])
            ->assertSessionHas('success');
        $this->assertSame('cetak', $this->langkah($op, 5)->desain);
    }

    public function test_kartu_desain_gambar_op_setelah_dibayar_dan_tanda_belum_disetujui(): void
    {
        $so = $this->so();
        $op = $this->op($so, [(string) $this->cnc->id, 'desain:tempel', (string) $this->cnc->id],
            ['status' => 'in_progress']);
        $meja = app(MejaKerjaService::class);

        $meja->sinkron();
        $this->assertSame(0, Task::where('skill', 'desain-gambar-op')->count(), 'Belum dibayar → belum ada kartu.');

        $so->update(['paid_amount' => 450000]);
        $meja->sinkron();
        $g = Task::where('skill', 'desain-gambar-op')->firstOrFail();
        $this->assertSame('desain', $g->peran);
        $this->assertStringContainsString('Tempat Kartu Perdana', (string) $g->description);

        // Giliran Desain tiba sebelum gambar disetujui → kartu bertanda ⚠️.
        $this->selesai($this->langkah($op, 1));
        $meja->sinkron();
        $l = Task::where('skill', 'langkah-desain')->firstOrFail();
        $this->assertStringStartsWith('⚠️ ', $l->title);

        // Gambar disetujui (kartu desain gambar ditandai selesai) → tanda dilepas.
        $meja->selesai($g, $this->desainer);
        $meja->sinkron();
        $this->assertStringStartsNotWith('⚠️', $l->fresh()->title);
    }

    public function test_kartu_desain_gambar_op_tidak_dobel(): void
    {
        // SO bercentang "Desain gambar oleh Admin Desain" (akrilik sign) → kartu SO saja.
        $so = $this->so(['paid_amount' => 450000, 'desain_gambar' => true]);
        $this->op($so, [(string) $this->cnc->id, 'desain:tempel']);

        // OP tanpa langkah Desain → tak ada kartu desain gambar.
        $this->op($this->so(['paid_amount' => 100000]), [(string) $this->cnc->id, (string) $this->asm->id]);

        // BC/KS: SO sudah punya kartu Desain pesanan → tak ditambah kartu OP.
        $bc = $this->so(['paid_amount' => 100000]);
        Task::create(['title' => 'Desain BC', 'creator_user_id' => $this->owner->id, 'skill' => 'desain', 'status' => 'open', 'peran' => 'desain',
            'taskable_type' => SalesOrder::class, 'taskable_id' => $bc->id, 'kunci_sumber' => "desain:so:{$bc->id}"]);
        $this->op($bc, [(string) $this->cnc->id, 'desain:cetak', (string) $this->asm->id]);

        app(MejaKerjaService::class)->sinkron();
        $this->assertSame(0, Task::where('skill', 'desain-gambar-op')->count());
    }

    public function test_tambah_langkah_desain_langsung_membuat_kartu_dan_hapus_membatalkan(): void
    {
        // Shopee: sudah dibayar, OP terposting tanpa langkah Desain.
        $so = $this->so(['paid_amount' => 900000]);
        $op = $this->op($so, [(string) $this->cnc->id, (string) $this->asm->id]);

        $this->actingAs($this->owner)->post(route('production.orders.steps.add', $op->id), ['department_id' => 'desain:tempel'])
            ->assertSessionHas('success');
        $g = Task::where('skill', 'desain-gambar-op')->firstOrFail(); // tanpa sinkron berkala
        $this->assertSame('open', $g->status);

        $step = $op->steps()->whereNotNull('desain')->firstOrFail();
        $this->actingAs($this->owner)->delete(route('production.orders.steps.delete', [$op->id, $step->id]))
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $g->fresh()->status, 'Langkah Desain dihapus → kartu batal.');
    }

    public function test_langkah_desain_tidak_masuk_antrean_proses_produksi(): void
    {
        $op = $this->op($this->so(), ['desain:tempel', (string) $this->cnc->id]);

        $this->actingAs($this->owner)->get(route('production.process.index'))
            ->assertOk()->assertDontSee(ProductionOrderStep::DESAIN['tempel']);
        $this->assertSame(1, app(ProductionOrderService::class)->activeStepNumber($op));
    }
}
