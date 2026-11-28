<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\AlatTempelService;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\OpAkrilikSignService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\MejaKerja\Skills\LangkahDesainSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Skill "Buat OP Akrilik Sign" (disepakati owner 21 Nov 2026): jalur cetak & tempel Admin Desain
 * bila longgar, selain itu cetak → Assembling; alat tempel satu; OP pengisi saat admin nganggur.
 */
class OpAkrilikSignTest extends TestCase
{
    use RefreshDatabase;

    private User $farid;
    private Warehouse $gudang;
    private Department $asm;
    private Product $bas;
    private Product $stiker;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00')); // Senin, jam kerja
        $this->farid  = User::factory()->create(['role' => 'user', 'is_active' => true, 'name' => 'Farid']);
        $this->gudang = Warehouse::create(['name' => 'Gudang Utama', 'is_sellable' => true, 'is_active' => true]);
        $this->asm    = Department::create(['code' => 'PRD-002', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->farid->id, 'urutan' => 1]);
        $this->bas    = $this->produk('BAS-3010');
        $this->stiker = $this->produk('STK-A3');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function produk(string $sku, ?float $min = null): Product
    {
        return Product::create(['sku' => $sku, 'name' => "Produk $sku", 'base_unit' => 'pcs', 'base_price' => 23900,
            'is_active' => true, 'is_sellable' => true, 'min_stock' => $min]);
    }

    /** Produk AS + BOM (BAS + 0,25 lembar stiker, langkah Desain tempel) + stok. */
    private function sign(string $sku, float $stok, ?float $min = 2, int $siklus = 4): Product
    {
        $p = $this->produk($sku, $min);
        app(BomService::class)->create([
            'name' => "BOM $sku", 'typical_cycles' => $siklus,
            'materials' => [['product_id' => $this->bas->id, 'qty_per_cycle' => 1, 'unit' => 'pcs'],
                            ['product_id' => $this->stiker->id, 'qty_per_cycle' => 0.25, 'unit' => 'lembar']],
            'outputs' => [['product_id' => $p->id, 'qty_per_cycle' => 1, 'output_type' => 'main', 'percentage' => 100]],
            'steps'   => [['department_id' => 'desain:tempel', 'name' => '']],
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    private function kartuFarid(int $n, bool $lewat = false): void
    {
        for ($i = 0; $i < $n; $i++) {
            Task::create(['title' => "Kartu $i", 'creator_user_id' => $this->farid->id, 'assignee_user_id' => $this->farid->id,
                'status' => 'open', 'priority' => 'normal', 'source' => 'skill', 'skill' => 'desain', 'peran' => 'desain',
                'tenggat_at' => $lewat ? now()->subHour() : now()->addHours(3)]);
        }
    }

    private function op(Product $p): ?ProductionOrder
    {
        return ProductionOrder::where('kv_skill', OpAkrilikSignService::SKILL)
            ->whereHas('outputs', fn ($q) => $q->where('product_id', $p->id))->with('steps')->latest('id')->first();
    }

    private function jalur(ProductionOrder $op): array
    {
        return $op->steps->sortBy('step_number')->map(fn ($s) => $s->desain ? "desain:{$s->desain}" : $s->department?->name)->values()->all();
    }

    private function jual(Product $p, float $qty): void
    {
        static $n = 0;
        $n++;
        $so = SalesOrder::create(['order_number' => "SO-AS-$n", 'customer_id' => Customer::create(['code' => "C$n", 'name' => 'Pembeli', 'is_active' => true])->id,
            'warehouse_id' => $this->gudang->id, 'order_date' => now()->subDays(3)->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0]);
        SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'description' => $p->name,
            'qty' => $qty, 'unit_price' => 23900, 'net_unit_price' => 23900, 'line_subtotal' => 0, 'line_discount' => 0, 'line_total' => 0]);
    }

    public function test_menipis_dan_admin_longgar_dicetak_dan_ditempel_admin(): void
    {
        $kiri = $this->sign('AS-JE-3010-KI-STD', 1);
        $this->kartuFarid(2);

        $hasil = app(OpAkrilikSignService::class)->periksa();

        $op = $this->op($kiri);
        $this->assertNotNull($op, json_encode($hasil));
        $this->assertSame(['desain:tempel'], $this->jalur($op));
        $this->assertEquals(4, $op->outputs->firstWhere('output_type', 'main')->qty_planned, 'Jumlah = Jumlah Siklus Biasa BOM.');
        $this->assertSame('auto', $op->score_type);
    }

    public function test_dua_menipis_hanya_satu_tempel_di_admin(): void
    {
        $kiri  = $this->sign('AS-JE-3010-KI-STD', 0);
        $kanan = $this->sign('AS-JE-3010-KA-STD', 1);

        app(OpAkrilikSignService::class)->periksa();

        $this->assertSame(['desain:tempel'], $this->jalur($this->op($kiri)), 'Stok habis didahulukan.');
        $this->assertSame(['desain:cetak', 'Assembling'], $this->jalur($this->op($kanan)), 'Alat satu → yang kedua ditempel Assembling.');
    }

    public function test_admin_sibuk_atau_ada_kartu_lewat_tenggat_ditempel_assembling(): void
    {
        $a = $this->sign('AS-EX-3010-KI-STD', 0);
        $this->kartuFarid(9);
        app(OpAkrilikSignService::class)->periksa();
        $this->assertSame(['desain:cetak', 'Assembling'], $this->jalur($this->op($a)), '9 kartu antre > 8.');

        Task::query()->delete();
        $b = $this->sign('AS-EX-3010-KA-STD', 0);
        $this->kartuFarid(1, lewat: true);
        // Langkah tempel OP pertama sudah selesai supaya hanya keadaan Farid yang diuji.
        ProductionOrderStep::query()->update(['status' => 'completed']);
        app(OpAkrilikSignService::class)->periksa();
        $this->assertSame(['desain:cetak', 'Assembling'], $this->jalur($this->op($b)), 'Ada kartu lewat tenggat.');
    }

    public function test_sisa_jam_kerja_kurang_dari_satu_jam_tidak_longgar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 15:30:00'));
        $p = $this->sign('AS-PD-3010-TP-STD', 0);

        app(OpAkrilikSignService::class)->periksa();

        $this->assertSame(['desain:cetak', 'Assembling'], $this->jalur($this->op($p)));
    }

