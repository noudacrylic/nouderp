#!/usr/bin/env bash
#
# Pasang Laravel Reverb untuk Intercom divisi — sekali jalan, aman diulang.
#
#   cd /var/www/noud-erp && sudo bash deploy/setup-reverb.sh
#
# Yang dikerjakan:
#   1. .env      : isi REVERB_* (kunci dibuat acak bila belum ada) + BROADCAST_CONNECTION=reverb
#   2. supervisor: program `reverb` (php artisan reverb:start di 127.0.0.1:8080)
#   3. nginx     : blok `location /app` → 127.0.0.1:8080 (dicadangkan dulu; batal otomatis bila nginx -t gagal)
#   4. cek       : handshake WebSocket lokal & lewat domain
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$APP_DIR/.env"
PORT=8080

if [[ $EUID -ne 0 ]]; then
    echo "Jalankan dengan sudo: sudo bash deploy/setup-reverb.sh" >&2
    exit 1
fi
[[ -f "$ENV_FILE" ]] || { echo ".env tidak ditemukan di $APP_DIR" >&2; exit 1; }

APP_USER="$(stat -c %U "$APP_DIR/storage")"
PHP_BIN="$(command -v php)"
APP_URL="$(grep -E '^APP_URL=' "$ENV_FILE" | cut -d= -f2- | tr -d '"' || true)"
DOMAIN="$(echo "$APP_URL" | sed -E 's#^https?://##; s#[/:].*$##')"
[[ -n "$DOMAIN" ]] || { echo "APP_URL di .env kosong — tidak tahu domain ERP." >&2; exit 1; }

echo "== App: $APP_DIR (user $APP_USER), domain: $DOMAIN"

# ───────────── 1. .env ─────────────
set_env() {   # set_env KUNCI NILAI [hanya-bila-kosong]
    local k="$1" v="$2" only_empty="${3:-}"
    if grep -qE "^${k}=" "$ENV_FILE"; then
        local cur; cur="$(grep -E "^${k}=" "$ENV_FILE" | head -1 | cut -d= -f2-)"
        if [[ -n "$only_empty" && -n "$cur" ]]; then return; fi
        sed -i "s#^${k}=.*#${k}=${v}#" "$ENV_FILE"
    else
        printf '%s=%s\n' "$k" "$v" >> "$ENV_FILE"
    fi
}

cp "$ENV_FILE" "$ENV_FILE.bak-reverb-$(date +%Y%m%d%H%M%S)"
grep -q '^# Intercom divisi' "$ENV_FILE" || printf '\n# Intercom divisi — Laravel Reverb (deploy/setup-reverb.sh)\n' >> "$ENV_FILE"
set_env REVERB_APP_ID     "$(shuf -i 100000-999999 -n 1)"          only_empty
set_env REVERB_APP_KEY    "$("$PHP_BIN" -r 'echo bin2hex(random_bytes(10));')" only_empty
set_env REVERB_APP_SECRET "$("$PHP_BIN" -r 'echo bin2hex(random_bytes(16));')" only_empty
set_env BROADCAST_CONNECTION reverb
set_env REVERB_HOST        "$DOMAIN"
set_env REVERB_PORT        443
set_env REVERB_SCHEME      https
set_env REVERB_SERVER_HOST 127.0.0.1
set_env REVERB_SERVER_PORT "$PORT"
echo "== .env diperbarui (cadangan: $ENV_FILE.bak-reverb-*)"

sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" config:cache >/dev/null
echo "== config:cache selesai"

# ───────────── 2. supervisor ─────────────
if ! command -v supervisorctl >/dev/null; then
    echo "== Memasang supervisor…"
    apt-get update -qq && apt-get install -y -qq supervisor
    systemctl enable --now supervisor
fi
cat > /etc/supervisor/conf.d/reverb.conf <<EOF
[program:reverb]
command=$PHP_BIN $APP_DIR/artisan reverb:start --host=127.0.0.1 --port=$PORT
directory=$APP_DIR
user=$APP_USER
autostart=true
autorestart=true
stopwaitsecs=10
stdout_logfile=$APP_DIR/storage/logs/reverb.log
stdout_logfile_maxbytes=5MB
stdout_logfile_backups=3
redirect_stderr=true
minfds=10000
EOF
supervisorctl reread >/dev/null
supervisorctl update >/dev/null
supervisorctl restart reverb >/dev/null || supervisorctl start reverb >/dev/null
sleep 2
supervisorctl status reverb

# ───────────── 3. nginx ─────────────
NGINX_CONF="$(grep -lE "root\s+$APP_DIR/public" /etc/nginx/sites-enabled/* 2>/dev/null | head -1 || true)"
if [[ -z "$NGINX_CONF" ]]; then
    echo "!! Konfigurasi nginx untuk $APP_DIR/public tidak ditemukan — tambahkan blok location /app manual (lihat deploy/POST_DEPLOY.md)." >&2
else
    NGINX_CONF="$(readlink -f "$NGINX_CONF")"
    if grep -q 'location /app' "$NGINX_CONF"; then
        echo "== nginx: blok location /app sudah ada di $NGINX_CONF"
    else
        BAK="$NGINX_CONF.bak-reverb-$(date +%Y%m%d%H%M%S)"
        cp "$NGINX_CONF" "$BAK"
        # Sisipkan tepat sebelum `location / {` yang pertama.
        awk -v port="$PORT" '
            !done && /^[[:space:]]*location[[:space:]]+\/[[:space:]]*\{/ {
                ind = $0; sub(/location.*/, "", ind)
                print ind "# Intercom: WebSocket Laravel Reverb"
                print ind "location /app {"
                print ind "    proxy_pass http://127.0.0.1:" port ";"
                print ind "    proxy_http_version 1.1;"
                print ind "    proxy_set_header Upgrade $http_upgrade;"
                print ind "    proxy_set_header Connection \"Upgrade\";"
                print ind "    proxy_set_header Host $host;"
                print ind "    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;"
                print ind "    proxy_read_timeout 120s;"
                print ind "}"
                print ""
                done = 1
            }
            { print }
        ' "$BAK" > "$NGINX_CONF"
        if nginx -t 2>/dev/null; then
            systemctl reload nginx
            echo "== nginx: location /app ditambahkan ke $NGINX_CONF (cadangan: $BAK)"
        else
            cp "$BAK" "$NGINX_CONF"
            echo "!! nginx -t gagal — konfigurasi dikembalikan dari cadangan. Tambahkan manual." >&2
            nginx -t || true
        fi
    fi
fi

# ───────────── 4. cek ─────────────
KEY="$(grep -E '^REVERB_APP_KEY=' "$ENV_FILE" | cut -d= -f2-)"
cek() {
    curl -s -i -N --max-time 4 \
        -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" \
        -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" "$1" 2>/dev/null | grep -aq connection_established
}
cek "http://127.0.0.1:$PORT/app/$KEY?protocol=7" && echo "OK  Reverb lokal menjawab" || echo "GAGAL  Reverb lokal tidak menjawab — cek $APP_DIR/storage/logs/reverb.log"
cek "https://$DOMAIN/app/$KEY?protocol=7"       && echo "OK  WebSocket lewat https://$DOMAIN" || echo "GAGAL  WebSocket lewat domain — cek nginx/Cloudflare"

echo
echo "Selesai. Buka ERP → ikon 📞 di kanan atas → pilih stasiun → titik hijau = tersambung."
