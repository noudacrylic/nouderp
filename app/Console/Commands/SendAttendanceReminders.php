<?php

namespace App\Console\Commands;

use App\Modules\SDM\Services\AttendanceReminderService;
use Illuminate\Console\Command;

/**
 * Kirim pengingat absensi (Web Push) ke karyawan sesuai jadwal masing-masing.
 * Dijadwalkan tiap 5 menit; aman dipanggil manual kapan saja (idempoten harian).
 */
class SendAttendanceReminders extends Command
{
    protected $signature = 'sdm:send-attendance-reminders';

    protected $description = 'Kirim pengingat absen masuk/pulang via Web Push sesuai jadwal karyawan';

    public function handle(AttendanceReminderService $service): int
    {
        $stats = $service->run();

        $this->info(sprintf(
            'Pengingat terkirim — sebelum masuk: %d, belum absen: %d, jam pulang: %d',
            $stats['before_in'], $stats['late_in'], $stats['before_out']
        ));

        return self::SUCCESS;
    }
}
