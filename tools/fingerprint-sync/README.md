# Fingerprint Sidecar — Noud ERP

Script Python untuk PULL data attendance dari mesin fingerprint **ZKTeco K40** (atau ZKTeco-compatible lainnya) dan POST ke ERP secara periodik.

## Kenapa pakai sidecar?

ZKTeco K40 support ZKTeco binary protocol (TCP/UDP 4370). Sidecar ini PULL data dari mesin via `pyzk`, lalu kirim ke ERP via HTTP endpoint `/iclock/cdata` (ADMS-compatible).

Sidecar dipakai supaya ERP tidak perlu menjalankan TCP listener / ADMS push handler. Polling tiap 30 detik sudah cukup untuk use case attendance.

## Prerequisites

1. **Python 3.8+** terinstall di komputer XAMPP. Saat install, centang **"Add Python to PATH"**.
2. ERP Laravel jalan & accessible (default `http://127.0.0.1:8000`).
3. Mesin ZKTeco K40 di LAN yang sama, IP statik, port 4370 terbuka.

## Setup (sekali saja)

```cmd
cd c:\xampp\htdocs\noud-erp\tools\fingerprint-sync
install.bat
```

Lalu edit `config.ini`: isi `ip`, `serial_number`. Default `port = 4370`, `force_udp = true` sudah sesuai K40 standard.

## Test koneksi

```cmd
python test_connection.py
```

Output yang diharapkan:
- `Connected!`
- Daftar users di mesin
- Beberapa attendance records terakhir

Kalau gagal connect:
- Cek IP mesin reachable: `ping <ip-mesin>`
- Cek port 4370 terbuka: `Test-NetConnection <ip-mesin> -Port 4370`
- Coba toggle `force_udp` true ↔ false di `config.ini`
- Pastikan Comm Key di mesin = 0 (atau set `password` di config sama dengan Comm Key mesin)

## Run sidecar (foreground)

```cmd
run.bat
```

Polling tiap 30 detik. Tekan `Ctrl+C` untuk stop.

## Run sebagai background service (production)

Pakai Windows Task Scheduler:

1. Buka Task Scheduler (`taskschd.msc`)
2. Create Basic Task → Name: "Noud ERP Fingerprint Sync"
3. Trigger: **When the computer starts**
4. Action: Start a program → Browse ke `run-background.vbs` (run hidden tanpa cmd window)
5. Save. Pastikan "Run whether user is logged on or not" + "Run with highest privileges" untuk reliability.

## Cek log

```powershell
Get-Content sync.log -Tail 50 -Wait
```

## Cek state

`state.json` menyimpan timestamp last fetched record. Hapus file ini untuk re-baseline (sidecar akan POST ulang semua record yang masih di memori mesin).

## Troubleshooting

### Records tidak muncul di ERP

- Cek `sync.log` ada error apa
- Pastikan SN di `config.ini` sama persis dengan SN di form Mesin di ERP
- Pastikan ERP endpoint reachable: `curl http://127.0.0.1:8000/iclock/ping` harus respond `OK`
- Cek panel "Aktivitas ADMS (Live)" di halaman detail mesin di ERP — request dari sidecar akan muncul di sini

### Records duplicate

Hapus `state.json`, sidecar akan re-baseline.

### Mesin penuh memori (hapus log lama)

Sidecar **tidak** menghapus log dari mesin (safety). Kalau memori mesin penuh, hapus manual di mesin: `Menu → Sys Info → Delete attendance log`.

## File-file di folder ini

| File | Fungsi |
|---|---|
| `fingerprint_sync.py` | Main script (poll loop) |
| `test_connection.py` | Test koneksi sekali, dump info mesin |
| `config.example.ini` | Template config |
| `config.ini` | Config aktif |
| `state.json` | State (timestamp last fetched) |
| `sync.log` | Log output |
| `requirements.txt` | Python dependencies |
| `install.bat` | Setup script (sekali) |
| `run.bat` | Run script (foreground) |
| `run-background.vbs` | Run hidden tanpa cmd window |
| `CHECKLIST-K40.md` | Checklist setup saat mesin K40 baru tiba |
