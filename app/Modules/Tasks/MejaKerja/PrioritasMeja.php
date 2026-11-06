<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Collection;

/**
 * Urutan kartu sebuah meja menurut config('meja_prioritas.urutan') — kategori dulu, lalu tenggat
 * terdekat, lalu yang paling lama menunggu. Dipakai skill "Prioritas & pengganti admin"; selama
 * skill itu Nonaktif, Meja Kerja kembali ke skor lama (bobot × urgensi).
 */
class PrioritasMeja
{
    /** Kategori kartu; null = bukan kartu skill (task manual) — diurutkan setelah kategori dikenal. */
    public static function kategori(Task $t): ?string
    {
        if ($t->skill === 'retur') {
            $m = $t->meta ?? [];
            $mendesak = ($m['fase'] ?? null) === 'periksa' && $t->tenggatDariLuar()
                && $t->tenggat_at && $t->tenggat_at->lt(now()->addDay());

            return $mendesak ? 'retur-24j' : 'retur';
        }
        if ($t->skill === 'tugas-owner') {
            return 'owner-' . (($t->meta['prioritas'] ?? 'normal') ?: 'normal');
        }

        return $t->skill;
    }

    /** Peringkat kategori di meja $meja; makin kecil makin atas. */
    public static function peringkat(Task $t, string $meja): int
    {
        $daftar = (array) config("meja_prioritas.urutan.$meja", []);
        $k = self::kategori($t);
        $i = $k === null ? false : array_search($k, $daftar, true);
        if ($i !== false) return $i * 100;

        // Belum disebut (skill baru / task manual): tepat sebelum owner-rendah, urut bobot skill.
        $rendah = array_search('owner-rendah', $daftar, true);
        $dasar  = ($rendah === false ? count($daftar) : $rendah) * 100 - 50;
        $bobot  = $t->skill ? (int) (MejaKerjaSkill::where('skill', $t->skill)->value('bobot') ?? 5) : 0;

        return $dasar - $bobot;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    public static function urutkan(Collection $tasks, string $meja): Collection
    {
        return $tasks->sortBy(fn (Task $t) => [
            self::peringkat($t, $meja),
            $t->tenggat_at?->getTimestamp() ?? PHP_INT_MAX,
            $t->created_at?->getTimestamp() ?? 0,
        ])->values();
    }
}
