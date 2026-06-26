<?php

namespace App\Modules\SDM\Services;

use App\Models\PushSubscription;
use App\Models\User;
use App\Modules\Notifications\Services\WebPushNotifier;
use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\AttendanceReminder;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\NationalHoliday;
use Illuminate\Support\Carbon;

/**
 * Pengingat absensi via Web Push, mengikuti jadwal per-karyawan:
 *  1. before_in  — ~30 menit sebelum jam masuk (belum scan masuk).
 *  2. late_in    — sudah lewat jam masuk + toleransi, masih belum scan masuk.
 *  3. before_out — di/sesudah jam pulang, sudah masuk tapi belum scan pulang.
 *
 * Dipanggil tiap 5 menit oleh scheduler (juga bisa manual: artisan
 * sdm:send-attendance-reminders). Idempoten via sdm_attendance_reminders
 * (tiap jenis maksimal 1×/hari/karyawan), jadi aman dipanggil berulang.
 *
 * Hanya menyasar karyawan AKTIF yang punya akun PWA + langganan Web Push.
 * Melewati hari libur/off, dan hari dengan izin/cuti/tukar (override selain
 * penyesuaian_jam). Penyesuaian jam → patokan jam masuk/pulang ikut override.
 */
class AttendanceReminderService
{
    public function __construct(private WebPushNotifier $push) {}

    public function run(?Carbon $now = null): array
    {
        $now   = $now ? $now->copy() : now();
        $today = $now->toDateString();
        $stats = ['before_in' => 0, 'late_in' => 0, 'before_out' => 0];

        if (! $this->push->enabled()) {
            return $stats;
        }

        // Karyawan yang punya akun PWA (role 'karyawan') + minimal 1 langganan push.
        $usersByKaryawan = User::where('role', 'karyawan')
            ->whereNotNull('karyawan_id')
            ->whereIn('id', PushSubscription::query()->select('user_id'))
            ->get()
            ->keyBy('karyawan_id');

        if ($usersByKaryawan->isEmpty()) {
            return $stats;
        }

        $isHoliday = NationalHoliday::isLibur($today);
        $dow       = (int) $now->dayOfWeek;

        $karyawans = Karyawan::with('schedules')
            ->whereIn('id', $usersByKaryawan->keys()->all())
            ->where('is_active', true)
            ->get();

        foreach ($karyawans as $k) {
            $user = $usersByKaryawan[$k->id] ?? null;
            if (! $user) {
                continue;
            }

            $sched = $k->schedules->firstWhere('day_of_week', $dow);
            $isOff = $sched ? (bool) $sched->is_off : ($dow === 0);
            if ($isOff || $isHoliday) {
                continue;
            }

            // Hari spesial (cuti/sakit/izin/tukar) → tidak ingatkan. Penyesuaian jam → ikut.
            $override = AttendanceOverride::where('karyawan_id', $k->id)
                ->whereDate('tanggal', $today)
                ->first();
            if ($override && $override->type !== 'penyesuaian_jam') {
                continue;
            }

            $jamMasuk  = $override?->jam_masuk_override  ?: ($sched?->jam_masuk  ?: '08:00:00');
            $jamPulang = $override?->jam_pulang_override ?: ($sched?->jam_pulang ?: '16:00:00');
            $masukAt   = Carbon::parse($today . ' ' . substr($jamMasuk, 0, 5));
            $pulangAt  = Carbon::parse($today . ' ' . substr($jamPulang, 0, 5));
            $lateTol   = (int) ($sched?->late_in_minutes ?? 10);

            $att        = Attendance::where('karyawan_id', $k->id)->whereDate('tanggal', $today)->first();
            $scannedIn  = $att && $att->on_work1;
            $scannedOut = $att && $att->off_work1;

            // 1. before_in — 30 menit sebelum masuk, belum scan.
            if (! $scannedIn
                && $now->gte($masukAt->copy()->subMinutes(30))
                && $now->lt($masukAt)) {
                $this->maybeSend($k, $user, $today, 'before_in',
                    '⏰ Sebentar lagi jam kerja',
                    'Jam masuk Anda pukul ' . $masukAt->format('H:i') . '. Jangan lupa absen, ya!',
                    $stats);
            }

            // 2. late_in — sudah lewat masuk + toleransi, masih belum scan.
            if (! $scannedIn
                && $now->gte($masukAt->copy()->addMinutes($lateTol))
                && $now->lt($pulangAt)) {
                $this->maybeSend($k, $user, $today, 'late_in',
                    '🔴 Belum absen masuk',
                    'Anda belum tercatat absen masuk hari ini. Segera lakukan absen.',
                    $stats);
            }

            // 3. before_out — di/sesudah jam pulang, sudah masuk, belum scan pulang.
            if ($scannedIn && ! $scannedOut
                && $now->gte($pulangAt)
                && $now->lt($pulangAt->copy()->addHours(2))) {
                $this->maybeSend($k, $user, $today, 'before_out',
                    '👋 Waktunya pulang',
                    'Jam kerja selesai pukul ' . $pulangAt->format('H:i') . '. Jangan lupa absen pulang.',
                    $stats);
            }
        }

        return $stats;
    }

    private function maybeSend(Karyawan $k, User $user, string $today, string $type, string $title, string $body, array &$stats): void
    {
        // Idempoten: unique(karyawan_id,tanggal,type) → hanya kirim sekali/hari.
        $rec = AttendanceReminder::firstOrCreate([
            'karyawan_id' => $k->id,
            'tanggal'     => $today,
            'type'        => $type,
        ]);
        if (! $rec->wasRecentlyCreated) {
            return;
        }

        $this->push->notifyUser($user, $title, $body, [
            'url' => route('me.home'),
            'tag' => 'absen-' . $type,
        ]);
        $stats[$type]++;
    }
}
