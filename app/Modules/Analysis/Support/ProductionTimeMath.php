<?php

namespace App\Modules\Analysis\Support;

/**
 * Matematika analisa waktu produksi — MURNI: tanpa Eloquent, request, session, atau waktu sekarang.
 *
 * Sengaja dipisah dari service supaya:
 *  1. bisa diuji tanpa database (tests/Unit/Analysis/ProductionTimeMathTest.php);
 *  2. Tahap 2 (kapasitas & HPP) memakai rumus yang sama persis tanpa lapisan query.
 *
 * Urutan rumus yang WAJIB dipertahankan: bagi jumlah siklus DULU, baru dirata-rata.
 * Contoh: 3600 dtk/10 siklus dan 1200 dtk/2 siklus → 360 & 600 → rata-rata 480,
 * BUKAN (3600+1200)/(10+2) = 400.
 */
final class ProductionTimeMath
{
    /**
     * Detik per divisi → detik per siklus per divisi.
     *
     * @param  array<int,float|int>  $secByDept  [department_id => detik]
     * @return array<int,float>                  [department_id => detik/siklus]
     */
    public static function secPerCycle(array $secByDept, float $cycles): array
    {
        if ($cycles <= 0) {
            return [];
        }

        $out = [];
        foreach ($secByDept as $deptId => $sec) {
            $out[$deptId] = (float) $sec / $cycles;
        }

        return $out;
    }

    /**
     * Rata-rata detik/siklus per divisi, lintas sampel.
     *
     * Divisi yang TIDAK ada di suatu sampel tidak dianggap 0 — sampel itu hanya
     * tidak ikut menghitung divisi tersebut (lihat `n`). Ini penting karena tidak
     * semua OP melewati semua divisi.
     *
     * @param  array<int,array{sec_per_cycle: array<int,float>}>  $samples
     * @return array<int,array{avg: float, n: int}>               [department_id => …]
     */
    public static function averagePerDivision(array $samples): array
    {
        $sum = [];
        $n   = [];

        foreach ($samples as $sample) {
            foreach ($sample['sec_per_cycle'] ?? [] as $deptId => $val) {
                $sum[$deptId] = ($sum[$deptId] ?? 0.0) + (float) $val;
                $n[$deptId]   = ($n[$deptId] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($sum as $deptId => $total) {
            $out[$deptId] = ['avg' => $total / $n[$deptId], 'n' => $n[$deptId]];
        }

        return $out;
    }

    /**
     * Tentukan qty_per_cycle produk dari BOM yang dipakai sampel.
     *
     * Aturan: BOM yang paling sering muncul menang; bila seri, bom_id terbesar
     * (paling baru) yang dipakai. Sampel tanpa BOM tidak ikut memilih.
     *
     * @param  array<int,array{bom_id: int|null, qty_per_cycle: float|null, bom_number?: ?string, bom_name?: ?string}>  $samples
     * @return array{qty_per_cycle: ?float, source: ?array, conflicts: array<int,array>}
     */
    public static function resolveQtyPerCycle(array $samples): array
    {
        $voters = array_filter(
            $samples,
            fn ($s) => !empty($s['bom_id']) && ($s['qty_per_cycle'] ?? 0) > 0
        );

        if (empty($voters)) {
            return ['qty_per_cycle' => null, 'source' => null, 'conflicts' => []];
        }

        // Kelompokkan per bom_id
        $byBom = [];
        foreach ($voters as $s) {
            $bomId = (int) $s['bom_id'];
            if (!isset($byBom[$bomId])) {
                $byBom[$bomId] = [
                    'bom_id'        => $bomId,
                    'bom_number'    => $s['bom_number'] ?? null,
                    'bom_name'      => $s['bom_name'] ?? null,
                    'qty_per_cycle' => (float) $s['qty_per_cycle'],
                    'votes'         => 0,
                ];
            }
            $byBom[$bomId]['votes']++;
        }

        // Terbanyak menang; seri → bom_id terbesar
        $sorted = array_values($byBom);
        usort($sorted, function ($a, $b) {
            return $b['votes'] <=> $a['votes'] ?: $b['bom_id'] <=> $a['bom_id'];
        });

        $chosen = $sorted[0];
        $chosen['total_voters'] = count($voters);

        // Konflik = BOM lain yang nilainya BEDA (BOM beda tapi nilai sama bukan masalah)
        $conflicts = array_values(array_filter(
            array_slice($sorted, 1),
            fn ($b) => abs($b['qty_per_cycle'] - $chosen['qty_per_cycle']) > 0.00001
        ));

        return [
            'qty_per_cycle' => $chosen['qty_per_cycle'],
            'source'        => $chosen,
            'conflicts'     => $conflicts,
        ];
    }

    /** Detik/siklus → detik/unit. Null bila qty_per_cycle tidak diketahui. */
    public static function perUnit(?float $secPerCycle, ?float $qtyPerCycle): ?float
    {
        if ($secPerCycle === null || $qtyPerCycle === null || $qtyPerCycle <= 0) {
            return null;
        }

        return $secPerCycle / $qtyPerCycle;
    }

    /** @param array<int,float|int> $values */
    public static function median(array $values): ?float
    {
        $values = array_values(array_map('floatval', $values));
        if (empty($values)) {
            return null;
        }

        sort($values);
        $count = count($values);
        $mid   = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Tandai sampel yang menyimpang jauh dari median — HANYA petunjuk visual,
     * tidak pernah otomatis membuang sampel (pembuangan tetap keputusan operator).
     *
     * @param  array<int|string,float>  $totalSecPerCycleById  [order_id => total detik/siklus]
     * @return array<int|string,bool>                          [order_id => menyimpang?]
     */
    public static function outlierFlags(array $totalSecPerCycleById, float $factor = 2.0): array
    {
        $median = self::median($totalSecPerCycleById);
        $out    = [];

        foreach ($totalSecPerCycleById as $id => $val) {
            $out[$id] = ($median === null || $median <= 0)
                ? false
                : ($val > $median * $factor || $val < $median / $factor);
        }

        return $out;
    }
}
