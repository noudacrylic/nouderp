<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\KaryawanSchedule;
use Carbon\Carbon;

class ExecutorScheduleResolver
{
    /**
     * Ingatan sepanjang HIDUP OBJEK INI — bukan cache lintas permintaan.
     *
     * Kalender Produksi memanggil resolver ini untuk tiap orang pada tiap tanggal, dan
     * Kuota Produksi memutar kalender itu sekali untuk SETIAP HARI dalam jendelanya.
     * Terukur sebelum ada ingatan ini: satu kali buka halaman HPP menembakkan
     * `sdm_karyawan_schedule where karyawan_id=? and day_of_week=?` sebanyak 1.236 kali —
     * jawabannya sama persis setiap kali, karena jadwal orang tidak berubah di tengah
     * satu permintaan.
     *
     * Jadwal & tukar-hari diambil SEKALI PER ORANG (7-an baris), lalu tanggalnya
     * dicocokkan di PHP. Scan sidik jari tetap per tanggal — jumlahnya bisa setahun
     * penuh, tidak layak diborong.
     *
     * Sengaja TIDAK didaftarkan sebagai singleton/scoped di container: objek ini juga
     * dipakai jalur timer produksi yang menulis lalu membaca lagi. Dengan ingatan yang
     * hanya sepanjang umur objek, penghematan tetap didapat di tempat yang memutar
     * ribuan kali (kalender), tanpa risiko jawaban basi di tempat yang menulis.
     */
    private array $memoJadwal = [];   // [karyawan_id => Collection<KaryawanSchedule>]
    private array $memoTukar  = [];   // [karyawan_id => Collection<AttendanceOverride>]
    private array $memoScan   = [];   // ["jenis:karyawan_id:tanggal" => hasil]

    /** Buang ingatan — dipakai bila objek yang sama dipakai lagi SESUDAH data diubah. */
    public function lupakanIngatan(): void
    {
        $this->memoJadwal = [];
        $this->memoTukar  = [];
        $this->memoScan   = [];
    }

    /** Seluruh baris jadwal mingguan satu orang (±7 baris). */
    protected function jadwalOrang(int $karyawanId)
    {
        return $this->memoJadwal[$karyawanId] ??= KaryawanSchedule::where('karyawan_id', $karyawanId)->get();
    }

    /** Seluruh override "tukar hari" satu orang. */
    protected function tukarHariOrang(int $karyawanId)
    {
        return $this->memoTukar[$karyawanId] ??= \App\Modules\SDM\Models\AttendanceOverride::query()
            ->where('karyawan_id', $karyawanId)
            ->where('type', 'tukar_hari')
            ->get();
    }

    /**
     * Jadwal RESMI seseorang pada satu tanggal: jadwal mingguan, dikoreksi tukar hari.
     *
     * Inilah yang berarti "hari ini dijadwalkan bekerja". Dipakai untuk menghitung KAPASITAS
     * (Kalender Produksi), karena kapasitas hanya lahir dari hari yang memang dijadwalkan —
     * bukan dari orang yang kebetulan mampir dan menempelkan jarinya.
     */
    public function scheduledFor(int $karyawanId, Carbon $date): ?KaryawanSchedule
    {
        $today = $this->jadwalOrang($karyawanId)
            ->firstWhere('day_of_week', (int) $date->dayOfWeek);

        $swap = $this->fullDaySwap($karyawanId, $date);
        if (!$swap) {
            return $today;
        }

        // Hari kerja yang ditukar KELUAR → dianggap libur. Salinan, tidak disimpan.
        if ($today && !$today->is_off) {
            $lepas = $today->replicate();
            $lepas->is_off = true;

            return $lepas;
        }

        // Hari libur yang ditukar MASUK → pinjam jam hari pasangannya.
        return $this->workingDaySchedule($karyawanId, $swap, $date) ?? $today;
    }

