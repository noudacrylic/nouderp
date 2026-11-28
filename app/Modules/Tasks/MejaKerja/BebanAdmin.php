<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Models\User;
use App\Modules\Production\Services\ExecutorScheduleResolver;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Carbon;

/**
 * Seberapa sibuk seorang admin SEKARANG, dibaca dari kartu Meja Kerja + absensi SDM.
 * Disepakati owner 21 Nov 2026 (Akrilik Sign); ANTRE_MAKS dinaikkan 3 → 8 pada 28 Nov (Farid biasa memegang ±8 kartu):
 *
 *   Longgar  = sudah absen masuk • kartu Antre (open + sedang dikerjakan) ≤ ANTRE_MAKS
 *              • tak ada kartu lewat tenggat • sisa jam kerja ≥ SISA_MENIT_MIN
 *   Nganggur = sudah absen masuk • Antre kosong • sisa jam kerja ≥ SISA_MENIT_MIN
 *
 * Kartu Pending tidak dihitung (menunggu orang lain, bukan beban kerja saat ini). Akun tanpa
 * karyawan_id dianggap hadir (sama dengan KehadiranAdmin) dengan jam pulang JAM_PULANG_BAWAAN.
 */
class BebanAdmin
{
    public const ANTRE_MAKS        = 8;
    public const SISA_MENIT_MIN    = 60;
    public const JAM_PULANG_BAWAAN = '16:00';

    public function __construct(private ExecutorScheduleResolver $jadwal) {}

    /** @return array{hadir: bool, antre: int, lewat: int, sisa_menit: int, nama: string} */
    public function keadaan(int $userId, ?Carbon $saat = null): array
    {
        $saat ??= now();
        $user = User::find($userId);
        [$hadir, $pulang] = $this->hadirDanPulang($user, $saat);

        $kartu = Task::where('assignee_user_id', $userId)->whereNotNull('peran')
            ->whereIn('status', ['open', 'in_progress'])->get(['id', 'tenggat_at']);

        return [
            'hadir'      => $hadir,
            'antre'      => $kartu->count(),
            'lewat'      => $kartu->filter(fn ($t) => $t->tenggat_at && $t->tenggat_at->lt($saat))->count(),
            'sisa_menit' => $hadir && $pulang ? max(0, (int) floor($saat->diffInMinutes($pulang, false))) : 0,
            'nama'       => $user?->name ?? "#$userId",
        ];
    }

    public function longgar(array $k): bool
    {
        return $k['hadir'] && $k['antre'] <= self::ANTRE_MAKS && $k['lewat'] === 0 && $k['sisa_menit'] >= self::SISA_MENIT_MIN;
    }

    public function nganggur(array $k): bool
    {
        return $k['hadir'] && $k['antre'] === 0 && $k['sisa_menit'] >= self::SISA_MENIT_MIN;
    }

    /** Ringkas untuk jurnal: "Farid: 2 kartu antre, 0 lewat tenggat, sisa 3 jam". */
    public function ringkas(array $k): string
    {
        if (!$k['hadir']) return "{$k['nama']}: belum absen masuk / libur";
        $sisa = $k['sisa_menit'] >= 60 ? intdiv($k['sisa_menit'], 60) . ' jam ' . ($k['sisa_menit'] % 60) . ' mnt' : $k['sisa_menit'] . ' mnt';
        return "{$k['nama']}: {$k['antre']} kartu antre, {$k['lewat']} lewat tenggat, sisa jam kerja $sisa";
    }

    /** @return array{0: bool, 1: ?Carbon} */
    private function hadirDanPulang(?User $user, Carbon $saat): array
    {
        if (!$user) return [false, null];
        $bawaan = $saat->copy()->setTimeFromTimeString(self::JAM_PULANG_BAWAAN);
        if (!$user->karyawan_id) return [true, $bawaan];

        $jadwal = $this->jadwal->forKaryawan((int) $user->karyawan_id, $saat);
        if ($jadwal && $jadwal->is_off) return [false, null];

        $scan = FingerprintLog::where('karyawan_id', $user->karyawan_id)
            ->whereBetween('scan_at', [$saat->copy()->startOfDay(), $saat])->exists();
        if (!$scan) return [false, null];

        $pulang = $jadwal?->jam_pulang ? $saat->copy()->setTimeFromTimeString((string) $jadwal->jam_pulang) : $bawaan;
        return [true, $pulang];
    }
}
