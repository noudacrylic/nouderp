<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Models\ProductPackingCost;
use App\Modules\Analysis\Models\ProductionTimeAssumption;
use App\Modules\Analysis\Services\ProductHppService;
use App\Modules\Analysis\Services\ProductionQuotaService;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\Production\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Susunan HPP.
 *
 * Yang dikunci di sini adalah bentuk rumusnya, karena setiap sukunya punya cara gagal yang
 * tidak kelihatan sebagai kesalahan:
 *
 *  1. **Empat suku dijumlah**, tidak ada yang menimpa yang lain. Packing Khusus dulu berperilaku
 *     sebagai pengganti; sekarang ia biaya EKSTRA di atas overhead packing (peti kayu tidak
 *     menghapus ongkos membungkus yang biasa).
 *  2. **Fixed cost = tarif per slot-jam × waktu**, memakai waktu EFEKTIF — jadi asumsi di halaman
 *     Waktu Produksi langsung terasa di sini tanpa mengubah data terukurnya.
 *  3. **Rekonsiliasi harus bertemu**: terserap + tidak terserap = fixed cost sebulan. Kalau tidak,
 *     waktu dan kuota diambil dari data yang berbeda — kesalahan yang hanya akan terasa sebagai
 *     HPP yang "rasanya kurang pas" tanpa bisa ditunjuk di mana.
 */
class ProductHppServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Publik karena dibaca kelas stub anonim di bawah. */
    public const TARIF     = 20_000.0;
    public const KAPASITAS = 1_000.0;
    public const TERPAKAI  = 800.0;
    public const PACKING   = 5_000.0;

    private int $cnc;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cnc = Department::create(['code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true])->id;

        $this->productId = DB::table('products')->insertGetId([
            'sku' => 'UJI-1', 'name' => 'Produk Uji', 'base_price' => 1_000_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->stubKuota();
        $this->stubWaktu(7200.0);   // 2 jam per unit
    }

    // ==========================================================
    // SUSUNAN
    // ==========================================================

    public function test_hpp_menjumlah_empat_suku(): void
    {
        $r = $this->hpp();

        $this->assertEqualsWithDelta(40_000.0, $r['fixed_cost'], 0.01, '2 jam × Rp 20.000.');
        $this->assertEqualsWithDelta(self::PACKING, $r['packing_overhead'], 0.01);
        $this->assertNull($r['packing_khusus']);

        // Variable cost belum ada lapisan stok → null, dan HPP tetap terbentuk dari sisanya.
        $this->assertNull($r['variable_cost']);
        $this->assertEqualsWithDelta(45_000.0, $r['hpp_per_unit'], 0.01);
        $this->assertContains('Variable cost belum diketahui — belum ada hasil produksi tersimpan di kartu stok untuk OP sampel ini.', $r['warnings']);
    }

    public function test_packing_khusus_ditambahkan_bukan_menimpa_overhead(): void
    {
        ProductPackingCost::create(['product_id' => $this->productId, 'amount_per_unit' => 30_000]);

        $r = $this->hpp();

        $this->assertEqualsWithDelta(self::PACKING, $r['packing_overhead'], 0.01, 'Overhead packing tidak boleh hilang.');
        $this->assertEqualsWithDelta(30_000.0, $r['packing_khusus'], 0.01);
        $this->assertEqualsWithDelta(35_000.0, $r['packing_total'], 0.01, 'Peti kayu adalah biaya EKSTRA, bukan pengganti.');
        $this->assertEqualsWithDelta(75_000.0, $r['hpp_per_unit'], 0.01);
    }

    // ==========================================================
    // WAKTU EFEKTIF
    // ==========================================================

    public function test_fixed_cost_mengikuti_asumsi_waktu_yang_dicentang(): void
    {
        ProductionTimeAssumption::create([
            'product_id'               => $this->productId,
            'department_id'            => $this->cnc,
            'assumed_seconds_per_unit' => 3600,      // 1 jam, separuh dari yang terukur
            'use_assumption'           => true,
        ]);

        $r = $this->hpp();

        $this->assertEqualsWithDelta(3600.0, $r['sec_per_unit_effective'], 0.01);
        $this->assertEqualsWithDelta(7200.0, $r['sec_per_unit'], 0.01, 'Angka terukur tetap utuh di sebelahnya.');
        $this->assertEqualsWithDelta(20_000.0, $r['fixed_cost'], 0.01, 'Setengah waktu, setengah fixed cost.');
        $this->assertTrue($r['has_assumption']);
    }

    public function test_asumsi_yang_belum_dicentang_tidak_mengubah_hpp(): void
    {
        ProductionTimeAssumption::create([
            'product_id'               => $this->productId,
            'department_id'            => $this->cnc,
            'assumed_seconds_per_unit' => 3600,
            'use_assumption'           => false,
        ]);

        $r = $this->hpp();

        $this->assertEqualsWithDelta(40_000.0, $r['fixed_cost'], 0.01);
        $this->assertFalse($r['has_assumption']);
    }

    // ==========================================================
    // MARGIN & REKONSILIASI
    // ==========================================================

    public function test_margin_dihitung_dari_harga_base(): void
    {
        $r = $this->hpp();

        $this->assertEqualsWithDelta(1_000_000.0, $r['base_price'], 0.01);
        $this->assertEqualsWithDelta(1_000_000.0 - 45_000.0, $r['margin'], 0.01);
        $this->assertEqualsWithDelta(95.5, $r['margin_percent'], 0.1);
    }

    public function test_rekonsiliasi_terserap_dan_tidak_terserap_menutup_total(): void
    {
        $rekon = app(ProductHppService::class)->reconciliation([]);

        $this->assertEqualsWithDelta(self::TARIF * self::TERPAKAI, $rekon['absorbed'], 0.01);
        $this->assertEqualsWithDelta(
            $rekon['fixed_total'],
            $rekon['absorbed'] + $rekon['unabsorbed'],
            0.01,
            'Kalau ini meleset, waktu dan kuota diambil dari data yang berbeda.'
        );
        $this->assertEqualsWithDelta(20.0, $rekon['unabsorbed_percent'], 0.1, 'Utilisasi 80% menyisakan 20% tak terserap.');
    }

    // ==========================================================
    // Bantuan
    // ==========================================================

    private function hpp(): array
    {
        app()->forgetInstance(ProductHppService::class);

        return app(ProductHppService::class)->forProduct($this->productId, []);
    }

    /** Kapasitas & tarif dipatok, supaya yang diuji susunan HPP-nya. */
    private function stubKuota(): void
    {
        $this->app->instance(ProductionQuotaService::class, new class extends ProductionQuotaService {
            public function __construct()
            {
            }

            public function build(array $filters = []): array
            {
                return [
                    'slots'  => [],
                    'totals' => [
                        'slot_count'      => 2,
                        'available_month' => ProductHppServiceTest::KAPASITAS,
                        'used_month'      => ProductHppServiceTest::TERPAKAI,
                        'utilization'     => 80.0,
                    ],
                    'cost' => [
                        'grand_total'             => ProductHppServiceTest::TARIF * ProductHppServiceTest::KAPASITAS,
                        'packing_total'           => 1_000_000.0,
                        'packing_per_transaction' => ProductHppServiceTest::PACKING,
                        'transactions_per_month'  => 200,
                        'fixed_total'             => ProductHppServiceTest::TARIF * ProductHppServiceTest::KAPASITAS,
                        'available_hours'         => ProductHppServiceTest::KAPASITAS,
                        'rate_per_slot_hour'      => ProductHppServiceTest::TARIF,
                        'absorbed'                => ProductHppServiceTest::TARIF * ProductHppServiceTest::TERPAKAI,
                        'unabsorbed'              => ProductHppServiceTest::TARIF * (ProductHppServiceTest::KAPASITAS - ProductHppServiceTest::TERPAKAI),
                        'unabsorbed_percent'      => 20.0,
                    ],
                ];
            }

            public function capacityPerDay(array $filters = []): array
            {
                return [];
            }
        });
    }

    /** Waktu produksi dipatok, supaya tidak perlu membangun OP + timer palsu. */
    private function stubWaktu(float $secPerUnit): void
    {
        $row = [
            'product'       => ['id' => $this->productId, 'sku' => 'UJI-1', 'name' => 'Produk Uji'],
            'qty_per_cycle' => 1.0,
            'included_count' => 3,
            'per_division'  => [
                $this->cnc => [
                    'department'    => ['id' => $this->cnc, 'name' => 'CNC', 'type' => 'produksi'],
                    'sec_per_cycle' => $secPerUnit,
                    'sec_per_unit'  => $secPerUnit,
                    'n'             => 3,
                ],
            ],
            'total' => ['sec_per_cycle' => $secPerUnit, 'sec_per_unit' => $secPerUnit],
        ];

        $this->app->instance(ProductionTimeAnalysisService::class, new class([$this->productId => $row]) extends ProductionTimeAnalysisService {
            public function __construct(private array $rows)
            {
            }

            public function perProduct(array $filters = []): Collection
            {
                return collect($this->rows);
            }

            public function perProductSampleOrderIds(array $filters = []): array
            {
                return [];
            }
        });
    }
}
