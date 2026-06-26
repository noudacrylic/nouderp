<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use Illuminate\Support\Collection;

/**
 * Logika tampilan Home PWA Karyawan: pesan status hari ini (kontekstual) +
 * metrik kehadiran bulan berjalan. TANPA rupiah (uang hanya di Slip Gaji).
 *
 * Sumber kebenaran absensi tetap fingerprint (sdm_attendance) — read-only,
 * tidak memfabrikasi kehadiran (lih. memori project_absensi_log_only_2026_06_17).
 */
class KaryawanHomeService
{
    /** Status yang dihitung sebagai "hadir" untuk metrik. */
    private const HADIR_STATUSES = ['hadir', 'terlambat', 'setengah_hari', 'pulang_awal'];

    /**
     * Pesan status hari ini berdasarkan baris absensi (nullable) + jam saat ini.
     * Return: ['icon','title','message','tone'] (tone: green|amber|red|slate|blue).
     */
    public function todayMessage(?Attendance $today): array
    {
        $jamMasuk  = $today?->on_work1;
        $jamPulang = $this->lastOut($today);
        $masukStr  = $jamMasuk ? substr($jamMasuk, 0, 5) : null;
        $pulangStr = $jamPulang ? substr($jamPulang, 0, 5) : null;

        $status = $today?->status;

        return match (true) {
            $status === 'libur' => [
                'icon' => '🌴', 'tone' => 'blue',
                'title' => 'Hari libur', 'message' => 'Selamat beristirahat.',
            ],
            $status === 'cuti' => [
                'icon' => '✈️', 'tone' => 'blue',
                'title' => 'Sedang cuti', 'message' => 'Hari ini tercatat cuti.',
            ],
            $status === 'sakit' => [
                'icon' => '🤒', 'tone' => 'amber',
                'title' => 'Izin sakit', 'message' => 'Lekas sembuh, hari ini tercatat sakit.',
            ],
            // ── Hari MASIH BERJALAN: sudah absen masuk, belum scan pulang ──
            // Jangan beri vonis (setengah hari/terlambat) selagi hari belum selesai —
            // tampilkan apresiasi yang menyemangati.
            $jamMasuk && ! $jamPulang => [
                'icon' => '☀️', 'tone' => 'green',
                'title' => 'Sudah absen masuk',
                'message' => "Tercatat masuk pukul {$masukStr}. Selamat bekerja, semangat hari ini! 💪",
            ],

            // ── Hari sudah selesai (sudah scan pulang) ──
            $jamMasuk && $jamPulang && $status === 'setengah_hari' => [
                'icon' => '🌗', 'tone' => 'amber',
                'title' => 'Setengah hari',
                'message' => "Masuk {$masukStr} · pulang {$pulangStr}.",
            ],
            $jamMasuk && $jamPulang => [
                'icon' => '👋', 'tone' => 'green',
                'title' => 'Sudah pulang',
                'message' => "Masuk {$masukStr} · pulang {$pulangStr}. Terima kasih hari ini!",
            ],

            // Hanya tercatat scan pulang (jarang — scan masuk kelewat).
            $jamPulang => [
                'icon' => '✅', 'tone' => 'green',
                'title' => 'Absensi tercatat',
                'message' => "Tercatat pulang pukul {$pulangStr}.",
            ],

            default => [
                'icon' => '🕗', 'tone' => 'slate',
                'title' => 'Belum absen',
                'message' => 'Belum ada catatan absensi masuk hari ini. Jangan lupa scan, ya!',
            ],
        };
    }

    /**
     * Metrik kehadiran bulan berjalan dari koleksi absensi.
     * Return: ['hari_hadir','jam_kerja','jam_lembur','tepat_waktu','terlambat','tidak_hadir'].
     */
    public function monthMetrics(Collection $attendances): array
    {
        $hadir = $attendances->whereIn('status', self::HADIR_STATUSES);

        return [
            'hari_hadir'  => $hadir->count(),
            'jam_kerja'   => round((float) $attendances->sum('working_hours'), 1),
            'jam_lembur'  => round((float) $attendances->sum('overtime_hours'), 1),
            'tepat_waktu' => $attendances->where('status', 'hadir')
                                ->where('late_minutes', 0)
                                ->filter(fn ($a) => !empty($a->on_work1))
                                ->count(),
            'terlambat'   => $attendances->where('status', 'terlambat')->count(),
            'tidak_hadir' => $attendances->where('status', 'tidak_hadir')->count(),
        ];
    }

    /** Jam pulang terakhir yang tercatat (off_work3 → 2 → 1). */
    private function lastOut(?Attendance $a): ?string
    {
        if (!$a) return null;
        return $a->off_work3 ?: ($a->off_work2 ?: $a->off_work1);
    }

    /** Label + warna badge untuk status absensi (dipakai daftar Absensi). */
    public static function statusBadge(?string $status): array
    {
        return match ($status) {
            'hadir'         => ['Hadir', 'bg-green-100 text-green-700'],
            'terlambat'     => ['Terlambat', 'bg-amber-100 text-amber-700'],
            'setengah_hari' => ['Setengah hari', 'bg-orange-100 text-orange-700'],
            'pulang_awal'   => ['Pulang awal', 'bg-orange-100 text-orange-700'],
            'tidak_hadir'   => ['Tidak hadir', 'bg-red-100 text-red-700'],
            'libur'         => ['Libur', 'bg-blue-100 text-blue-700'],
            'cuti'          => ['Cuti', 'bg-indigo-100 text-indigo-700'],
            'sakit'         => ['Sakit', 'bg-yellow-100 text-yellow-700'],
            default         => ['—', 'bg-slate-100 text-slate-500'],
        };
    }
}
