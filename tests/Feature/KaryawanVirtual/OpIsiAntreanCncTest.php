<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomMaterial;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\BomStep;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\IsiAntreanCncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Skill "Isi antrean CNC yang hampir habis": Tingkat 1 andalan → 2 hampir menipis → 3 cadangan.
 */
class OpIsiAntreanCncTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'produksi.isi-antrean-cnc';

    private Warehouse $utama;
    private Warehouse $cadangan;
    private Department $cnc;
    private Department $rakit;
    private Product $lembar;
    private Carbon $senin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->utama    = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        $this->cadangan = Warehouse::firstOrCreate(['name' => 'Cadangan'], ['is_sellable' => false, 'is_active' => true]);
        $this->cnc      = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->rakit    = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $this->lembar   = $this->barang('LBR-TEST', 100);
        $this->senin    = Carbon::parse('2026-10-26 10:00');
    }

    private function barang(string $sku, float $stok, ?float $min = null, bool $andalan = false): Product
    {
        $p = Product::create([
            'sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'made_to_order' => false, 'base_unit' => 'pcs',
            'base_price' => 10000, 'is_active' => true, 'min_stock' => $min, 'produk_andalan' => $andalan,
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->utama->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    /** Produk dengan BOM CNC → Assembling (1 lembar/siklus, 10 pcs/siklus) dan laku 30 hari. */
    private function produk(string $sku, float $stok, float $laku30, ?float $min = 10, bool $andalan = false, float $lembarPerSiklus = 1): Product
    {
        $p = $this->barang($sku, $stok, $min, $andalan);
        $bom = Bom::create(['bom_number' => 'BOM-' . $sku, 'name' => $sku, 'auto_production' => true, 'typical_cycles' => 1]);
        BomMaterial::create(['bom_id' => $bom->id, 'product_id' => $this->lembar->id, 'qty_per_cycle' => $lembarPerSiklus, 'unit' => 'Lembar']);
        BomOutput::create(['bom_id' => $bom->id, 'product_id' => $p->id, 'qty_per_cycle' => 10, 'output_type' => 'main', 'percentage' => 100]);
        BomStep::create(['bom_id' => $bom->id, 'step_number' => 1, 'department_id' => $this->cnc->id, 'name' => 'CNC']);
        BomStep::create(['bom_id' => $bom->id, 'step_number' => 2, 'department_id' => $this->rakit->id, 'name' => 'Assembling']);
        if ($laku30 > 0) {
            DB::table('inventory_ledgers')->insert([
                'product_id' => $p->id, 'warehouse_id' => $this->utama->id, 'transaction_type' => 'sale', 'transaction_id' => 1,
                'qty_in' => 0, 'qty_out' => $laku30, 'balance' => $stok, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
            ]);
        }
        return $p;
    }

    /** OP lain berjalan untuk produk $p (atau tanpa produk) dengan langkah [dept, status]. */
    private function opLain(?Product $p, array $langkah = [], string $status = 'confirmed'): ProductionOrder
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create([
            'order_number' => "OP-LAIN-{$n}", 'type' => 'ready_stock', 'warehouse_id' => $this->utama->id,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => $status,
            'score_type' => 'auto', 'created_via' => 'manual',
        ]);
        if ($p) {
            ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $p->id, 'qty_planned' => 10, 'output_type' => 'main', 'percentage' => 100]);
        }
        foreach ($langkah as $i => [$dept, $st]) {
            ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => $i + 1, 'department_id' => $dept->id, 'name' => $dept->name, 'status' => $st]);
        }
        return $op;
    }

    private function isi(): array
    {
        return app(IsiAntreanCncService::class)->jalankan(false, true, $this->senin);
    }

    private function pengisi()
    {
        return ProductionOrder::with('outputs')->where('kv_skill', 'like', self::SKILL . ':%')->orderBy('id')->get();
    }

    private function utamaDari(ProductionOrder $op): ProductionOrderOutput
    {
        return $op->outputs->firstWhere('output_type', 'main');
    }

    public function test_tingkat1_andalan_tanpa_op_dibuat_otomatis_walau_stok_banyak(): void
    {
        $p = $this->produk('RP-3x5', 500, 300, andalan: true);
        $this->produk('TBKD-13-T1-M3', 50, 300); // hampir menipis — kalah dari Tingkat 1

        $this->isi();
        $ops = $this->pengisi();

        // Mesin 2 & 3 diisi 2 OP: andalan (Tingkat 1) dulu, lalu yang hampir menipis.
        $this->assertCount(2, $ops);
        $this->assertSame($p->id, $this->utamaDari($ops[0])->product_id);
        $this->assertSame(IsiAntreanCncService::penanda(1), $ops[0]->kv_skill);
        $this->assertSame('auto', $ops[0]->score_type);
    }

    public function test_tingkat1_op_kedua_prioritas_rendah_lalu_otomatis_setelah_op_pertama_selesai(): void
    {
        $p = $this->produk('RP-3x5', 500, 300, andalan: true);
        $pertama = $this->opLain($p, [[$this->rakit, 'pending']], 'draft'); // berjalan, tapi tak di antrean CNC

        $this->isi();
        $kedua = $this->pengisi()->first();
        $this->assertSame('priority', $kedua->score_type);
        $this->assertSame('low', $kedua->priority_level);

        // Masih ada OP pertama → tidak dinaikkan.
        $this->assertSame([], app(IsiAntreanCncService::class)->sesuaikanOpBerjalan());

        $pertama->update(['status' => 'finalized']);
        $hasil = app(IsiAntreanCncService::class)->sesuaikanOpBerjalan();

        $this->assertSame('auto', $kedua->fresh()->score_type);
        $this->assertTrue($hasil[0]['diubah']);
    }

    public function test_op_manual_rendah_dinaikkan_bila_tak_ada_op_lebih_lama_dan_menahan_op_sesudahnya(): void
    {
        // Kasus AM-40x30x6 27 Okt 2026: OP manual 00100 Rendah, OP pengisi 00120 Rendah.
        $p = $this->produk('AM-40x30x6', 500, 300, andalan: true);
        $lama   = $this->opLain($p, [[$this->rakit, 'completed']], 'finalized');
        $manual = $this->opLain($p, [[$this->rakit, 'pending']], 'in_progress');
        $manual->update(['score_type' => 'priority', 'priority_level' => 'low']);
        $pengisi = $this->opLain($p, [[$this->rakit, 'pending']], 'in_progress');
        $pengisi->update(['score_type' => 'priority', 'priority_level' => 'low', 'kv_skill' => IsiAntreanCncService::penanda(1)]);

        $hasil = app(IsiAntreanCncService::class)->sesuaikanOpBerjalan();

        $this->assertSame('auto', $manual->fresh()->score_type, 'OP di depannya sudah selesai → manual Rendah naik.');
        $this->assertSame('low', $pengisi->fresh()->priority_level, 'Masih ada OP lebih lama (manual) → pengisi tetap Rendah.');
        $this->assertCount(1, $hasil);

        $manual->update(['status' => 'finalized']);
        app(IsiAntreanCncService::class)->sesuaikanOpBerjalan();
        $this->assertSame('auto', $pengisi->fresh()->score_type);
    }

    public function test_op_manual_prioritas_lain_tidak_disentuh(): void
    {
        $p = $this->produk('AM-40x30x6', 500, 300, andalan: true);
        $op = $this->opLain($p, [[$this->rakit, 'pending']], 'in_progress');
        $op->update(['score_type' => 'priority', 'priority_level' => 'high']);

        $this->assertSame([], app(IsiAntreanCncService::class)->sesuaikanOpBerjalan());
        $this->assertSame('high', $op->fresh()->priority_level);
    }

    public function test_tingkat1_maksimal_dua_op_lalu_turun_ke_tingkat2(): void
    {
        $andalan = $this->produk('RP-3x5', 500, 300, andalan: true);
        $this->opLain($andalan);
        $this->opLain($andalan);
        $t2 = $this->produk('TBKD-13-T1-M3', 50, 300);

        $hasil = $this->isi();

        $this->assertSame($t2->id, $this->utamaDari($this->pengisi()->first())->product_id);
        $this->assertStringContainsString('Tingkat 1 dilewati', collect($hasil)->firstWhere('jenis', 'tingkat')['reason']);
    }

    public function test_tingkat2_pilih_hari_cukup_terkecil_di_atas_minimum(): void
    {
        $this->produk('A-8HARI', 80, 300);         // 10/hari → 8 hari
        $cepat = $this->produk('B-4HARI', 40, 300); // 4 hari
        $this->produk('C-DIBAWAH-MIN', 5, 300, min: 10); // urusan skill stok menipis
        $this->produk('D-JARANG', 5, 20, min: 1);   // laku < 30/bulan
        $this->produk('E-AMAN', 200, 300);          // 20 hari

        $this->isi();
        $op = $this->pengisi()->first();

        $this->assertSame($cepat->id, $this->utamaDari($op)->product_id);
        $this->assertSame(IsiAntreanCncService::penanda(2), $op->kv_skill);
        $this->assertSame('auto', $op->score_type);
    }

    public function test_tingkat3_kd_laris_stok_aman_hasil_ke_cadangan(): void
    {
        $p = $this->produk('TBKD-A5-T1-M3', 200, 300); // 20 hari, cadangan 0
        $this->produk('TBKD-JENUH', 200, 300);
        ProductStock::create(['product_id' => Product::where('sku', 'TBKD-JENUH')->value('id'), 'warehouse_id' => $this->cadangan->id, 'qty_on_hand' => 300]);
        $this->produk('TB-BUKAN-KD', 200, 300);

        $this->isi();
        $ops = $this->pengisi();

        $this->assertCount(1, $ops);
        $utama = $this->utamaDari($ops[0]);
        $this->assertSame($p->id, $utama->product_id);
        $this->assertSame(IsiAntreanCncService::penanda(3), $ops[0]->kv_skill);
        $this->assertSame('low', $ops[0]->priority_level);
        $this->assertEquals([['warehouse_id' => $this->cadangan->id, 'qty' => 10.0]], $utama->warehouse_allocations);
    }

    public function test_tingkat3_stok_jual_diborong_hasil_dialihkan_ke_gudang_jual(): void
    {
        $p = $this->produk('TBKD-A5-T1-M3', 200, 300);
        $this->isi();
        $op = $this->pengisi()->first();

        ProductStock::where('product_id', $p->id)->where('warehouse_id', $this->utama->id)->update(['qty_on_hand' => 150]); // 15 hari
        $this->assertSame([], app(IsiAntreanCncService::class)->sesuaikanOpBerjalan(), 'Masih > 10 hari → tetap cadangan.');

        ProductStock::where('product_id', $p->id)->where('warehouse_id', $this->utama->id)->update(['qty_on_hand' => 40]); // 4 hari
        $hasil = app(IsiAntreanCncService::class)->sesuaikanOpBerjalan();

        $op->refresh();
        $this->assertNull($this->utamaDari($op)->warehouse_allocations, 'Modal Selesai kembali ke gudang order (jual).');
        $this->assertSame('auto', $op->score_type);
        $this->assertSame('pindah', $hasil[0]['jenis']);
        $this->assertSame([], app(IsiAntreanCncService::class)->sesuaikanOpBerjalan(), 'Tidak dialihkan dua kali.');
    }

    public function test_antrean_cnc_berisi_atau_di_luar_jam_kerja_tidak_membuat_op(): void
    {
        $this->produk('RP-3x5', 500, 300, andalan: true);
        app(IsiAntreanCncService::class)->jalankan(false, true, Carbon::parse('2026-10-25 10:00')); // Minggu
        $this->assertCount(0, $this->pengisi());

        // Antrean Mesin 2 & 3 masih 2 pekerjaan (> sisa 1) → belum diisi.
        $this->opLain(null, [[$this->cnc, 'in_progress']]);
        $this->opLain(null, [[$this->cnc, 'pending']]);
        $hasil = $this->isi();
        $this->assertCount(0, $this->pengisi());
        $this->assertStringContainsString('Antrean Mesin 2 & 3 masih 2 pekerjaan', collect($hasil)
            ->first(fn ($r) => $r['jenis'] === 'antrean' && $r['kelompok'] === IsiAntreanCncService::TUNGGAL)['reason']);
    }

    public function test_bahan_kurang_produk_dilewati_dan_dicatat(): void
    {
        $this->produk('RP-3x5', 500, 300, andalan: true, lembarPerSiklus: 999);
        $lain = $this->produk('RP-2x5', 500, 300, andalan: true);

        $this->isi();

        $this->assertSame($lain->id, $this->utamaDari($this->pengisi()->first())->product_id);

        ProductStock::where('product_id', $this->lembar->id)->update(['qty_on_hand' => 0]);
        ProductionOrder::query()->update(['status' => 'finalized']);
        $hasil = $this->isi();
        $this->assertStringContainsString('Bahan kurang', collect($hasil)
            ->first(fn ($r) => $r['tingkat'] === 1 && $r['kelompok'] === IsiAntreanCncService::TUNGGAL)['reason']);
    }

    public function test_skill_bawaan_minta_persetujuan_lalu_disetujui(): void
    {
        Carbon::setTestNow($this->senin);
        $p = $this->produk('RP-3x5', 500, 300, andalan: true);
        $kv = app(KaryawanVirtualService::class);

        $this->assertSame(Skill::PERSETUJUAN, $kv->mode(self::SKILL));
        $this->assertTrue($kv->dalamPercobaan(self::SKILL));

        $hasil = $kv->jalankan(self::SKILL, KaryawanVirtualService::PEMICU_JADWAL);
        $this->assertCount(0, $this->pengisi());
        $usulan = KaryawanVirtualUsulan::where('skill', self::SKILL)->sole();
        $this->assertSame("cnc:1:{$p->id}", $usulan->kunci);
        $this->assertSame(Skill::DIUSULKAN, $hasil['rincian'][0]['status']);

        $this->assertTrue($kv->skill(self::SKILL)->kerjakanUsulan($usulan->data)[0]['created']);
        $this->assertCount(1, $this->pengisi());
        Carbon::setTestNow();
    }

    public function test_halaman_stok_menampilkan_laku_per_hari_dan_cukup_berapa_hari(): void
    {
        $this->produk('RP-3x5', 50, 300); // 10/hari → 5 hari
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('inventory.stocks.index', ['search' => 'RP-3x5']))
            ->assertOk()
            ->assertSee('Laku/hari')
            ->assertSee('5,0 hari');
    }
}
