<?php

namespace App\Modules\Tasks\MejaKerja;

use App\Models\User;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\IzinRequest;
use Illuminate\Support\Carbon;

/**
 * Admin hari ini berangkat atau tidak — dibaca dari SDM lewat users.karyawan_id.
 *
 * Tidak berangkat = izin seharian (cuti/sakit/tukar hari) yang DISETUJUI, atau belum scan
 * fingerprint sampai config('meja_prioritas.jam_batas_scan'). Absen lewat PWA ikut tercatat
 * sebagai log fingerprint. Akun tanpa karyawan_id dianggap hadir: tanpa data kehadiran, kartunya
 * tidak boleh berpindah-pindah karena tebakan.
 */
class KehadiranAdmin
{
    /** @var array<int, bool> */
    private array $cache = [];

    /** $saat null = sekarang (hasilnya diingat selama satu jalan); jam lain selalu dihitung ulang. */
    public function tidakMasuk(int $userId, ?Carbon $saat = null): bool
    {
        if ($saat === null && array_key_exists($userId, $this->cache)) return $this->cache[$userId];

        $karyawanId = User::whereKey($userId)->value('karyawan_id');
        $hasil = $karyawanId ? $this->hitung((int) $karyawanId, $saat ?? now()) : false;
        if ($saat === null) $this->cache[$userId] = $hasil;

        return $hasil;
    }

    private function hitung(int $karyawanId, Carbon $saat): bool
    {
        $hari = $saat->toDateString();
        if ($this->scan($karyawanId, $saat)) return false;

        $izin = IzinRequest::where('karyawan_id', $karyawanId)->where('status', 'approved')
            ->whereIn('type', (array) config('meja_prioritas.izin_seharian', []))
            ->whereDate('tanggal', '<=', $hari)
            ->where(fn ($q) => $q->whereDate('tanggal_akhir', '>=', $hari)->orWhere(fn ($q) => $q->whereNull('tanggal_akhir')->whereDate('tanggal', $hari)))
            ->exists();
        if ($izin) return true;

        [$jam, $menit] = array_map('intval', explode(':', (string) config('meja_prioritas.jam_batas_scan', '09:00')) + [0, 0]);

        return $saat->gte($saat->copy()->setTime($jam, $menit));
    }

    private function scan(int $karyawanId, Carbon $saat): bool
    {
        return FingerprintLog::where('karyawan_id', $karyawanId)
            ->whereBetween('scan_at', [$saat->copy()->startOfDay(), $saat])->exists();
    }
}