    /**
     * Jadwal untuk menentukan BOLEH-TIDAKNYA timer produksi jalan pada tanggal itu.
     *
     * Lebih longgar dari `scheduledFor()`: siapa pun yang sudah menempelkan jarinya hari itu
     * dianggap sedang bekerja, sekalipun harinya libur dan tidak ada surat apa pun.
     *
     * Kenapa longgar: sebelumnya `assertExecutorsReady()` menolak begitu `is_off` true, jadi
     * orang yang benar-benar masuk hari Minggu tidak bisa menekan Mulai sama sekali — dan
     * SELURUH pekerjaan hari itu lenyap dari data. Terjadi tiga kali: 28 Juni, 19 Juli, dan
     * 9 Agustus 2026. Menolak merekam tidak membuat pekerjaannya tidak terjadi; hanya membuat
     * kita buta terhadapnya.
     *
     * Jam yang dipinjam tetap jam hari kerja, supaya auto-pause tahu kapan harus berhenti.
     */
    public function forKaryawan(int $karyawanId, Carbon $date): ?KaryawanSchedule
    {
        $sched = $this->scheduledFor($karyawanId, $date);

        if ($sched && !$sched->is_off) {
            return $sched;
        }

        if (!$this->hasAnyScan($karyawanId, $date)) {
            return $sched;
        }

        return $this->workingDaySchedule($karyawanId, null, $date) ?? $sched;
    }

    /** Override "tukar hari" penuh yang menyentuh tanggal ini (dari sisi mana pun pasangannya). */
    protected function fullDaySwap(int $karyawanId, Carbon $date): ?object
    {
        $tgl = $date->toDateString();

        return $this->tukarHariOrang($karyawanId)->first(
            fn ($row) => $this->tanggalSama($row->tanggal, $tgl) || $this->tanggalSama($row->paired_date, $tgl)
        );
    }

    protected function hasAnyScan(int $karyawanId, Carbon $date): bool
    {
        return $this->ingatScan('any', $karyawanId, $date, fn () => FingerprintLog::where('karyawan_id', $karyawanId)
            ->whereDate('scan_at', $date->toDateString())
            ->exists());
    }

    /** Bandingkan kolom tanggal (Carbon atau string) dengan 'Y-m-d'. */
    private function tanggalSama($nilai, string $tanggal): bool
    {
        if (!$nilai) {
            return false;
        }

        return ($nilai instanceof \DateTimeInterface ? $nilai->format('Y-m-d') : substr((string) $nilai, 0, 10)) === $tanggal;
    }

    /** Satu (jenis, orang, tanggal) hanya ditanyakan sekali ke basis data. */
    private function ingatScan(string $jenis, int $karyawanId, Carbon $date, \Closure $fn)
    {
        $kunci = $jenis . ':' . $karyawanId . ':' . $date->toDateString();

        if (!array_key_exists($kunci, $this->memoScan)) {
            $this->memoScan[$kunci] = $fn();
        }

        return $this->memoScan[$kunci];
    }

    /**
     * Jadwal hari kerja yang dipinjam untuk hari libur yang dipakai bekerja: jadwal hari
     * pasangannya kalau ada, kalau tidak jadwal hari kerja terpanjang orang itu.
     */
    protected function workingDaySchedule(int $karyawanId, ?object $swap, Carbon $date): ?KaryawanSchedule
    {
        $rows = $this->jadwalOrang($karyawanId)
            ->filter(fn ($r) => !$r->is_off && $r->jam_masuk !== null && $r->jam_pulang !== null)
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        if ($swap) {
            $lain = Carbon::parse($swap->tanggal)->isSameDay($date) ? $swap->paired_date : $swap->tanggal;
            if ($lain && ($cocok = $rows->firstWhere('day_of_week', (int) Carbon::parse($lain)->dayOfWeek))) {
                return $cocok;
            }
        }

        return $rows->sortByDesc(fn ($r) => strtotime($r->jam_pulang) - strtotime($r->jam_masuk))->first();
    }

    public function forExecutor(DepartmentExecutor $exec, Carbon $date): ?KaryawanSchedule
    {
        $karyawanId = $exec->effectiveKaryawanId();
        if (!$karyawanId) return null;
        return $this->forKaryawan($karyawanId, $date);
    }

    public function hasCheckedIn(int $karyawanId, Carbon $date): ?Carbon
    {
        return $this->ingatScan('check_in', $karyawanId, $date, function () use ($karyawanId, $date) {
            $scan = FingerprintLog::where('karyawan_id', $karyawanId)
                ->where('verify_type', 'check_in')
                ->whereDate('scan_at', $date->toDateString())
                ->orderBy('scan_at')
                ->first();
            return $scan ? Carbon::parse($scan->scan_at) : null;
        });
    }

