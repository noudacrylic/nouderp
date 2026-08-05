#!/usr/bin/env bash
# ============================================================
# Backup database Noud ERP -> file .sql.gz di storage/backups/.
# Membaca kredensial DB langsung dari .env (tidak perlu set ulang).
# Pakai manual sebelum perubahan besar, ATAU jadwalkan harian via cron:
#   0 1 * * *  cd /var/www/noud-erp && ./deploy/backup-db.sh >> storage/logs/backup.log 2>&1
# Menyimpan 14 backup terakhir (sisanya dihapus otomatis).
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

# Ambil nilai dari .env
get_env() { grep -E "^$1=" .env | head -n1 | cut -d '=' -f2- | tr -d '"' | tr -d "'"; }
DB_DATABASE="$(get_env DB_DATABASE)"
DB_USERNAME="$(get_env DB_USERNAME)"
DB_PASSWORD="$(get_env DB_PASSWORD)"
DB_HOST="$(get_env DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"

BACKUP_DIR="$APP_DIR/storage/backups"
mkdir -p "$BACKUP_DIR"
STAMP="$(date '+%Y%m%d_%H%M%S')"
OUT="$BACKUP_DIR/${DB_DATABASE}_${STAMP}.sql.gz"

echo "==> Backup $DB_DATABASE -> $OUT"
mysqldump --single-transaction --quick --routines --no-tablespaces \
  -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" | gzip > "$OUT"

# Simpan 14 terbaru saja.
ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -n +15 | xargs -r rm -f

echo "==> Backup selesai ($(du -h "$OUT" | cut -f1))"
