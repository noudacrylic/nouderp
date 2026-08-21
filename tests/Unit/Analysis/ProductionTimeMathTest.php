<?php

namespace Tests\Unit\Analysis;

use App\Modules\Analysis\Support\ProductionTimeMath as Math;
use PHPUnit\Framework\TestCase;

/**
 * Test murni tanpa database — ProductionTimeMath sengaja bebas Eloquent.
 * Jalankan: php artisan test --filter=ProductionTimeMathTest
 */
class ProductionTimeMathTest extends TestCase
{
    public function test_sec_per_cycle_membagi_tiap_divisi_dengan_jumlah_siklus(): void
    {
        $this->assertSame(
            [3 => 540.0, 5 => 720.0],
            Math::secPerCycle([3 => 5400, 5 => 7200], 10)
        );
    }

    public function test_siklus_nol_tidak_melempar_dan_menghasilkan_kosong(): void
    {
        $this->assertSame([], Math::secPerCycle([3 => 5400], 0));
        $this->assertSame([], Math::secPerCycle([3 => 5400], -2));
    }

    /**
     * Inti rumus yang diminta: BAGI SIKLUS DULU, baru rata-rata.
     * 3600/10 = 360 dan 1200/2 = 600 → rata-rata 480.
     * Kalau salah urutan: (3600+1200)/(10+2) = 400.
     */
    public function test_bagi_siklus_dulu_baru_rata_rata(): void
    {
        $samples = [
            ['sec_per_cycle' => Math::secPerCycle([3 => 3600], 10)],
            ['sec_per_cycle' => Math::secPerCycle([3 => 1200], 2)],
        ];

        $avg = Math::averagePerDivision($samples);

        $this->assertSame(480.0, $avg[3]['avg']);
        $this->assertNotSame(400.0, $avg[3]['avg']);
        $this->assertSame(2, $avg[3]['n']);
    }

    public function test_divisi_yang_absen_di_satu_sampel_tidak_dianggap_nol(): void
    {
        $samples = [
            ['sec_per_cycle' => [3 => 600.0, 5 => 300.0]],
            ['sec_per_cycle' => [3 => 400.0]], // divisi 5 tidak dilewati OP ini
        ];

        $avg = Math::averagePerDivision($samples);

        $this->assertSame(500.0, $avg[3]['avg']);
        $this->assertSame(2, $avg[3]['n']);
        $this->assertSame(300.0, $avg[5]['avg']); // bukan 150.0
        $this->assertSame(1, $avg[5]['n']);
    }

    public function test_qty_per_cycle_diambil_dari_bom_terbanyak_dipakai(): void
    {
        $samples = [
            ['bom_id' => 7, 'qty_per_cycle' => 12.0, 'bom_number' => 'BOM-0007'],
            ['bom_id' => 7, 'qty_per_cycle' => 12.0, 'bom_number' => 'BOM-0007'],
            ['bom_id' => 7, 'qty_per_cycle' => 12.0, 'bom_number' => 'BOM-0007'],
            ['bom_id' => 9, 'qty_per_cycle' => 10.0, 'bom_number' => 'BOM-0009'],
        ];

        $res = Math::resolveQtyPerCycle($samples);

        $this->assertSame(12.0, $res['qty_per_cycle']);
        $this->assertSame(7, $res['source']['bom_id']);
        $this->assertSame(3, $res['source']['votes']);
        $this->assertSame(4, $res['source']['total_voters']);
        $this->assertCount(1, $res['conflicts']);
        $this->assertSame(9, $res['conflicts'][0]['bom_id']);
    }

    public function test_suara_seri_dimenangkan_bom_id_terbaru(): void
    {
        $samples = [
            ['bom_id' => 7, 'qty_per_cycle' => 12.0],
            ['bom_id' => 9, 'qty_per_cycle' => 10.0],
        ];

        $res = Math::resolveQtyPerCycle($samples);

        $this->assertSame(9, $res['source']['bom_id']);
        $this->assertSame(10.0, $res['qty_per_cycle']);
    }

    public function test_bom_berbeda_dengan_nilai_sama_bukan_konflik(): void
    {
        $samples = [
            ['bom_id' => 7, 'qty_per_cycle' => 12.0],
            ['bom_id' => 7, 'qty_per_cycle' => 12.0],
            ['bom_id' => 9, 'qty_per_cycle' => 12.0],
        ];

        $this->assertSame([], Math::resolveQtyPerCycle($samples)['conflicts']);
    }

    public function test_semua_sampel_tanpa_bom_menghasilkan_null_tanpa_bagi_nol(): void
    {
        $res = Math::resolveQtyPerCycle([
            ['bom_id' => null, 'qty_per_cycle' => null],
            ['bom_id' => 4,    'qty_per_cycle' => 0.0],
        ]);

        $this->assertNull($res['qty_per_cycle']);
        $this->assertNull($res['source']);
        $this->assertNull(Math::perUnit(1260.0, $res['qty_per_cycle']));
        $this->assertNull(Math::perUnit(1260.0, 0.0));
        $this->assertNull(Math::perUnit(null, 12.0));
    }

    public function test_per_unit_membagi_dengan_hasil_per_siklus(): void
    {
        $this->assertSame(105.0, Math::perUnit(1260.0, 12.0));
    }

    public function test_median(): void
    {
        $this->assertNull(Math::median([]));
        $this->assertSame(1000.0, Math::median([1000]));
        $this->assertSame(1000.0, Math::median([500, 1000, 3000]));
        $this->assertSame(750.0, Math::median([500, 1000]));
    }

    public function test_outlier_flags_menandai_yang_menyimpang_dua_kali_median(): void
    {
        $flags = Math::outlierFlags([
            101 => 1000.0,
            102 => 1100.0,
            103 => 2500.0, // > 2× median
            104 => 400.0,  // < median/2
        ]);

        $this->assertFalse($flags[101]);
        $this->assertFalse($flags[102]);
        $this->assertTrue($flags[103]);
        $this->assertTrue($flags[104]);
    }
}
