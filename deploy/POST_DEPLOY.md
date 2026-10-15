# Catatan Pasca-Deploy

Langkah **manual** yang TIDAK otomatis dijalankan oleh `deploy/deploy.sh`.
(`deploy.sh` sudah meng-handle: backup DB → pull → composer → `migrate` → build aset →
`optimize:clear` + `optimize` (cache ulang config/route/view) → up. Jadi perubahan
route/view/console tidak perlu clear cache manual.)

---

## Rilis 2026-10-15 — Intercom divisi (Laravel Reverb + WebRTC)

Suara dua arah antar stasiun (Admin, Packing, CNC, Asembling). Suaranya WebRTC langsung antar
perangkat di Wi-Fi kantor; server hanya meneruskan **sinyal** lewat WebSocket (Laravel Reverb).
`deploy.sh` sudah menjalankan `composer install` + `migrate` (tabel `intercom_calls`).

**Cara cepat:** semua langkah 1–4 di bawah dikerjakan otomatis oleh
`sudo bash deploy/setup-reverb.sh` (aman diulang; `.env` & konfigurasi nginx dicadangkan dulu,
nginx dikembalikan otomatis bila `nginx -t` gagal). Rinciannya, bila ingin manual:

### 1. `.env` server
```bash
php -r 'echo "REVERB_APP_ID=".random_int(100000,999999)."\nREVERB_APP_KEY=".bin2hex(random_bytes(10))."\nREVERB_APP_SECRET=".bin2hex(random_bytes(16))."\n";'
```
Tempel hasilnya ke `.env`, lalu tambahkan:
```
BROADCAST_CONNECTION=reverb
REVERB_HOST=<domain ERP, mis. u.noudakrilik.com>
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=6001
```
`REVERB_HOST/PORT/SCHEME` = alamat **publik** yang dipakai peramban. Reverb sendiri hanya
mendengar di `127.0.0.1:6001`; Nginx yang meneruskan. Lalu `php artisan config:cache`.

### 2. Jalankan Reverb terus-menerus (supervisor)
`/etc/supervisor/conf.d/reverb.conf`:
```ini
[program:reverb]
command=php /var/www/noud-erp/artisan reverb:start --host=127.0.0.1 --port=6001
directory=/var/www/noud-erp
user=www-data
autostart=true
autorestart=true
stopwaitsecs=10
stdout_logfile=/var/www/noud-erp/storage/logs/reverb.log
redirect_stderr=true
minfds=10000
```
```bash
sudo apt install -y supervisor   # bila belum ada
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status reverb
```
**Setiap deploy berikutnya** yang mengubah config/kode Reverb: `sudo supervisorctl restart reverb`
(atau `php artisan reverb:restart`).

### 3. Nginx — teruskan WebSocket `/app` ke Reverb
Di blok `server { ... }` ERP, **sebelum** `location /`:
```nginx
location /app {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 120s;
}
```
`sudo nginx -t && sudo systemctl reload nginx`. Cloudflare (Tunnel/proxy) meneruskan WebSocket
tanpa pengaturan tambahan.

### 4. Cek
- Handshake WebSocket lewat domain (harus `101 Switching Protocols` + `pusher:connection_established`):
  ```bash
  curl -s -i -N --max-time 3 -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" "https://<domain>/app/<REVERB_APP_KEY>?protocol=7" | head -c 300
  ```
  (Request HTTP biasa tanpa header Upgrade memang dibalas 500 oleh Reverb — itu normal.)
- Buka ERP → ikon 📞 di kanan atas → pilih stasiun → titik **hijau** = tersambung ke Reverb.

### 5. Di lapangan (sekali per perangkat)
- Router/AP kantor: **"AP isolation" / "client isolation" harus MATI** — kalau nyala, sinyal
  tersambung tapi suara tidak (pesan "Suara tidak bisa tersambung").
- Tiap perangkat: 📞 → pilih stasiun → **Aktifkan mikrofon** → izinkan.
- Tablet/HP stasiun: biarkan `/erp/intercom` terbuka di depan, dicolok charger. Bila browser
  masuk background/layar terkunci, Android memutus koneksinya dan stasiun tidak bisa dipanggil.

---

## Rilis 2026-08-03 — Metode bayar Midtrans yang ditawarkan bisa diatur

Halaman `/pay` sebelumnya memajang keenam metode apa adanya, termasuk Alfamart dan
Kredivo/Akulaku yang butuh **pengajuan terpisah** ke Midtrans. Pembeli yang memilih metode
belum-disetujui mentok di halaman Snap tanpa penjelasan.

`deploy.sh` sudah menjalankan `migrate`, jadi tidak ada langkah teknis tambahan. Bawaannya
aman: **QRIS + Virtual Account + E-Wallet** saja.

### Yang perlu dicek sekali setelah deploy
**Pengaturan → Midtrans → tabel "Tarif & Subsidi per Metode" → kolom Aktif.** Centang metode
hanya setelah channel-nya benar-benar disetujui di dashboard Midtrans. Kartu Kredit dibiarkan
tidak aktif karena statusnya belum dikonfirmasi — centang bila sudah aktif.

Etalase (repo `web`) memajang logo metode di footer & halaman `/cara-belanja`. Daftar itu
masih ditulis manual di `lib/site.ts`, jadi **kalau kolom Aktif berubah, perbarui juga di
sana** supaya situs tidak menjanjikan metode yang tak bisa dipilih.

---

## Rilis 2026-06-29 — Modul Store (Produk Store + Media R2 + API Etalase)

Fondasi website toko (noudakrilik.com): menu **Store** (Kategori + Produk Store dengan varian),
galeri media via **Cloudflare R2**, dan **API jembatan** `/api/storefront/*`.

### 1. Dependency baru — WAJIB `composer install`
Driver S3 `league/flysystem-aws-s3-v3` ditambahkan (dipakai R2). `deploy/deploy.sh` **sudah**
menjalankan `composer install --no-dev` (step 4) + `migrate` (step 5), jadi cukup deploy normal:

```bash
cd /var/www/noud-erp && ./deploy/deploy.sh
```

Bila deploy MANUAL (tanpa skrip), jangan lupa keduanya:
```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader   # WAJIB — tanpa ini disk R2 error "class not found"
php artisan migrate --force                        # tabel store_*, r2_settings, storefront_settings, dst
php artisan optimize:clear && php artisan optimize
```

### 2. Konfigurasi Cloudflare R2 — sekali, lewat UI (bukan .env)
Settings → Integrasi → **Cloudflare R2**: isi Access Key ID + Secret (dari bagian *"S3 clients"*
di halaman R2 API Token, BUKAN "Token value" `cfat_...`), Bucket, Endpoint S3 (tanpa nama bucket),
URL Publik (`https://galeri.noudakrilik.com`), Region `auto`, Path-style ✓ → Aktifkan → Simpan →
**Uji Koneksi**. Saat R2 nonaktif, media jatuh ke disk lokal `public` (butuh `php artisan storage:link`).

### 3. Kunci API etalase — sekali, lewat UI
Settings → Integrasi → **Etalase Website**: Simpan (kunci dibuat otomatis) → Aktifkan. Kunci ini
dipakai server etalase (VPS/Cloudflare) untuk memanggil `/api/storefront/*`.

### 4. Scheduler (sudah ada cron `schedule:run`)
Job baru `store:gc-media` (harian 03:10) membersihkan file media yang sudah dihapus & lewat masa
jeda. Tidak ada langkah manual selama cron `* * * * * php artisan schedule:run` aktif.

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
