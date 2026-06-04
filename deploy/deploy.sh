#!/usr/bin/env bash
# ============================================================
# Skrip deploy 1-perintah untuk Noud ERP (server Ubuntu).
# Pakai SETELAH commit baru sudah di-push & kamu siap update server:
#   cd /var/www/noud-erp && ./deploy/deploy.sh
#
# Yang dilakukan: backup DB -> maintenance mode -> pull kode ->
# install dependency -> migrate -> build aset -> bersihkan cache -> up lagi.
# Aman & idempotent. Kalau ada langkah gagal, skrip berhenti (set -e).
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

echo "==> [1/8] Backup database dulu (jaga-jaga)"
./deploy/backup-db.sh || { echo "Backup gagal — deploy dibatalkan."; exit 1; }

echo "==> [2/8] Maintenance mode ON"
php artisan down --render="errors::503" --retry=15 || true

# Pastikan tetap 'up' lagi walau ada error di tengah jalan.
trap 'php artisan up || true' EXIT

echo "==> [3/8] Tarik kode terbaru (git)"
git pull --ff-only

echo "==> [4/8] Composer (produksi, tanpa dev)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> [5/8] Migrasi database (aman, tidak hapus data)"
php artisan migrate --force

echo "==> [6/8] Build aset frontend (Vite)"
npm ci
npm run build

echo "==> [7/8] Bersihkan & cache ulang konfigurasi"
php artisan optimize:clear
php artisan optimize        # cache config + route + view

echo "==> [8/8] Maintenance mode OFF"
php artisan up
trap - EXIT

echo "==> Deploy selesai: $(date '+%Y-%m-%d %H:%M:%S')"
