# Catatan Pasca-Deploy

Langkah **manual** yang TIDAK otomatis dijalankan oleh `deploy/deploy.sh`.
(`deploy.sh` sudah meng-handle: backup DB → pull → composer → `migrate` → build aset →
`optimize:clear` + `optimize` (cache ulang config/route/view) → up. Jadi perubahan
route/view/console tidak perlu clear cache manual.)

---

## Rilis 2026-06-17

Tiga perubahan: (a) fix race-condition siklus di form Order Produksi, (b) Edit Finalisasi
menggantikan Void di Finalisasi Produksi, (c) absensi 100% dari log fingerprint.

### 1. Bersihkan jam absensi fabrikasi — WAJIB
Auto-attendance lama meng-inject jam palsu (masuk 08:00 / pulang 16:00 / lembur 20:00) untuk
karyawan tanpa scan — termasuk di hari libur. Batch itu sudah dimatikan; bersihkan histori
jam fabrikasi yang tidak didukung log fingerprint:

```bash
php artisan sdm:clean-fabricated-attendance              # DRY-RUN — cek dulu daftarnya
php artisan sdm:clean-fabricated-attendance --apply      # terapkan
```

Hanya menghapus slot jam yang = sentinel (`08:00:00` / `16:00:00` / `16:30:00` / `20:00:00`)
DAN tidak ada scan fingerprint pendukung di window jam itu. Baris `edited_manually` dan scan
asli (mis. 07:55:30, 16:01:09) tidak disentuh. Bisa dibatasi `--from=YYYY-MM-DD --to=YYYY-MM-DD`.

### 2. Koreksi order produksi OP/2026/06/00033 — opsional, review dulu
Order ini dibuat SEBELUM fix race-condition: `planned_cycles=4` padahal qty material/output
untuk 2 siklus (2 lembar akrilik sudah dikonsumsi, output 32 rak). Selaraskan ke 2 siklus
agar cocok dengan stok yang sudah keluar:

```sql
SELECT order_number, planned_cycles FROM production_orders WHERE order_number='OP/2026/06/00033';
-- bila benar 4, koreksi:
UPDATE production_orders SET planned_cycles=2 WHERE order_number='OP/2026/06/00033';
```

Order baru tidak terdampak — fix sudah mencegah desync siklus vs qty.

### 3. Pastikan scheduler & mesin fingerprint jalan
- Cron `* * * * * php artisan schedule:run` TETAP diperlukan (job produksi, Jubelio, periode, dll).
  Batch auto-attendance memang sudah dihapus dari scheduler — itu disengaja.
- Absensi kini 100% dari log fingerprint (ZKTeco K40 via ADMS). Pastikan mesin push log ke
  server. Hari kerja tanpa scan tampil kosong; izin/sakit/cuti/lupa-absen diisi manual HRD
  lewat dropdown status atau Upload Excel.

### Catatan Edit Finalisasi (tanpa langkah manual)
Tombol Void di Finalisasi Produksi diganti **Edit** (popup koreksi qty/%/keterangan). Edit
membalik finalisasi lama lalu menerapkan ulang secara atomik — FIFO (layer + ledger) dan
jurnal ikut tersesuaikan. Tidak bisa diedit bila stok output sudah terpakai di Surat Jalan/
transaksi lain (batalkan dokumen tersebut dulu).
