<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Models\NationalHoliday;
use App\Modules\SDM\Models\PeriodePenggajian;
use App\Modules\SDM\Models\SlipGaji;
use Carbon\Carbon;

/**
 * Bahan untuk MENIMBANG satu pengajuan izin, bukan sekadar menyetujuinya.
 *
 * Sebelumnya halaman persetujuan cuma menampilkan nama, tipe, tanggal, dan alasan — lalu
 * dua tombol. Tidak ada cara melihat apakah pengajuannya masuk akal: apakah orangnya
 * sebenarnya masuk hari itu, apakah harinya memang sudah libur, apakah tanggal pasangan
 * tukar harinya benar. Akibatnya salah pilih tanggal baru ketahuan setelah gaji dihitung.
 *
 * Service ini mengumpulkan fakta hari itu apa adanya (jadwal, scan mentah, status absensi,
 * override yang sudah ada) dan menyalakan peringatan untuk pola yang biasanya berarti
 * pengajuannya keliru. Peringatan TIDAK memblokir apa pun — yang memutuskan tetap manusia;
 * tugasnya cuma memastikan keputusannya diambil dengan mata terbuka.
 */
class IzinReviewService
{
    public function __construct(private IzinRequestService $izin)
    {
    }

    /**
     * @return array{
     *   dates: array<int,array>, paired: ?array, warnings: array<int,array{level:string,text:string}>,
     *   sisa_cuti: ?int, periode: ?array
     * }
     */
    public function build(IzinRequest $req): array
    {
        $karyawan = $req->karyawan;

        $dates  = array_map(fn ($d) => $this->dayContext($req, $d), $this->expandDates($req));
        $paired = $req->paired_date ? $this->dayContext($req, $req->paired_date->toDateString()) : null;

        return [
            'dates'     => $dates,
            'paired'    => $paired,
            'warnings'  => $this->warnings($req, $dates, $paired),
            'sisa_cuti' => $karyawan && $req->type === 'cuti'
                ? $this->izin->sisaCuti($karyawan, $req->tanggal->year)
                : null,
            'periode'   => $this->periode($req),
        ];
    }