    public function hasCheckedOut(int $karyawanId, Carbon $date): ?Carbon
    {
        return $this->ingatScan('check_out', $karyawanId, $date, function () use ($karyawanId, $date) {
            $scan = FingerprintLog::where('karyawan_id', $karyawanId)
                ->where('verify_type', 'check_out')
                ->whereDate('scan_at', $date->toDateString())
                ->orderByDesc('scan_at')
                ->first();
            return $scan ? Carbon::parse($scan->scan_at) : null;
        });
    }

    public function hasOvertimeIn(int $karyawanId, Carbon $date): ?Carbon
    {
        return $this->ingatScan('overtime_in', $karyawanId, $date, function () use ($karyawanId, $date) {
            $scan = FingerprintLog::where('karyawan_id', $karyawanId)
                ->where('verify_type', 'overtime_in')
                ->whereDate('scan_at', $date->toDateString())
                ->orderBy('scan_at')
                ->first();
            return $scan ? Carbon::parse($scan->scan_at) : null;
        });
    }

    /**
     * Tentukan status efektif eksekutor pada $now berdasarkan schedule + scan overtime.
     *
     * Return salah satu:
     *   off_day          → karyawan libur
     *   no_schedule      → tidak ada jadwal hari ini
     *   pre_work         → sebelum jam_masuk
     *   in_work          → dalam jam kerja regular (di luar istirahat)
     *   in_break         → sedang istirahat
     *   after_work       → setelah jam_pulang, sebelum lembur
     *   in_overtime      → dalam window lembur (has_lembur + overtimeIn scan tercatat)
     *   after_overtime   → setelah jam_pulang_lembur
     */
    public function currentStatus(?KaryawanSchedule $sched, Carbon $now, ?Carbon $overtimeIn): string
    {
        if (!$sched) return 'no_schedule';
        if ($sched->is_off) return 'off_day';

        $t = $now->format('H:i:s');
        $jamMasuk  = $this->norm($sched->jam_masuk);
        $jamPulang = $this->norm($sched->jam_pulang);

        if (!$jamMasuk || !$jamPulang) return 'no_schedule';

        $istStart = $this->norm($sched->jam_istirahat_start);
        $istEnd   = $this->norm($sched->jam_istirahat_end);

        $inBreak = $istStart && $istEnd && $t >= $istStart && $t < $istEnd;

        if ($t < $jamMasuk) return 'pre_work';

        if ($t >= $jamMasuk && $t < $jamPulang) {
            return $inBreak ? 'in_break' : 'in_work';
        }

        // Setelah jam_pulang → cek lembur
        if ($sched->has_lembur && $overtimeIn) {
            $jamPulangLembur = $this->norm($sched->jam_pulang_lembur);
            if ($jamPulangLembur && $t < $jamPulangLembur) {
                $istLemburStart = $this->norm($sched->jam_istirahat_lembur_start);
                $istLemburEnd   = $this->norm($sched->jam_istirahat_lembur_end);
                $inLemburBreak  = $istLemburStart && $istLemburEnd
                    && $t >= $istLemburStart && $t < $istLemburEnd;
                return $inLemburBreak ? 'in_break' : 'in_overtime';
            }
            return 'after_overtime';
        }

        return 'after_work';
    }

    /**
     * Hitung waktu efektif mulai timer berdasarkan scan check_in vs jam_masuk.
     * Scan sebelum jam_masuk → mulai dihitung dari jam_masuk.
     * Scan setelah jam_masuk → mulai dihitung dari scan_at.
     */
    public function effectiveStartTime(?KaryawanSchedule $sched, Carbon $checkInScan): Carbon
    {
        if (!$sched || !$sched->jam_masuk) return $checkInScan->copy();

        $jamMasukDt = Carbon::parse($checkInScan->toDateString() . ' ' . $this->norm($sched->jam_masuk));
        return $checkInScan->lt($jamMasukDt) ? $jamMasukDt : $checkInScan->copy();
    }

    /**
     * Normalize TIME field: "08:00" → "08:00:00", null → null, Carbon → "HH:MM:SS".
     */
    private function norm($val): ?string
    {
        if ($val === null || $val === '') return null;
        if ($val instanceof \DateTimeInterface) return $val->format('H:i:s');
        $s = (string) $val;
        // Already HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) return $s;
        // HH:MM
        if (preg_match('/^\d{2}:\d{2}$/', $s)) return $s . ':00';
        return $s;
    }
}
