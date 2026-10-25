<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\NmDariBnmService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skill "Isi antrean Assembling yang kosong": rakit BNM → NM untuk NM berstok terendah.
 */
class OpIsiAntreanRakitTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'produksi.isi-antrean-rakit';

    private Warehouse $gudang;
    private Department $cnc;
    private Department $rakit;
    private Carbon $senin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gudang = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        $this->cnc    = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->rakit  = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $this->senin  = Carbon::parse('2026-10-26 10:00'); // Senin
    }

    private function produk(string $sku, float $stok, ?float $min = null): Product
    {
        $p = Product::create([
            'sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'made_to_order' => false,
            'base_unit' => 'pcs', 'base_price' => 10000, 'is_active' => true, 'min_stock' => $min,
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    /** NM (stok aman, min 25) + BNM-nya. */
    private function pasangan(string $ukuran, float $stokNm, float $stokBnm): array
    {
        return [$this->produk("NM-{$ukuran}", $stokNm, 25), $this->produk("BNM-{$ukuran}", $stokBnm)];
    }

    /** OP lain yang sudah confirmed dengan langkah [dept => status, ...] berurutan. */
    private function opLain(array $langkah): ProductionOrder
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create([
            'order_number' => "OP-LAIN-{$n}", 'type' => 'ready_stock', 'warehouse_id' => $this->gudang->id,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => 'confirmed',
            'score_type' => 'auto', 'created_via' => 'manual',
        ]);
        $i = 0;
        foreach ($langkah as [$dept, $status]) {
            ProductionOrderStep::create([
                'production_order_id' => $op->id, 'step_number' => ++$i, 'department_id' => $dept->id,
                'name' => $dept->name, 'status' => $status,
            ]);
        }
        return $op;
    }

    private function isi(?Carbon $saat = null): array
    {
        return app(NmDariBnmService::class)->isiAntreanRakit(false, true, $saat ?? $this->senin);
    }

    private function pengisi(): \Illuminate\Support\Collection
    {
        return ProductionOrder::with(['materials', 'outputs', 'steps'])->where('created_via', 'auto')->get();
    }

    public function test_antrean_kosong_membuat_satu_op_untuk_nm_berstok_terendah(): void
    {
        $this->pasangan('7X20-M1', 60, 300);
        [$nmRendah, $bnmRendah] = $this->pasangan('7X30-M1', 40, 80);
        $this->pasangan('7X25-M1', 50, 500);

        $this->isi();
        $ops = $this->pengisi();

        $this->assertCount(1, $ops, 'Satu OP sekali jalan.');
        $op = $ops->first();
        $this->assertSame($nmRendah->id, $op->outputs->firstWhere('output_type', 'main')->product_id, 'Stok NM terendah dulu.');
        $this->assertEquals(80, (float) $op->materials->firstWhere('product_id', $bnmRendah->id)->qty_required, 'BNM < 150 → seluruh BNM, tanpa potong lembar.');
        $this->assertCount(1, $op->materials);
        $this->assertSame('priority', $op->score_type);
        $this->assertSame('low', $op->priority_level);
        $this->assertNull($op->bom_id);
        $this->assertSame([$this->rakit->id], $op->steps->pluck('department_id')->all());
    }

    public function test_bnm_150_ke_atas_dibatasi_150(): void
    {
        [$nm] = $this->pasangan('7X20-M1', 60, 686);

        $this->isi();

        $this->assertEquals(150, (float) $this->pengisi()->first()->outputs->firstWhere('output_type', 'main')->qty_planned);
    }

    public function test_antrean_berisi_tidak_membuat_op(): void
    {
        $this->pasangan('7X20-M1', 60, 300);
        $this->opLain([[$this->rakit, 'paused']]);

        $hasil = $this->isi();

        $this->assertCount(0, $this->pengisi());
        $this->assertStringContainsString('masih berisi 1 pekerjaan', $hasil[0]['reason']);
    }

    public function test_langkah_assembling_yang_belum_gilirannya_tidak_dihitung_antrean(): void
    {
        $this->pasangan('7X20-M1', 60, 300);
        $this->opLain([[$this->cnc, 'in_progress'], [$this->rakit, 'pending']]);

        $this->isi();

        $this->assertCount(1, $this->pengisi(), 'CNC belum selesai → Assembling belum punya pekerjaan.');
    }

    public function test_di_luar_jam_kerja_tidak_mengisi(): void
    {
        $this->pasangan('7X20-M1', 60, 300);

        $this->isi(Carbon::parse('2026-10-25 10:00')); // Minggu
        $this->isi(Carbon::parse('2026-10-26 16:30')); // Senin sore
        $this->isi(Carbon::parse('2026-10-26 07:59'));

        $this->assertCount(0, $this->pengisi());
    }

    public function test_bnm_kurang_dari_10_dan_nm_dengan_op_aktif_dilewati(): void
    {
        $this->pasangan('7X20-M1', 1, 9);
        [$nmAktif] = $this->pasangan('7X25-M1', 2, 300);
        $aktif = $this->opLain([[$this->cnc, 'pending']]);
        ProductionOrderOutput::create(['production_order_id' => $aktif->id, 'product_id' => $nmAktif->id, 'qty_planned' => 10, 'output_type' => 'main', 'percentage' => 100]);
        [$nmPilih] = $this->pasangan('7X30-M1', 90, 30);

        $this->isi();

        $this->assertSame($nmPilih->id, $this->pengisi()->first()->outputs->first()->product_id);
    }

    public function test_pengisi_yang_masih_draft_menahan_pengisi_berikutnya(): void
    {
        $this->pasangan('7X20-M1', 60, 300);
        $this->pasangan('7X30-M1', 70, 300);

        $this->isi();
        ProductionOrder::query()->update(['status' => 'draft']);
        $hasil = $this->isi();

        $this->assertCount(1, $this->pengisi());
        $this->assertStringContainsString('masih draft', end($hasil)['reason']);
    }

    public function test_nm_menipis_selagi_antre_skor_naik_ke_otomatis(): void
    {
        [$nm] = $this->pasangan('7X20-M1', 60, 300);
        $this->isi();
        $op = $this->pengisi()->first();
        $this->assertEquals(0, $op->effective_score, 'Prioritas rendah.');

        // Masih aman → tidak diubah.
        $this->assertSame([], app(NmDariBnmService::class)->naikkanPengisiMenipis());

        ProductStock::where('product_id', $nm->id)->update(['qty_on_hand' => 20]);
        $hasil = app(NmDariBnmService::class)->naikkanPengisiMenipis();

        $op->refresh();
        $this->assertSame('auto', $op->score_type);
        $this->assertNull($op->priority_level);
        $this->assertTrue($hasil[0]['diubah']);
        $this->assertEquals(100, $op->effective_score, 'Otomatis tanpa BOM = bobot stok menipis (100).');

        ProductStock::where('product_id', $nm->id)->update(['qty_on_hand' => 0]);
        $this->assertEquals(200, $op->fresh()->effective_score, 'Habis = 200.');
    }

    public function test_skill_bawaan_minta_persetujuan_lalu_disetujui_membuat_op(): void
    {
        Carbon::setTestNow($this->senin);
        [$nm] = $this->pasangan('7X20-M1', 60, 300);
        $kv = app(KaryawanVirtualService::class);

        $this->assertSame(Skill::PERSETUJUAN, $kv->mode(self::SKILL));
        $this->assertTrue($kv->dalamPercobaan(self::SKILL));

        $hasil = $kv->jalankan(self::SKILL, KaryawanVirtualService::PEMICU_JADWAL);
        $this->assertCount(0, $this->pengisi(), 'Belum disetujui.');
        $usulan = KaryawanVirtualUsulan::where('skill', self::SKILL)->sole();
        $this->assertSame('rakit:' . $nm->id, $usulan->kunci);
        $this->assertSame(Skill::DIUSULKAN, $hasil['rincian'][0]['status']);

        $hasilSetuju = $kv->skill(self::SKILL)->kerjakanUsulan($usulan->data);
        $this->assertTrue($hasilSetuju[0]['created']);
        $this->assertCount(1, $this->pengisi());
        Carbon::setTestNow();
    }
}
