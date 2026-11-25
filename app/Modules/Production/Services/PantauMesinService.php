<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Mesin\RuidaKlien;
use App\Modules\Production\Models\DepartmentExecutor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pencatat keadaan mesin CNC dari sinyal pengontrol (25 Nov 2026). Riwayat disimpan sebagai
 * potongan keadaan berurutan per mesin di production_mesin_keadaan:
 *   potong = pekerjaan berjalan (bit status 0x1) · gerak = kepala bergerak tanpa pekerjaan
 *   (jog/frame) · diam · putus = pengontrol tidak menjawab.
 * Hanya perubahan keadaan yang menambah baris; selama sama cukup `dilihat_at` diperbarui.
 */
class PantauMesinService
{
    /** Lebih lama dari ini tanpa pembacaan = data terputus (potongan lama ditutup di dilihat terakhir). */
    public const BATAS_SENYAP_DETIK = 90;
    /** Perbarui dilihat_at paling sering tiap N detik supaya tulisan ke DB ringan. */
    public const SELANG_DILIHAT_DETIK = 15;

    /** @var array<int, array{0: ?int, 1: ?int}> posisi terakhir per mesin */
    private array $posisi = [];

    /** Mesin yang punya alamat sinyal. */
    public function mesin()
    {
        return DepartmentExecutor::whereNotNull('mesin_ip')->where('mesin_ip', '!=', '')
            ->where('is_active', true)->orderBy('name')->get();
    }

    public static function keadaan(?int $status, bool $bergerak): string
    {
        if ($status === null) return 'putus';
        if ($status & RuidaKlien::BIT_JALAN) return 'potong';

        return $bergerak ? 'gerak' : 'diam';
    }

    /** Baca satu mesin & catat keadaannya. */
    public function amati(DepartmentExecutor $mesin, RuidaKlien $klien, ?Carbon $now = null): string
    {
        $status = $klien->baca(RuidaKlien::ALAMAT_STATUS);
        $x = $status === null ? null : $klien->baca(RuidaKlien::ALAMAT_X);
        $y = $status === null ? null : $klien->baca(RuidaKlien::ALAMAT_Y);
        $lalu = $this->posisi[$mesin->id] ?? null;
        $bergerak = $x !== null && $lalu !== null && [$x, $y] !== $lalu;
        if ($x !== null) $this->posisi[$mesin->id] = [$x, $y];

        $k = self::keadaan($status, $bergerak);
        $this->catat($mesin->id, $k, $status, $now ?? now());

        return $k;
    }

    public function catat(int $executorId, string $keadaan, ?int $kode, Carbon $now): void
    {
        $buka = DB::table('production_mesin_keadaan')->where('executor_id', $executorId)
            ->whereNull('sampai_at')->orderByDesc('mulai_at')->orderByDesc('id')->first();

        if ($buka) {
            $dilihat = Carbon::parse($buka->dilihat_at ?? $buka->mulai_at);
            $senyap = $dilihat->diffInSeconds($now, false) > self::BATAS_SENYAP_DETIK;
            if (!$senyap && $buka->keadaan === $keadaan) {
                if ($dilihat->diffInSeconds($now, false) >= self::SELANG_DILIHAT_DETIK) {
                    DB::table('production_mesin_keadaan')->where('id', $buka->id)->update(['dilihat_at' => $now, 'kode' => $kode]);
                }
                return;
            }
            // Ganti keadaan: tutup di saat ini; bila sempat senyap, tutup di pembacaan terakhir
            // (selang tanpa data dibiarkan kosong — tidak ditebak).
            DB::table('production_mesin_keadaan')->where('id', $buka->id)
                ->update(['sampai_at' => $senyap ? $dilihat : $now, 'updated_at' => $now]);
        }

        DB::table('production_mesin_keadaan')->insert([
            'executor_id' => $executorId, 'keadaan' => $keadaan, 'kode' => $kode,
            'mulai_at' => $now, 'dilihat_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