    public function test_simulasi_tidak_membuat_op_dan_memberi_peringatan_lembar(): void
    {
        $p = $this->sign('AS-TK-3040-TP-STD', 0, 2, 6);

        $hasil = app(OpAkrilikSignService::class)->periksa(true);

        $this->assertNull($this->op($p));
        $this->assertSame('akan', $hasil[0]['jenis']);
        $this->assertStringContainsString('sisa 2 dari 4 slot', $hasil[0]['reason']);
    }

    public function test_op_berjalan_tidak_dibuat_lagi(): void
    {
        $p = $this->sign('AS-JE-3010-AT-STD', 0);
        app(OpAkrilikSignService::class)->periksa();
        app(OpAkrilikSignService::class)->periksa();

        $this->assertSame(1, ProductionOrder::where('kv_skill', OpAkrilikSignService::SKILL)->count());
    }

    public function test_pengisi_saat_admin_nganggur_untuk_yang_paling_cepat_habis(): void
    {
        $laris  = $this->sign('AS-JE-3010-KI-STD', 5);   // min 2, sekali produksi 4 → < 6
        $sepi   = $this->sign('AS-JE-3010-KA-STD', 5);
        $penuh  = $this->sign('AS-JE-3010-AT-STD', 9);   // ≥ min + 4 → tak perlu
        $this->jual($laris, 20);
        $this->jual($sepi, 2);
        $this->jual($penuh, 50);

        app(OpAkrilikSignService::class)->periksa();

        $op = $this->op($laris);
        $this->assertNotNull($op);
        $this->assertSame(['priority', 'low'], [$op->score_type, $op->priority_level]);
        $this->assertSame(['desain:tempel'], $this->jalur($op));
        $this->assertSame(1, ProductionOrder::where('kv_skill', OpAkrilikSignService::SKILL)->count(), 'Satu pengisi saja.');

        // Lalu SKU-nya keburu menipis → dinaikkan ke prioritas biasa.
        ProductStock::where('product_id', $laris->id)->update(['qty_on_hand' => 1]);
        app(OpAkrilikSignService::class)->periksa();
        $this->assertSame('auto', $op->fresh()->score_type);
    }

