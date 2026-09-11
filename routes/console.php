<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

// Pengingat absensi karyawan (Web Push) — cek tiap 5 menit sesuai jadwal masing-masing.
Schedule::command('sdm:send-attendance-reminders')
    ->everyFiveMinutes()->name('attendance-reminders')->withoutOverlapping();

// Jubelio — sinkron pesanan tiap 5 menit, retur tiap 15 menit (andalan localhost; webhook akselerator).
Schedule::command('jubelio:sync-orders')->everyFiveMinutes()->name('jubelio-sync-orders')->withoutOverlapping();
Schedule::command('jubelio:sync-returns')->everyFifteenMinutes()->name('jubelio-sync-returns')->withoutOverlapping();
// Jubelio stok — push perubahan tiap 5 menit (near-realtime), rekonsiliasi penuh tiap 2 jam.
Schedule::command('jubelio:push-stock')->everyFiveMinutes()->name('jubelio-push-stock')->withoutOverlapping();
Schedule::command('jubelio:reconcile-stock')->everyTwoHours()->name('jubelio-reconcile-stock')->withoutOverlapping();
// Jubelio harga — push perubahan harga tiap 15 menit (promo tetap diatur di Jubelio).
Schedule::command('jubelio:push-prices')->everyFifteenMinutes()->name('jubelio-push-prices')->withoutOverlapping();
// Pengiriman — segarkan status kurir Jubelio Shipment & tandai paket yang sudah sampai.
// Webhook Jubelio yang jadi andalan (real-time); ini penyapu bila ada webhook yang terlewat.
Schedule::command('shipping:sync-status')->everyThirtyMinutes()->name('shipping-sync-status')->withoutOverlapping();
// Store — garbage collector media: hapus file foto/video yang sudah di-soft-delete
// & lewat masa jeda (config store.media_gc_days). Harian dini hari.
// Antrean notifikasi WhatsApp pelanggan. Tiap 5 menit: pesan "siap diambil" yang
// ditunda ke jam buka berangkat sendiri tanpa ada yang perlu menekan tombol.
Schedule::command('crm:kirim-notifikasi')->everyFiveMinutes()->name('crm-kirim-notifikasi')->withoutOverlapping();

// Denyut jantung sesi WAHA. Sesi yang putus TIDAK menimbulkan gejala apa pun:
// ERP tetap mengantre dengan rapi, cuma pelanggan tak menerima apa-apa. Kalau
// hanya diperiksa saat mengirim, jeda antar-pesanan bisa berjam-jam.
// Titipan "kabari kalau stok ada". Observer stok sudah menyalakan pemeriksaan
// seketika; ini jaring pengaman untuk perubahan stok yang tidak lewat ledger
// (impor, koreksi langsung, perintah yang mematikan event model).
Schedule::command('crm:cek-stok-titipan')->everyFifteenMinutes()->name('crm-cek-stok-titipan')->withoutOverlapping();

// Penagihan pesanan yang tautan bayarnya belum dibayar — dan pembatalan yang
// lewat batas. SEKALI SEHARI di jam kerja: jadwalnya berbasis hari penuh, jadi
// jalan berkali-kali sehari tak menghasilkan apa pun selain risiko, dan pesan
// tagihan yang tiba tengah malam mengundang blokir.
// Pengingat jatuh tempo pesanan tempo (H-3 & hari-H). Jalur RESMI berbayar,
// apa pun driver yang sedang dipilih — lihat TemplateResmi::wajibResmi().
Schedule::command('crm:ingatkan-jatuh-tempo')->dailyAt('09:00')->name('crm-ingatkan-jatuh-tempo')->withoutOverlapping();

Schedule::command('crm:tagih-pembayaran')->dailyAt('09:15')->name('crm-tagih-pembayaran')->withoutOverlapping();

// Pancingan jendela 24 jam. Tiap 15 menit karena jendela habis di menit mana
// saja: jarak antar-jalan itulah yang menentukan seberapa mepet pancingan
// terkirim, dan yang terkirim lima menit sebelum tutup hampir pasti mubazir.
Schedule::command('crm:pancing-jendela')->everyFifteenMinutes()->name('crm-pancing-jendela')->withoutOverlapping();

Schedule::command('crm:pantau-waha')->everyFiveMinutes()->name('crm-pantau-waha')->withoutOverlapping();

