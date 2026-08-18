<#
================================================================
  Tarik (pull) database dari server Ubuntu on-prem -> XAMPP lokal.
  Otomatis: backup DB lokal -> dump di server -> scp -> impor -> beres-beres.

  Pakai:
    .\deploy\pull-db.ps1
    .\deploy\pull-db.ps1 -Server noud@192.168.1.50 -RemoteDir /var/www/noud-erp

  PRASYARAT:
    - Bisa SSH ke server. Tanpa kunci SSH, sandi akan ditanyakan 3x
      (dump, cari file, scp) — jalankan di terminal biasa, bukan lewat skrip lain.
    - XAMPP MySQL lokal sedang jalan.

  PERINGATAN: script ini MENIMPA TOTAL database lokal dengan data server.
  Cadangan DB lokal dibuat lebih dulu di storage\backups\lokal-sebelum-impor-*.sql.
================================================================
#>
param(
    [string]$Server    = "u.noudakrilik.com",         # lihat ~/.ssh/config (lewat cloudflared)
    [string]$RemoteDir = "/var/www/noud-erp",         # path aplikasi di server
    [string]$LocalDb   = "noud_erp",                  # nama DB lokal (lihat .env)
    [string]$MysqlBin  = "C:\xampp\mysql\bin\mysql.exe",
    [string]$DumpBin   = "C:\xampp\mysql\bin\mysqldump.exe"
)

$ErrorActionPreference = "Stop"
$AppDir    = Split-Path -Parent $PSScriptRoot
$BackupDir = Join-Path $AppDir "storage\backups"
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

# ── 0. Cadangkan dulu yang lokal ────────────────────────────────────────
# `DROP DATABASE` pernah membuat mysqld crash saat server sedang tidak stabil,
# dan tanpa cadangan tidak ada jalan pulang. Murah, jadi selalu dikerjakan.
$Stamp      = Get-Date -Format "yyyyMMdd_HHmmss"
$LocalDump  = Join-Path $BackupDir "lokal-sebelum-impor-$Stamp.sql"
Write-Host "==> 1/6  Cadangkan DB lokal -> $LocalDump" -ForegroundColor Cyan
& $DumpBin --single-transaction --quick --routines --no-tablespaces -u root $LocalDb `
    --result-file="$LocalDump"
if ($LASTEXITCODE -ne 0) { throw "Gagal mencadangkan DB lokal — dihentikan sebelum menimpa apa pun." }

Write-Host "==> 2/6  Dump database di server ($Server) ..." -ForegroundColor Cyan
ssh $Server "cd $RemoteDir && ./deploy/backup-db.sh"
if ($LASTEXITCODE -ne 0) { throw "Gagal menjalankan backup-db.sh di server." }

Write-Host "==> 3/6  Cari file backup terbaru di server ..." -ForegroundColor Cyan
$Remote = (ssh $Server "ls -1t $RemoteDir/storage/backups/*.sql.gz | head -n1").Trim()
if ([string]::IsNullOrWhiteSpace($Remote)) { throw "Tidak menemukan file backup di server." }
$FileName = Split-Path $Remote -Leaf
$LocalGz  = Join-Path $BackupDir $FileName
Write-Host "    $Remote"

Write-Host "==> 4/6  Tarik file ke lokal ($LocalGz) ..." -ForegroundColor Cyan
scp "${Server}:${Remote}" $LocalGz
if ($LASTEXITCODE -ne 0) { throw "Gagal scp file backup." }

# Extract .sql.gz -> .sql
$LocalSql = $LocalGz -replace '\.gz$', ''
& "C:\Program Files\Git\usr\bin\gzip.exe" -d -k -f $LocalGz
if (-not (Test-Path $LocalSql)) { throw "Gagal extract $LocalGz" }

# Direktif "sandbox mode" di baris pertama dump MariaDB 10.11 tidak dikenal client
# XAMPP 10.4 dan menggagalkan seluruh impor dengan `Unknown command '\-'`.
$raw = Get-Content -Raw $LocalSql
if ($raw -match 'enable the sandbox mode') {
    Write-Host "    (membuang direktif sandbox MariaDB dari baris pertama)" -ForegroundColor DarkGray
    $raw = $raw -replace '/\*M!999999\\- enable the sandbox mode \*/\s*', ''
    Set-Content -Path $LocalSql -Value $raw -Encoding utf8 -NoNewline
}

Write-Host "==> 5/6  Impor ke MySQL lokal (DB: $LocalDb) — MENIMPA data lokal ..." -ForegroundColor Yellow
& $MysqlBin -u root -e "DROP DATABASE IF EXISTS ``$LocalDb``; CREATE DATABASE ``$LocalDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw "Gagal membuat ulang database lokal." }
# Redirect lewat cmd, bukan `-e "source ..."`: `source` menelan galat baris demi
# baris dan tetap keluar dengan kode 0, jadi impor rusak terbaca sebagai sukses.
cmd /c "`"$MysqlBin`" -u root $LocalDb < `"$LocalSql`""
if ($LASTEXITCODE -ne 0) { throw "Gagal impor ke MySQL lokal." }

Write-Host "==> 6/6  Beres-beres pasca-impor ..." -ForegroundColor Cyan
# Kolom terenkripsi (`encrypted` cast) di-encrypt dengan APP_KEY SERVER, sedangkan
# lokal punya APP_KEY sendiri — setiap pembacaannya melempar "The MAC is invalid".
# Untuk Jubelio itu fatal: InventoryLedgerObserver memanggil isConfigured() pada
# SETIAP penulisan ledger, jadi seluruh mutasi stok lokal ikut gagal. Dimatikan
# saja; ini salinan untuk pratinjau, bukan penerus operasi server.
& $MysqlBin -u root $LocalDb -e "UPDATE jubelio_settings SET is_active = 0, password = NULL;" 2>$null
# Sama untuk R2: kalau tetap aktif, unggah gambar dari ERP lokal gagal mendekripsi
# kuncinya. Dimatikan -> unggahan lokal jatuh ke disk publik lokal. URL gambar yang
# SUDAH tersimpan tetap absolut ke R2, jadi foto lama tetap tampil.
& $MysqlBin -u root $LocalDb -e "UPDATE r2_settings SET is_active = 0;" 2>$null

# Migrasi yang baru ada di lokal (belum pernah jalan di server) disusulkan di sini.
Push-Location $AppDir
php artisan migrate --force
php artisan optimize:clear | Out-Null
Pop-Location

Remove-Item $LocalSql -Force   # sisakan .gz, buang .sql hasil extract
Write-Host "==> SELESAI. Data lokal '$LocalDb' kini sama dengan server (snapshot $FileName)." -ForegroundColor Green
Write-Host "    Cadangan data lokal sebelumnya: $LocalDump" -ForegroundColor DarkGray
