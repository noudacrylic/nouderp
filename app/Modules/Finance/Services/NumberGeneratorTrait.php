<?php

namespace App\Modules\Finance\Services;

trait NumberGeneratorTrait
{
    /**
     * Generate sequential number with prefix and yearly reset.
     * Example: CD-2026-0001
     */
    protected function generateNumber(string $modelClass, string $prefix, string $field = 'number'): string
    {
        $year = now()->format('Y');
        $base = "{$prefix}-{$year}-";

        $latest = $modelClass::where($field, 'LIKE', $base . '%')
            ->orderByDesc($field)
            ->value($field);

        $next = $latest ? ((int) substr($latest, strlen($base))) + 1 : 1;
        return $base . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