// Lampiran chat diunduh ke penyimpanan sendiri secepat mungkin: media di sisi
// Meta hanya bertahan ~30 hari, dan diskusi custom menggantung lebih lama.
Schedule::command('crm:unduh-lampiran')->everyMinute()->name('crm-unduh-lampiran')->withoutOverlapping();

// Nama profil WhatsApp untuk lead yang masih tampil sebagai nomor. Webhook tidak
// membawa nama, jadi ditanyakan ke vendor — hanya untuk yang belum bernama.
Schedule::command('crm:ambil-nama-kontak')->everyMinute()->name('crm-ambil-nama-kontak')->withoutOverlapping();

// Penyapu masa simpan — hanya lampiran yang tidak tertaut dokumen ERP.
Schedule::command('crm:gc-lampiran')->dailyAt('03:20')->name('crm-gc-lampiran')->withoutOverlapping();

Schedule::command('store:gc-media')->dailyAt('03:10')->name('store-gc-media')->withoutOverlapping();

// Pembayaran toko online (Transfer Bank + Kode Unik, dan QRIS/QRISLY):
//  1) poll mutasi (email/moota) & cocokkan nominal unik → posting pembayaran, tiap 2 menit;
//  1b) poll status QRIS — jaring pengaman bila webhook QRISLY tak sampai (baca saja,
//      tidak generate QR sehingga tidak menambah biaya), tiap 2 menit;
//  2) eskalasi ke Telegram bila belum tercocokkan setelah N menit, tiap menit;
//  3) backstop auto-batal order belum-bayar yang kedaluwarsa (24 jam), tiap jam.
Schedule::command('payments:poll-mutations')->everyTwoMinutes()->name('payments-poll-mutations')->withoutOverlapping();
Schedule::command('payments:poll-qris')->everyTwoMinutes()->name('payments-poll-qris')->withoutOverlapping();
Schedule::command('payments:escalate')->everyMinute()->name('payments-escalate')->withoutOverlapping();
Schedule::command('payments:cancel-expired')->hourly()->name('payments-cancel-expired')->withoutOverlapping();

// Midtrans — jaring pengaman kalau notifikasi webhook tidak sampai (server restart, deploy
// berjalan, URL notifikasi salah). Tanpa ini pembayaran yang notifikasinya hilang tidak
// pernah tersusul. Tiap 15 menit sudah cukup: webhook tetap jalur utama yang seketika.
Schedule::command('midtrans:reconcile-pending')->everyFifteenMinutes()->name('midtrans-reconcile-pending')->withoutOverlapping();

// Jubelio riwayat sinkron — buang log lebih lama dari 90 hari (jejak audit, bukan sumber kebenaran).
Schedule::call(fn() => \App\Modules\Marketplace\Jubelio\Models\JubelioSyncLog::where('created_at', '<', now()->subDays(90))->delete())
    ->dailyAt('02:30')->name('jubelio-prune-sync-logs');

// Analisa — hitung angka HPP/Harga/Kuota lebih dulu supaya halamannya terbuka seketika.
// Angkanya sudah dijaga sidik jari data (AnalysisCache), jadi kalau tidak ada yang berubah
// perintah ini hampir tanpa biaya; kalau ada, ongkos hitung ulang dibayar di sini, bukan
// oleh orang yang sedang membuka halaman.
Schedule::command('analisa:hangatkan')->everyFifteenMinutes()->name('analisa-hangatkan')->withoutOverlapping();

// Sapu entri cache yang sudah lewat masa berlakunya. Driver cache database TIDAK punya
// pembersih sendiri: entri kedaluwarsa cuma diabaikan saat dibaca, tidak pernah dihapus.
// Kunci cache Analisa mengandung sidik jari data, jadi setiap kali data berubah kunci
// ikut berganti dan entri lama ditinggalkan begitu saja sampai TTL 12 jamnya lewat —
// sementara isinya besar (satu jawaban waktu.buildAll ~330 KB). Ditambah penghangat yang
// jalan tiap 15 menit, tabel `cache` sempat menggelembung jadi 338 MB berisi 2.959 entri
// mati, dan ikut terbawa dump DB harian: ukurannya naik 5,9 MB -> 31,8 MB dalam 5 hari.
Schedule::call(fn () => DB::table('cache')->where('expiration', '<', now()->getTimestamp())->delete())
    ->hourly()->name('cache-prune-expired');