    /** Tanggal-tanggal yang benar-benar terdampak (tipe rentang bisa lebih dari satu). */
    public function expandDates(IzinRequest $req): array
    {
        $start = $req->tanggal->copy();
        $end   = $req->tanggal_akhir ? $req->tanggal_akhir->copy() : $start->copy();

        if (!in_array($req->type, IzinRequest::RANGE_TYPES, true)) {
            return [$start->toDateString()];
        }

        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** Fakta satu hari: jadwal, scan mentah, absensi tersimpan, override yang sudah ada. */
    protected function dayContext(IzinRequest $req, string $date): array
    {
        $d     = Carbon::parse($date);
        $sched = $req->karyawan?->schedules->firstWhere('day_of_week', (int) $d->dayOfWeek);

        $scans = FingerprintLog::where('karyawan_id', $req->karyawan_id)
            ->whereDate('scan_at', $date)
            ->orderBy('scan_at')
            ->get(['scan_at', 'verify_type'])
            ->map(fn ($s) => [
                'jam'  => Carbon::parse($s->scan_at)->format('H:i:s'),
                'type' => $s->verify_type,
            ])->all();

        $att = Attendance::where('karyawan_id', $req->karyawan_id)->whereDate('tanggal', $date)->first();

        // Override yang lahir DARI pengajuan ini sendiri bukan tabrakan — kalau ikut
        // ditampilkan, tiap pengajuan yang sudah disetujui akan terlihat bentrok dengan dirinya.
        $milikSendiri = $req->override_ids ?: [];

        $overrides = AttendanceOverride::where('karyawan_id', $req->karyawan_id)
            ->whereNotIn('id', $milikSendiri ?: [0])
            ->where(fn ($q) => $q->whereDate('tanggal', $date)->orWhereDate('paired_date', $date))
            ->get()
            ->map(fn ($o) => [
                'type'        => AttendanceOverride::TYPES[$o->type] ?? $o->type,
                'notes'       => $o->notes,
                'paired_date' => $o->paired_date?->toDateString(),
            ])->all();

        $holiday = NationalHoliday::whereDate('tanggal', $date)->first();

        // Persetujuan melewati Minggu & tanggal merah untuk tipe rentang (lihat
        // IzinRequestService::expandDates) — ditandai supaya tidak ada kejutan "kok cuti
        // 3 hari yang tercatat cuma 2".
        $terpakai = !in_array($req->type, IzinRequest::RANGE_TYPES, true)
            || ((int) $d->dayOfWeek !== 0 && !$holiday);

        return [
            'date'       => $d,
            'applied'    => $terpakai,
            'is_off'     => $sched ? (bool) $sched->is_off : ($d->dayOfWeek === 0),
            'holiday'    => $holiday?->nama,
            'jam_masuk'  => $sched?->jam_masuk,
            'jam_pulang' => $sched?->jam_pulang,
            'scans'      => $scans,
            'attendance' => $att ? [
                'status'       => $att->status,
                'on_work1'     => $att->on_work1,
                'off_work1'    => $att->off_work1,
                'late_minutes' => (int) $att->late_minutes,
                'remark'       => $att->remark,
                'edited'       => (bool) $att->edited_manually,
            ] : null,
            'overrides'  => $overrides,
        ];
    }

    /**
     * Pola yang biasanya berarti pengajuannya keliru.
     *
     * Sengaja hanya berupa peringatan: yang tahu duduk perkaranya adalah orang, bukan tabel.
     */
    protected function warnings(IzinRequest $req, array $dates, ?array $paired): array
    {
        $out    = [];
        $absen  = ['cuti', 'sakit', 'izin_pagi', 'izin_sore'];
        $format = fn (Carbon $d) => $d->translatedFormat('l, d M Y');

        foreach ($dates as $day) {
            $tgl = $format($day['date']);

            if (in_array($req->type, $absen, true) && !empty($day['scans'])) {
                $jam = implode(', ', array_column($day['scans'], 'jam'));
                $out[] = [
                    'level' => 'danger',
                    'text'  => "{$tgl}: karyawan TERCATAT SCAN hari itu ({$jam}). Kalau dia sebenarnya masuk, "
                             . 'kemungkinan besar tanggalnya salah pilih — tolak dan minta ajukan ulang.',
                ];
            }

            if (in_array($req->type, $absen, true) && $day['is_off']) {
                $out[] = [
                    'level' => 'warn',
                    'text'  => "{$tgl}: hari itu memang libur di jadwalnya. Mengajukan izin untuk hari libur biasanya salah tanggal.",
                ];
            }

            if ($day['holiday']) {
                $out[] = [
                    'level' => 'warn',
                    'text'  => "{$tgl}: tanggal merah ({$day['holiday']}). Pastikan pengajuannya memang perlu.",
                ];
            }

            foreach ($day['overrides'] as $o) {
                $out[] = [
                    'level' => 'warn',
                    'text'  => "{$tgl}: sudah ada catatan \"{$o['type']}\" di tanggal ini. Menyetujui bisa bertabrakan.",
                ];
            }

            if ($req->type === 'toleransi' && empty($day['scans'])) {
                $out[] = [
                    'level' => 'danger',
                    'text'  => "{$tgl}: tidak ada scan sama sekali. Toleransi hanya untuk hari yang ADA keterlambatan.",
                ];
            }
        }

        // Tukar hari: tanggal = hari kerja yang dilepas, pasangan = hari libur yang dipakai kerja.
        if (in_array($req->type, ['tukar_hari', 'tukar_setengah_hari'], true)) {
            if (!$paired) {
                $out[] = ['level' => 'danger', 'text' => 'Tanggal pasangan kosong — tukar hari tidak bisa diterapkan.'];
            } else {
                if (!$paired['is_off']) {
                    $out[] = [
                        'level' => 'danger',
                        'text'  => $format($paired['date']) . ': tanggal pasangan BUKAN hari libur. '
                                 . 'Tukar hari seharusnya menukar hari kerja dengan hari libur.',
                    ];
                }
                if (!$dates[0]['is_off'] && empty($paired['scans'])) {
                    $out[] = [
                        'level' => 'warn',
                        'text'  => $format($paired['date']) . ': belum ada scan di tanggal pengganti. '
                                 . 'Kalau harinya sudah lewat dan tetap tidak ada scan, berarti penggantinya tidak jadi masuk.',
                    ];
                }
            }
        }

        if ($req->type === 'cuti') {
            $sisa = $req->karyawan ? $this->izin->sisaCuti($req->karyawan, $req->tanggal->year) : null;
            $minta = count(array_filter($dates, fn ($d) => !$d['is_off'] && !$d['holiday']));
            if ($sisa !== null && $minta > $sisa) {
                $out[] = [
                    'level' => 'danger',
                    'text'  => "Sisa cuti {$req->tanggal->year} tinggal {$sisa} hari, pengajuan ini {$minta} hari kerja.",
                ];
            }
        }

        if (($periode = $this->periode($req)) && $periode['final']) {
            $out[] = [
                'level' => 'warn',
                'text'  => "Slip gaji {$periode['label']} sudah final. Menyetujui tidak akan mengubah slip itu.",
            ];
        }

        return $out;
    }

    /** Periode penggajian tempat tanggal ini jatuh, plus apakah slipnya sudah final. */
    protected function periode(IzinRequest $req): ?array
    {
        $periode = PeriodePenggajian::where('bulan', $req->tanggal->month)
            ->where('tahun', $req->tanggal->year)->first();

        if (!$periode) {
            return null;
        }

        $slip = SlipGaji::where('periode_id', $periode->id)
            ->where('karyawan_id', $req->karyawan_id)->first();

        return [
            'label' => $periode->label,
            'final' => (bool) ($slip && $slip->isFinalized()),
            'slip'  => (bool) $slip,
        ];
    }
}
