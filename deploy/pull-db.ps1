<#
================================================================
  Tarik (pull) database dari server Ubuntu on-prem -> XAMPP lokal.
  Otomatis: dump di server (backup-db.sh) -> scp ke sini -> impor.

  Pakai:
    .\deploy\pull-db.ps1 -Server noud@192.168.1.50
    .\deploy\pull-db.ps1 -Server noud@192.168.1.50 -RemoteDir /var/www/noud-erp

  PRASYARAT:
    - Bisa SSH ke server (idealnya pakai SSH key, biar tak tanya password berulang).
    - XAMPP MySQL lokal sedang jalan.

  PERINGATAN: script ini MENIMPA TOTAL database lokal dengan data server.
================================================================
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$Server,                                  # contoh: noud@192.168.1.50
    [string]$RemoteDir = "/var/www/noud-erp",         # path aplikasi di server
    [string]$LocalDb   = "noud_erp",                  # nama DB lokal (lihat .env)
    [string]$MysqlBin  = "C:\xampp\mysql\bin\mysql.exe"
)

$ErrorActionPreference = "Stop"
$AppDir    = Split-Path -Parent $PSScriptRoot
$BackupDir = Join-Path $AppDir "storage\backups"
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

Write-Host "==> 1/4  Dump database di server ($Server) ..." -ForegroundColor Cyan
ssh $Server "cd $RemoteDir && ./deploy/backup-db.sh"
if ($LASTEXITCODE -ne 0) { throw "Gagal menjalankan backup-db.sh di server." }

Write-Host "==> 2/4  Cari file backup terbaru di server ..." -ForegroundColor Cyan
$Remote = (ssh $Server "ls -1t $RemoteDir/storage/backups/*.sql.gz | head -n1").Trim()
if ([string]::IsNullOrWhiteSpace($Remote)) { throw "Tidak menemukan file backup di server." }
$FileName = Split-Path $Remote -Leaf
$LocalGz  = Join-Path $BackupDir $FileName
Write-Host "    $Remote"

Write-Host "==> 3/4  Tarik file ke lokal ($LocalGz) ..." -ForegroundColor Cyan
scp "${Server}:${Remote}" $LocalGz
if ($LASTEXITCODE -ne 0) { throw "Gagal scp file backup." }

# Extract .sql.gz -> .sql
$LocalSql = $LocalGz -replace '\.gz$', ''
& "C:\Program Files\Git\usr\bin\gzip.exe" -d -k -f $LocalGz
if (-not (Test-Path $LocalSql)) { throw "Gagal extract $LocalGz" }

Write-Host "==> 4/4  Impor ke MySQL lokal (DB: $LocalDb) — MENIMPA data lokal ..." -ForegroundColor Yellow
& $MysqlBin -u root -e "DROP DATABASE IF EXISTS ``$LocalDb``; CREATE DATABASE ``$LocalDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$sqlPathForward = $LocalSql -replace '\\', '/'
& $MysqlBin -u root $LocalDb -e "source $sqlPathForward"
if ($LASTEXITCODE -ne 0) { throw "Gagal impor ke MySQL lokal." }

Remove-Item $LocalSql -Force   # sisakan .gz, buang .sql hasil extract
Write-Host "==> SELESAI. Data lokal '$LocalDb' kini sama dengan server (snapshot $FileName)." -ForegroundColor Green
