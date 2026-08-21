<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Models\ProductionTimeAssumption;
use App\Modules\Analysis\Services\ProductionQuotaService;
use App\Modules\Analysis\Services\ProductionTimeInsightService;
use App\Modules\Production\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lapisan analisa di atas Waktu Produksi.
 *
 * Yang dijaga:
 *
 *  1. **Asumsi hanya berlaku bila dicentang**, dan angka terukur tidak pernah ditimpa. Kalau
 *     suatu hari asumsi bocor ke kolom terukur, tidak akan ada yang bisa membedakan lagi mana
 *     yang diukur dan mana yang dikarang.
 *  2. **Kapasitas ditentukan divisi terlambat.** Rata-rata akan melebihkan kapasitas: orang
 *     assembling tidak bisa mengerjakan CNC, jadi divisi tercepat tidak bisa menutupi yang lambat.
 *  3. **Divisi yang belum pernah terukur tetap bisa ditambal asumsi** — justru produk seperti itu
 *     yang paling membutuhkannya.
 */
class ProductionTimeInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Dibaca oleh stub kapasitas — id divisi baru diketahui setelah fixture dibuat. */
    public static array $kapasitas = [];

    private ProductionTimeInsightService $svc;
    private int $cnc;
    private int $asm;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cnc = Department::create(['code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true])->id;
        $this->asm = Department::create(['code' => 'PRD-002', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true])->id;

        $this->productId = DB::table('products')->insertGetId([
            'sku' => 'UJI-1', 'name' => 'Produk Uji', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3 mesin × 7 jam = 21 jam-slot/hari · 2 orang × 7 jam = 14 jam-slot/hari
        static::$kapasitas = [
            $this->cnc => ['slot_count' => 3, 'hours_per_day' => 21, 'seconds_per_day' => 21 * 3600, 'working_days' => 24],
            $this->asm => ['slot_count' => 2, 'hours_per_day' => 14, 'seconds_per_day' => 14 * 3600, 'working_days' => 24],
        ];

        // Kapasitas di-stub: yang diuji di sini penggabungan waktu x kapasitas, bukan cara
        // menghitung kapasitasnya (itu punya tesnya sendiri di ProductionQuotaServiceTest).
        $this->app->instance(ProductionQuotaService::class, new class extends ProductionQuotaService {
            public function __construct()
            {
            }

            public function capacityPerDay(array $filters = []): array
            {
                return ProductionTimeInsightServiceTest::$kapasitas;
            }
        });

        $this->svc = app(ProductionTimeInsightService::class);
    }

    // ==========================================================
    // KAPASITAS
    // ==========================================================

    public function test_kapasitas_dihitung_per_divisi_dan_ditentukan_yang_terlambat(): void
    {
        // CNC 30 menit/unit → 21 jam ÷ 0,5 jam = 42/hari
        // Assembling 60 menit/unit → 14 jam ÷ 1 jam = 14/hari
        $row = $this->enrich([$this->cnc => 1800, $this->asm => 3600]);

        $this->assertEqualsWithDelta(42.0, $row['per_division'][$this->cnc]['capacity_per_day'], 0.01);
        $this->assertEqualsWithDelta(14.0, $row['per_division'][$this->asm]['capacity_per_day'], 0.01);

        $this->assertSame($this->asm, $row['bottleneck_id']);
        $this->assertEqualsWithDelta(14.0, $row['capacity_per_day'], 0.01);
        $this->assertNotEqualsWithDelta(28.0, $row['capacity_per_day'], 0.01, 'Rata-rata akan melebihkan kapasitas dua kali lipat.');
    }

    public function test_kapasitas_bulanan_memakai_hari_kerja_divisi_penentu(): void
    {
        $row = $this->enrich([$this->cnc => 1800, $this->asm => 3600]);

        $this->assertEqualsWithDelta(14.0 * 24, $row['capacity_per_month'], 0.1);
    }

    // ==========================================================
    // ASUMSI
    // ==========================================================

    public function test_asumsi_diabaikan_selama_belum_dicentang(): void
    {
        $this->asumsi($this->asm, 900, false);   // 15 menit, jauh lebih cepat

        $row = $this->enrich([$this->cnc => 1800, $this->asm => 3600]);

        $this->assertEqualsWithDelta(3600.0, $row['per_division'][$this->asm]['sec_per_unit_effective'], 0.01);
        $this->assertEqualsWithDelta(14.0, $row['capacity_per_day'], 0.01);
        $this->assertEqualsWithDelta(900.0, $row['per_division'][$this->asm]['assumed'], 0.01, 'Angkanya tetap tersimpan supaya bisa dinyalakan tanpa mengetik ulang.');
        $this->assertFalse($row['has_assumption']);
    }

    public function test_asumsi_yang_dicentang_menggeser_penentu_tanpa_menghapus_angka_terukur(): void
    {
        $this->asumsi($this->asm, 900, true);

        $row = $this->enrich([$this->cnc => 1800, $this->asm => 3600]);
        $asm = $row['per_division'][$this->asm];

        $this->assertEqualsWithDelta(900.0, $asm['sec_per_unit_effective'], 0.01);
        $this->assertEqualsWithDelta(56.0, $asm['capacity_per_day'], 0.01, '14 jam ÷ 15 menit.');
        $this->assertSame($this->cnc, $row['bottleneck_id'], 'Assembling jadi cepat, CNC yang membatasi.');
        $this->assertEqualsWithDelta(42.0, $row['capacity_per_day'], 0.01);

        // Inti "asumsi di samping data real": angka terukurnya tetap utuh dan bisa dibedakan.
        $this->assertEqualsWithDelta(3600.0, $asm['sec_per_unit'], 0.01);
        $this->assertTrue($asm['use_assumption']);
        $this->assertTrue($row['has_assumption']);
    }

    public function test_divisi_yang_belum_pernah_terukur_bisa_ditambal_asumsi(): void
    {
        $this->asumsi($this->cnc, 1800, true);

        // Produk ini belum pernah punya angka CNC sama sekali.
        $row = $this->enrich([$this->asm => 3600]);

        $this->assertNull($row['per_division'][$this->cnc]['sec_per_unit'], 'Yang terukur memang belum ada.');
        $this->assertEqualsWithDelta(1800.0, $row['per_division'][$this->cnc]['sec_per_unit_effective'], 0.01);
        $this->assertEqualsWithDelta(42.0, $row['per_division'][$this->cnc]['capacity_per_day'], 0.01);
        $this->assertEqualsWithDelta(14.0, $row['capacity_per_day'], 0.01);
    }

    public function test_total_waktu_efektif_menjumlah_asumsi_dan_terukur(): void
    {
        $this->asumsi($this->asm, 900, true);

        $row = $this->enrich([$this->cnc => 1800, $this->asm => 3600]);

        // CNC terukur 1800 + Assembling asumsi 900 = 2700, bukan 1800 + 3600.
        $this->assertEqualsWithDelta(2700.0, $row['sec_per_unit_effective'], 0.01);
    }

    // ==========================================================
    // Bantuan
    // ==========================================================

    /** @param array<int,float> $secPerUnit */
    private function enrich(array $secPerUnit): array
    {
        $perDivision = [];
        foreach ($secPerUnit as $deptId => $sec) {
            $perDivision[$deptId] = [
                'department'    => ['id' => $deptId, 'name' => 'Divisi ' . $deptId, 'type' => 'produksi'],
                'sec_per_cycle' => (float) $sec,
                'sec_per_unit'  => (float) $sec,
                'n'             => 3,
            ];
        }

        return $this->svc->enrichOne([
            'product'      => ['id' => $this->productId, 'sku' => 'UJI-1', 'name' => 'Produk Uji'],
            'per_division' => $perDivision,
            'total'        => ['sec_per_cycle' => array_sum($secPerUnit), 'sec_per_unit' => array_sum($secPerUnit)],
        ]);
    }

    private function asumsi(int $deptId, float $detik, bool $pakai): void
    {
        ProductionTimeAssumption::create([
            'product_id'               => $this->productId,
            'department_id'            => $deptId,
            'assumed_seconds_per_unit' => $detik,
            'use_assumption'           => $pakai,
        ]);
    }
}
