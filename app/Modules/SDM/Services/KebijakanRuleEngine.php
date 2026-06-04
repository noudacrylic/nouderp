<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\KebijakanKolom;
use App\Modules\SDM\Models\KebijakanRule;
use App\Modules\SDM\Models\KebijakanSummary;

class KebijakanRuleEngine
{
    protected ?array $rulesCache = null;
    protected ?array $kolomKeysCache = null;
    protected ?array $summaryKeysCache = null;

    public function rules(): array
    {
        if ($this->rulesCache === null) {
            $this->rulesCache = KebijakanRule::aktif()->ordered()->get()->all();
        }
        return $this->rulesCache;
    }

    protected function kolomKeys(): array
    {
        if ($this->kolomKeysCache === null) {
            $this->kolomKeysCache = KebijakanKolom::pluck('key')->all();
        }
        return $this->kolomKeysCache;
    }

    protected function summaryKeys(): array
    {
        if ($this->summaryKeysCache === null) {
            $this->summaryKeysCache = KebijakanSummary::pluck('key')->all();
        }
        return $this->summaryKeysCache;
    }

    /**
     * Hitung nilai semua KOLOM (per-row absensi).
     *
     * @return array kolom_key => nilai
     */
    public function applyRow(array $row, Karyawan $karyawan, float $gajiPerHari = 0.0): array
    {
        $ctx = $this->buildRowContext($row, $karyawan);
        return $this->evaluate($ctx, $this->kolomKeys(), $gajiPerHari);
    }

    /**
     * Hitung nilai semua SUMMARY mode=auto (per-bulan-per-karyawan).
     * Context hanya field karyawan-level (sanksi).
     *
     * @return array summary_key => nilai
     */
    public function applySummaryAuto(Karyawan $karyawan, float $gajiPerHari = 0.0): array
    {
        $ctx = $this->buildSummaryContext($karyawan);
        return $this->evaluate($ctx, $this->summaryKeys(), $gajiPerHari);
    }

    protected function evaluate(array $ctx, array $allowedKeys, float $gajiPerHari): array
    {
        $result = [];
        foreach ($this->rules() as $rule) {
            if (! $this->matchAll($rule->conditions ?? [], $ctx)) {
                continue;
            }
            foreach ($rule->effects ?? [] as $eff) {
                $key = $eff['kolom'] ?? null;
                if (! $key || ! in_array($key, $allowedKeys, true)) continue;
                if (array_key_exists($key, $result)) continue;
                $result[$key] = $this->resolveEffect($eff, $gajiPerHari);
            }
        }
        return $result;
    }

    protected function buildRowContext(array $row, Karyawan $karyawan): array
    {
        return [
            'status'             => $row['status'] ?? null,
            'scan_masuk_menit'   => $this->toMinutes($row['reg_datang'] ?? null),
            'scan_pulang_menit'  => $this->toMinutes($row['reg_pulang'] ?? null),
            'sanksi'             => (string) ($karyawan->sanksi ?? 'SP0'),
            'lembur_jam'         => (float) ($row['lembur_jam'] ?? 0),
            'is_libur_nasional'  => (bool) ($row['is_holiday'] ?? false),
            'is_libur_mingguan'  => (bool) ($row['is_off'] ?? false),
        ];
    }

    protected function buildSummaryContext(Karyawan $karyawan): array
    {
        // Hanya field karyawan-level yang valid untuk evaluasi summary per-bulan
        return [
            'sanksi'             => (string) ($karyawan->sanksi ?? 'SP0'),
            // field per-row di-null kan supaya kondisi yang merujuk ke field per-hari tidak match
            'status'             => null,
            'scan_masuk_menit'   => null,
            'scan_pulang_menit'  => null,
            'lembur_jam'         => 0.0,
            'is_libur_nasional'  => false,
            'is_libur_mingguan'  => false,
        ];
    }

    protected function matchAll(array $conditions, array $ctx): bool
    {
        foreach ($conditions as $c) {
            if (! $this->matchOne($c, $ctx)) {
                return false;
            }
        }
        return true;
    }

    protected function matchOne(array $cond, array $ctx): bool
    {
        $field = $cond['field'] ?? null;
        $op    = $cond['op']    ?? 'eq';
        $val   = $cond['value'] ?? null;

        if (! array_key_exists($field, $ctx)) return false;
        $actual = $ctx[$field];

        if (in_array($field, ['scan_masuk_menit', 'scan_pulang_menit'], true)) {
            $val = $this->valueAsMinutes($val);
            if ($actual === null) return false;
        }

        if (is_bool($actual)) {
            $val = filter_var($val, FILTER_VALIDATE_BOOL);
        }

        switch ($op) {
            case 'eq':  return $actual == $val;
            case 'neq': return $actual != $val;
            case 'gt':  return is_numeric($actual) && is_numeric($val) && $actual >  (float) $val;
            case 'gte': return is_numeric($actual) && is_numeric($val) && $actual >= (float) $val;
            case 'lt':  return is_numeric($actual) && is_numeric($val) && $actual <  (float) $val;
            case 'lte': return is_numeric($actual) && is_numeric($val) && $actual <= (float) $val;
            case 'in':  return is_array($val) && in_array($actual, $val, false);
            default:    return false;
        }
    }

    protected function resolveEffect(array $eff, float $gajiPerHari)
    {
        $kind  = $eff['kind']  ?? 'nominal';
        $value = $eff['value'] ?? 0;

        switch ($kind) {
            case 'nominal':
                return (float) $value;
            case 'persen_gaji_hari':
                return round($gajiPerHari * ((float) $value / 100), 2);
            case 'flag':
                return $value !== null && $value !== '' ? (string) $value : true;
            default:
                return 0;
        }
    }

    protected function toMinutes(?string $hhmm): ?int
    {
        if (! $hhmm) return null;
        $parts = explode(':', $hhmm);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        return $h * 60 + $m;
    }

    protected function valueAsMinutes($val): ?int
    {
        if ($val === null) return null;
        if (is_numeric($val)) return (int) $val;
        if (is_string($val) && str_contains($val, ':')) return $this->toMinutes($val);
        return (int) $val;
    }
}
