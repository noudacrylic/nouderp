# Deploy Noud ERP — Server Ubuntu (on-premise)

Panduan deploy & operasional. Skenario: **server fisik di kantor (se-LAN dengan mesin fingerprint), punya domain, database mulai fresh.**

> Stack: PHP 8.3 + Nginx + PHP-FPM, MariaDB, Node 20 + Chromium (untuk PDF), cron scheduler. **Tanpa queue worker** (aplikasi tidak memakai job antrian).

---

## A. Setup awal server (sekali saja)

### 1. Paket
```bash
sudo apt update && sudo apt install -y nginx mariadb-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath php8.3-fileinfo \
  unzip git curl
# Node 20 (Vite build + Chromium browsershot)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
# Chromium + dependensi headless untuk PDF (browsershot)
sudo apt install -y chromium-browser fonts-liberation libnss3 libatk-bridge2.0-0 libgbm1 libasound2
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

### 2. Database (fresh)
```bash
sudo mysql -e "CREATE DATABASE noud_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'noud'@'localhost' IDENTIFIED BY 'PASSWORD_KUAT';
GRANT ALL ON noud_erp.* TO 'noud'@'localhost'; FLUSH PRIVILEGES;"
```

### 3. Kode + aplikasi
```bash
sudo git clone <URL_REPO> /var/www/noud-erp && cd /var/www/noud-erp
composer install --no-dev --optimize-autoloader
cp .env.production.example .env      # lalu edit nilai DB & APP_URL
php artisan key:generate

# --- Pilihan A: cepat (ada DATA CONTOH) ---
php artisan migrate --seed           # struktur DB + COA, admin (admin/admin123), periode, gudang
#   ⚠ Seeder JUGA membuat 1 produk & 2 customer CONTOH (Kotak Saran, Suwandi/Shopee).
#     Hapus dari UI setelah login bila mau bersih.

# --- Pilihan B: bersih (TANPA produk/customer contoh) — disarankan utk produksi nyata ---
# php artisan migrate
# php artisan db:seed --class=ChartOfAccountSeeder
# php artisan db:seed --class=UserSeeder
# php artisan db:seed --class=WarehouseSeeder
# php artisan db:seed --class=AccountingPeriodSeeder
# php artisan db:seed --class=TaskCategorySeeder
#   (produk & customer asli diimpor via Excel / diinput di UI)

