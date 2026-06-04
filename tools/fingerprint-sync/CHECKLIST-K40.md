# Checklist: Setup ZKTeco K40 saat Mesin Tiba

Ikuti urutan ini setelah K40 sampai di kantor. Estimasi: 30-60 menit total (di luar enrollment karyawan).

## 1. Hardware & Network (10 menit)

- [ ] Unboxing, pasang adaptor + kabel LAN ke mesin
- [ ] Sambungkan mesin ke router/switch kantor via kabel ethernet
- [ ] Hidupkan mesin, tunggu booting selesai
- [ ] Catat MAC address mesin (Menu → Sys Info → Device Info)
- [ ] Reservasi IP statik di router (DHCP reservation berdasarkan MAC) — pilih IP di subnet `192.168.1.x` yang belum dipakai
- [ ] Restart mesin, verifikasi IP yang muncul di mesin sama dengan yang di-reserve
- [ ] Catat **Serial Number** (Menu → Sys Info → Device Info, atau stiker di belakang)

## 2. Test koneksi dari komputer ERP (5 menit)

Dari komputer XAMPP/ERP:

```powershell
ping <ip-mesin>
Test-NetConnection <ip-mesin> -Port 4370
```

Kedua-duanya harus sukses. Kalau port 4370 ditutup, cek setting Network di mesin (Menu → Comm → Network/TCP-IP, pastikan TCP/IP enabled).

## 3. Konfigurasi sidecar (5 menit)

Edit `tools/fingerprint-sync/config.ini`:

```ini
[machine]
ip = <ip-statik-yang-tadi-direservasi>
port = 4370
serial_number = <SN-yang-dicatat>
force_udp = true
password = 0
```

Lalu test koneksi:

```cmd
cd c:\xampp\htdocs\noud-erp\tools\fingerprint-sync
python test_connection.py
```

Harus muncul `Connected!` + device info. Kalau gagal:
- Coba toggle `force_udp = false` (TCP mode)
- Pastikan tidak ada firewall block port 4370 di komputer maupun mesin

## 4. Daftarkan mesin di ERP (5 menit)

Di ERP, buka menu Mesin/Device Management, tambah mesin baru:

- Nama: `Fingerprint Utama` (atau sesuai preferensi)
- Serial Number: **sama persis** dengan yang di `config.ini` (case-sensitive)
- IP: sama dengan `config.ini`
- Status: Aktif

## 5. Enroll karyawan di mesin (30+ menit, tergantung jumlah karyawan)

Untuk setiap karyawan aktif (lihat memory `project_master_karyawan.md` — 7 karyawan):

- [ ] Di mesin: Menu → User Mgt → Add User
- [ ] Set **User ID (PIN)** = sama dengan `emp_id` di ERP supaya mapping clean (atau pakai urutan sederhana 1, 2, 3, ...)
- [ ] Isi Nama
- [ ] Enroll sidik jari (minimal 1, idealnya 2 untuk redundansi)
- [ ] Save

**Tip**: catat mapping `PIN → emp_id` di kertas/spreadsheet kecil, supaya bisa di-input di ERP kalau mapping belum auto.

Di ERP: pastikan setiap karyawan punya field `fingerprint_pin` yang match dengan PIN di mesin (atau buat tabel `device_user_mapping` kalau belum ada).

## 6. Jalankan sidecar pertama kali (5 menit)

```cmd
cd c:\xampp\htdocs\noud-erp\tools\fingerprint-sync
run.bat
```

Biarkan running di window terminal. Sambil itu:

- [ ] Suruh 1 karyawan melakukan punch (tap sidik jari) di mesin
- [ ] Tunggu max 30 detik
- [ ] Cek window sidecar: harus muncul log `POST 1 record(s) to http://...`
- [ ] Cek ERP — di panel "Aktivitas ADMS (Live)" di halaman detail mesin, request harus muncul
- [ ] Cek tabel attendance ERP — record harus tersimpan dengan timestamp & PIN yang benar
- [ ] Test 1-2 punch lagi untuk konfirmasi reliability

## 7. Jadikan sidecar sebagai background service (5 menit)

Setelah step 6 sukses:

- [ ] Stop sidecar terminal (Ctrl+C)
- [ ] Setup Task Scheduler sesuai README — trigger on startup, run hidden via `run-background.vbs`
- [ ] Restart komputer untuk verifikasi sidecar auto-start
- [ ] Cek `sync.log` setelah restart: log baru harus muncul = sidecar jalan otomatis

## 8. Setup auto-cleanup memory mesin (opsional, recommended)

K40 punya kapasitas log ~100k record. Untuk shop kecil dengan 7 karyawan × ~2 punch/hari = ~14 record/hari, cukup untuk ~20 tahun. Tapi kalau mau bersih-bersih berkala:

- Di mesin: Menu → Sys Info → Delete attendance log → set retention (mis. hapus log >6 bulan)

**Tidak wajib** karena sidecar sudah POST ke ERP, mesin tinggal jadi cache short-term.

## 9. Buat dokumentasi singkat untuk HR

Tulis 1 halaman A4 untuk HR/Admin:
- Cara enroll karyawan baru di mesin
- Cara hapus karyawan resigned
- Cara cek attendance di ERP (bukan di mesin lagi)
- PIC kalau ada masalah (siapa yang kontak supplier mesin)

---

## Setelah semua done

- [ ] Update memory `project_fingerprint_setup.md`: status = OPERASIONAL, tanggal go-live
- [ ] Decommission software vendor lama (uninstall "Attendance Access System 3.0" kalau mau bersih, atau biarkan saja sebagai backup)
- [ ] TH600 lama: simpan sebagai backup hardware, atau jual/buang