    public function test_tanpa_pengisi_bila_admin_punya_kartu_atau_alat_dipakai_assembling(): void
    {
        $p = $this->sign('AS-JE-3010-KI-STD', 5);
        $this->jual($p, 20);
        $this->kartuFarid(1);
        app(OpAkrilikSignService::class)->periksa();
        $this->assertNull($this->op($p), 'Satu kartu antre = tidak nganggur.');

        Task::query()->delete();
        $lain = $this->sign('AS-EX-3010-KI-STD', 0);
        $this->kartuFarid(9);
        app(OpAkrilikSignService::class)->periksa();               // lain: cetak → Assembling
        Task::query()->delete();
        $asm = $this->op($lain)->steps->firstWhere('department_id', $this->asm->id);
        $this->op($lain)->steps->firstWhere('desain', 'cetak')->update(['status' => 'completed']);
        $asm->update(['status' => 'in_progress']);
        app(AlatTempelService::class)->lupakan();

        $this->assertSame('Assembling', app(AlatTempelService::class)->dipakai()['oleh']);
        app(OpAkrilikSignService::class)->periksa();
        $this->assertNull($this->op($p), 'Alat dipakai Assembling → tak ada pengisi.');
    }

    public function test_kartu_tempel_wajib_mulai_dan_bertanda_tunggu_alat(): void
    {
        MejaKerjaSkill::updateOrCreate(['skill' => 'langkah-desain'], ['peran' => 'desain', 'bobot' => 9, 'sla_menit' => 240]);
        KaryawanVirtualSkill::updateOrCreate(['skill' => 'admin.langkah-desain'], ['mode' => 'otomatis', 'mode_diubah_at' => now()->subDay()]);
        $tempel = $this->sign('AS-JE-3010-KI-STD', 0);
        $cetak  = $this->sign('AS-JE-3010-KA-STD', 1);
        app(OpAkrilikSignService::class)->periksa();               // KI tempel, KA cetak → Assembling

        $meja = app(MejaKerjaService::class);
        $meja->kerjakan($meja->skill('langkah-desain'), false);
        $skill = app(LangkahDesainSkill::class);
        $kartuTempel = Task::where('skill', 'langkah-desain')->where('taskable_id', $this->op($tempel)->id)->sole();
        $kartuCetak  = Task::where('skill', 'langkah-desain')->where('taskable_id', $this->op($cetak)->id)->sole();

        $this->assertTrue(MejaKerjaService::butuhMulai($skill, $kartuTempel));
        $this->assertFalse(MejaKerjaService::butuhMulai($skill, $kartuCetak));
        $this->assertSame([], $skill->jawaban($kartuTempel), 'Tombol Selesai baru muncul setelah Mulai.');
        $this->assertNotEmpty($skill->jawaban($kartuCetak));

        // Assembling menempel OP KA → kartu tempel KI bertanda tunggu alat.
        $opCetak = $this->op($cetak);
        $opCetak->steps->firstWhere('desain', 'cetak')->update(['status' => 'completed']);
        $opCetak->steps->firstWhere('department_id', $this->asm->id)->update(['status' => 'in_progress']);
        app(AlatTempelService::class)->lupakan();
        $skill->keadaan($kartuTempel);
        $this->assertStringStartsWith('⏳ ', $kartuTempel->fresh()->title);

        // Assembling selesai → tanda dilepas; Farid Mulai → alat dipakai Farid.
        $opCetak->steps->firstWhere('department_id', $this->asm->id)->update(['status' => 'completed']);
        app(AlatTempelService::class)->lupakan();
        $skill->keadaan($kartuTempel->fresh());
        $this->assertStringStartsNotWith('⏳', $kartuTempel->fresh()->title);

        $meja->mulai($kartuTempel->fresh(), $this->farid);
        app(AlatTempelService::class)->lupakan();
        $this->assertSame(['Farid', true], [app(AlatTempelService::class)->dipakai()['oleh'], app(AlatTempelService::class)->dipakai()['desain']]);
        $this->assertNotEmpty($skill->jawaban($kartuTempel->fresh()));
    }
}