# Opsional bila pakai fitur terkait:
# php artisan db:seed --class=ProductionAccountSeeder
# php artisan db:seed --class=TaxSettingSeeder
npm ci && npm run build
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan optimize
```
> Login pertama pakai admin default (`admin` / `admin123`) — **segera ganti passwordnya**.

### 4. Cron scheduler — WAJIB
Tanpa ini: sync Jubelio, auto-produksi, absensi otomatis, task scheduler, & periode akuntansi semua mati.
```bash
sudo crontab -u www-data -e
# tambahkan:
* * * * * cd /var/www/noud-erp && php artisan schedule:run >> /dev/null 2>&1
# backup DB harian jam 01:00:
0 1 * * * cd /var/www/noud-erp && ./deploy/backup-db.sh >> storage/logs/backup.log 2>&1
```

### 5. Nginx (root ke /public)
`/etc/nginx/sites-available/noud-erp`:
```nginx
server {
    listen 80;
    server_name erp.domainanda.com;
    root /var/www/noud-erp/public;
    index index.php;

    client_max_body_size 50M;   # untuk upload Excel / lampiran

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/noud-erp /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 6. Domain + HTTPS — **Cloudflare Tunnel (DIREKOMENDASIKAN utk on-premise)**
Mengekspos server ke internet **tanpa port-forward / IP publik statis** (cloudflared konek keluar
ke Cloudflare). SSL otomatis, cocok di belakang NAT / IP dinamis. Nginx cukup `listen 80;` lokal.
```bash
# Install cloudflared
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb -o /tmp/cloudflared.deb
sudo dpkg -i /tmp/cloudflared.deb

cloudflared tunnel login            # buka URL yg muncul di browser → pilih domain noudakrilik.com
cloudflared tunnel create noud-erp  # catat TUNNEL_ID + path file kredensial .json

# /etc/cloudflared/config.yml :
#   tunnel: <TUNNEL_ID>
#   credentials-file: /root/.cloudflared/<TUNNEL_ID>.json
#   ingress:
#     - hostname: erp.noudakrilik.com
#       service: http://localhost:80
#     - service: http_status:404

cloudflared tunnel route dns noud-erp erp.noudakrilik.com   # otomatis bikin CNAME di Cloudflare
sudo cloudflared service install && sudo systemctl enable --now cloudflared
```
- Set `APP_URL=https://erp.noudakrilik.com` di `.env` (TrustProxies sudah `*`, Laravel deteksi HTTPS).
- **Firewall**: TIDAK perlu buka 80/443 inbound — cukup `sudo ufw allow 22/tcp && sudo ufw enable`.
- Dashboard Cloudflare → SSL/TLS mode: **Full** (koneksi via tunnel sudah terenkripsi).

### 6b. Alternatif: domain langsung (port-forward + certbot)
- DNS `A record` → IP publik kantor (IP dinamis → DDNS). Port-forward router 80 & 443 → IP lokal server.
- SSL: `sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d erp.noudakrilik.com`
- Firewall: `sudo ufw allow 22,80,443/tcp && sudo ufw enable`

### 7. Mesin fingerprint (ZKTeco) — tetap di LAN
Arahkan mesin ke **IP lokal server** (mis. `192.168.1.x`) port 80, endpoint `/iclock/*` (sudah dikecualikan CSRF). Se-LAN → tidak butuh internet.

### 8. Firewall
```bash
sudo ufw allow 22,80,443/tcp && sudo ufw enable
```

---

## B. Deploy perubahan berikutnya (rutin)

Setelah ada perbaikan/fitur baru yang sudah di-commit & di-push dari lokal:
```bash
cd /var/www/noud-erp && ./deploy/deploy.sh
```
Skrip otomatis: backup DB → maintenance mode → `git pull` → `composer install` → `migrate` → build aset → cache ulang → online lagi.

---

## C. Alur perbaikan bug (efisien & aman)

1. Reproduce di lokal (XAMPP). Bug spesifik data? Tarik dump DB prod: `./deploy/backup-db.sh` di server, salin `.sql.gz`, import ke lokal.
2. Perbaiki kode di lokal → tes (`php artisan test` bila sudah ada test).
3. `git commit` + `git push`.
4. Di server: `./deploy/deploy.sh`.
5. Salah? `git revert <commit>` + push + deploy lagi (rollback aman).

**Aturan emas:** perubahan struktur DB **selalu** lewat migration (`php artisan make:migration`), jangan SQL manual di phpMyAdmin prod.

---

## D. Troubleshooting

| Gejala | Solusi |
| --- | --- |
| **Cetak PDF gagal** (invoice/SO/resi/slip gaji) | Chromium/Node tidak terdeteksi. Set `BROWSERSHOT_NODE_BINARY` & `BROWSERSHOT_CHROME_PATH` di `.env` (lihat `.env.production.example`). Cek `which chromium-browser` & `which node`. |
| **Error 500 setelah deploy** | Cek `storage/logs/laravel.log`. Sering: permission `storage/` belum `www-data`, atau `php artisan optimize` perlu diulang. |
| **Fitur otomatis tidak jalan** | Cek cron scheduler terpasang: `sudo crontab -u www-data -l`. |
| **Webhook tidak masuk** | Pastikan SSL aktif & port-forward 443 jalan. `APP_URL` harus `https://...`. |
| **Halaman blank / aset hilang** | `npm run build` belum dijalankan, atau `php artisan storage:link` belum. |
