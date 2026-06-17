<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('production:auto-manage-sessions')->everyMinute();
Schedule::command('production:run-auto')->everyThirtyMinutes();

// Periode akuntansi — buat periode bulan berjalan otomatis tiap tanggal 1 jam 00:01
Schedule::command('period:ensure-current')
    ->monthlyOn(1, '00:01')->name('ensure-current-period')->withoutOverlapping();

// Periode penggajian/absensi — buat periode bulan berjalan otomatis tiap tanggal 1 jam 00:01
Schedule::command('periode-gaji:ensure-current')
    ->monthlyOn(1, '00:01')->name('ensure-current-payroll-period')->withoutOverlapping();

// Auto-attendance batch DINONAKTIFKAN (2026-06-17): jam absensi WAJIB 100% dari log
// fingerprint asli (via AdmsService::mergeIntoAttendance). Auto-inject dulu memfabrikasi
// jam masuk 08:00 / pulang 16:00 / lembur 20:00 untuk karyawan tanpa scan — termasuk di hari
// libur nasional (16:00 "pulang" padahal tak ada scan) sehingga absensi TIDAK sesuai log.
// Hari kerja tanpa scan kini tampil kosong; izin/sakit/cuti/lupa-absen diisi manual HRD
// lewat dropdown status / Upload Excel. Lihat AutoAttendanceService (kini tak terjadwal).

// Task Manager — generate scheduled tasks setiap 5 menit
Schedule::call(fn() => app(\App\Modules\Tasks\Services\TaskAutomationService::class)->runScheduled())
    ->everyFiveMinutes()->name('task-scheduler')->withoutOverlapping();

// Jubelio — sinkron pesanan tiap 5 menit, retur tiap 15 menit (andalan localhost; webhook akselerator).
Schedule::command('jubelio:sync-orders')->everyFiveMinutes()->name('jubelio-sync-orders')->withoutOverlapping();
Schedule::command('jubelio:sync-returns')->everyFifteenMinutes()->name('jubelio-sync-returns')->withoutOverlapping();
// Jubelio stok — push perubahan tiap 5 menit (near-realtime), rekonsiliasi penuh tiap 2 jam.
Schedule::command('jubelio:push-stock')->everyFiveMinutes()->name('jubelio-push-stock')->withoutOverlapping();
Schedule::command('jubelio:reconcile-stock')->everyTwoHours()->name('jubelio-reconcile-stock')->withoutOverlapping();
// Jubelio harga — push perubahan harga tiap 15 menit (promo tetap diatur di Jubelio).
Schedule::command('jubelio:push-prices')->everyFifteenMinutes()->name('jubelio-push-prices')->withoutOverlapping();
// Jubelio riwayat sinkron — buang log lebih lama dari 90 hari (jejak audit, bukan sumber kebenaran).
Schedule::call(fn() => \App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog::where('created_at', '<', now()->subDays(90))->delete())
    ->dailyAt('02:30')->name('jubelio-prune-sync-logs');
