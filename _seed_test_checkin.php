<?php

/*
 |--------------------------------------------------------------------------
 | TEST HELPER — isi scan check-in operator produksi untuk HARI INI
 |--------------------------------------------------------------------------
 | Jalankan: php artisan tinker _seed_test_checkin.php
 | Tujuan: supaya assertExecutorsReady() lolos cek "belum scan check-in"
 |         saat menekan tombol Mulai di Proses Produksi.
 | Idempotent: tidak membuat scan dobel kalau sudah ada check-in hari ini.
 | File ini aman dihapus setelah testing.
 */

use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Services\ExecutorScheduleResolver;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\FingerprintMachine;
use Carbon\Carbon;

// machine_id wajib (NOT NULL) — pakai mesin yang ada, atau buat mesin test.
$machine = FingerprintMachine::first() ?? FingerprintMachine::create([
    'code'      => 'TEST-MANUAL',
    'name'      => 'Mesin Test Manual',
    'is_active' => true,
    'notes'     => 'Dibuat otomatis oleh _seed_test_checkin.php untuk testing.',
]);
$machineId = $machine->id;

$resolver = app(ExecutorScheduleResolver::class);
$now      = Carbon::now();
$today    = $now->copy()->startOfDay();
$checkInAt = $today->copy()->setTime(8, 0, 0); // jam masuk 08:00

echo "== Seed check-in test — {$today->toDateString()} ({$today->isoFormat('dddd')}) ==\n\n";

// Semua executor → kumpulkan karyawan efektif yang unik
$karyawanIds = DepartmentExecutor::all()
    ->map(fn ($e) => $e->effectiveKaryawanId())
    ->filter()
    ->unique()
    ->values();

if ($karyawanIds->isEmpty()) {
    echo "Tidak ada executor yang ter-link ke karyawan.\n";
    return;
}

foreach ($karyawanIds as $karyawanId) {
    $karyawan = \App\Modules\SDM\Models\Karyawan::find($karyawanId);
    $name = $karyawan->name ?? "Karyawan #{$karyawanId}";
    $fpUserId = $karyawan->user_id_fingerprint ?: (string) $karyawanId;

    // 1) Cek jadwal hari ini (info saja — tidak dibuat otomatis)
    $sched = $resolver->forKaryawan($karyawanId, $now);
    if (!$sched) {
        echo "  [!] {$name}: TIDAK ADA jadwal kerja hari ini (day_of_week={$now->dayOfWeek}). Mulai tetap akan ditolak.\n";
    } elseif ($sched->is_off) {
        echo "  [!] {$name}: jadwal hari ini OFF/libur. Mulai tetap akan ditolak.\n";
    } else {
        echo "  [ok] {$name}: jadwal {$sched->jam_masuk}–{$sched->jam_pulang}.\n";
    }

    // 2) Buat scan check-in kalau belum ada
    $existing = $resolver->hasCheckedIn($karyawanId, $now);
    if ($existing) {
        echo "       check-in sudah ada @ {$existing->format('H:i')} — dilewati.\n";
        continue;
    }

    FingerprintLog::create([
        'machine_id'          => $machineId,
        'karyawan_id'         => $karyawanId,
        'user_id_fingerprint' => $fpUserId,
        'scan_at'             => $checkInAt,
        'verify_method'       => 'manual',
        'verify_type'         => 'check_in',
        'raw_payload'         => ['source' => 'manual_test_seed'],
        'processed'           => false,
        'processed_at'        => null,
        'attendance_id'       => null,
    ]);
    echo "       + check-in dibuat @ {$checkInAt->format('H:i')}.\n";
}

echo "\nSelesai. Refresh halaman Proses Produksi lalu klik Mulai.\n";
