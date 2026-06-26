<?php

namespace App\Http\Controllers\Me;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\SDM\Models\KaryawanSchedule;
use App\Modules\SDM\Services\AttendanceStatusResolver;
use App\Modules\SDM\Services\KaryawanHomeService;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class HomeController extends Controller
{
    public function __construct(
        private KaryawanHomeService $home,
        private AttendanceStatusResolver $statusResolver,
    ) {}

    public function index()
    {
        $karyawan = auth()->user()->karyawan;
        $now      = Carbon::now();
        $today    = $now->toDateString();

        $todayRow = Attendance::where('karyawan_id', $karyawan->id)
            ->whereDate('tanggal', $today)
            ->first();

        $monthRows = Attendance::where('karyawan_id', $karyawan->id)
            ->whereYear('tanggal', $now->year)
            ->whereMonth('tanggal', $now->month)
            ->get();

        // Selaraskan status dgn dashboard HRD: hitung live (hormati jam_pulang
        // per-jadwal, toleransi, tukar hari, penyesuaian jam) — kolom tersimpan
        // pakai ambang global shg bisa keliru utk karyawan berjadwal beda.
        $resolved = $this->statusResolver->resolveForRange(
            $karyawan,
            $now->copy()->startOfMonth(),
            $now->copy()->endOfMonth(),
            $monthRows,
        );
        foreach ($monthRows as $r) {
            $key = Carbon::parse($r->tanggal)->toDateString();
            if (array_key_exists($key, $resolved)) {
                $r->status = $resolved[$key];
            }
        }
        if ($todayRow && array_key_exists($today, $resolved)) {
            $todayRow->status = $resolved[$today];
        }

        $todayMessage = $this->home->todayMessage($todayRow);
        $metrics      = $this->home->monthMetrics($monthRows);

        $hour = (int) $now->format('H');
        $greeting = match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 18 => 'Selamat sore',
            default    => 'Selamat malam',
        };

        // Nama panggilan = kata pertama dari name
        $panggilan = explode(' ', trim($karyawan->name))[0] ?? $karyawan->name;

        // Jadwal hari ini (untuk jam live + kondisi: jam kerja/istirahat/lembur/libur)
        $sched = KaryawanSchedule::where('karyawan_id', $karyawan->id)
            ->where('day_of_week', $now->dayOfWeek)
            ->first();
        $schedule = $this->buildSchedule($sched);

        // Izin yang masih relevan: belum ditolak & tanggal terkaitnya belum lewat.
        // Hilang otomatis begitu tanggal terakhir (tanggal/tanggal_akhir/paired_date)
        // sudah lewat — mis. tukar 24↔30 tetap tampil sampai tgl 30 lewat.
        $izinAktif = IzinRequest::where('karyawan_id', $karyawan->id)
            ->where('status', '!=', 'rejected')
            ->where(function ($q) use ($today) {
                $q->whereDate('tanggal', '>=', $today)
                    ->orWhereDate('tanggal_akhir', '>=', $today)
                    ->orWhereDate('paired_date', '>=', $today);
            })
            ->orderBy('tanggal')
            ->get();

        // SP yang masih berlaku hari ini.
        $spAktif = $karyawan->spHistory()->berlaku()->get();

        return view('me.home', [
            'karyawan'     => $karyawan,
            'panggilan'    => $panggilan,
            'greeting'     => $greeting,
            'todayMessage' => $todayMessage,
            'metrics'      => $metrics,
            'now'          => $now,
            'schedule'     => $schedule,
            'izinAktif'    => $izinAktif,
            'spAktif'      => $spAktif,
        ]);
    }

    /**
     * Ringkas KaryawanSchedule hari ini → array jam (HH:MM) untuk dihitung kondisi
     * saat ini di sisi klien (jam live). Null bila tak ada jadwal.
     */
    private function buildSchedule(?KaryawanSchedule $s): array
    {
        $hm = fn ($t) => $t ? substr($t, 0, 5) : null;

        return [
            'has'              => $s !== null,
            'is_off'           => (bool) ($s->is_off ?? false),
            'masuk'            => $hm($s->jam_masuk ?? null),
            'pulang'           => $hm($s->jam_pulang ?? null),
            'ist_start'        => $hm($s->jam_istirahat_start ?? null),
            'ist_end'          => $hm($s->jam_istirahat_end ?? null),
            'has_lembur'       => (bool) ($s->has_lembur ?? false),
            'lembur_in'        => $hm($s->jam_masuk_lembur ?? null),
            'lembur_out'       => $hm($s->jam_pulang_lembur ?? null),
            'ist_lembur_start' => $hm($s->jam_istirahat_lembur_start ?? null),
            'ist_lembur_end'   => $hm($s->jam_istirahat_lembur_end ?? null),
        ];
    }
}
