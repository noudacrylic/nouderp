@echo off
REM Run fingerprint sidecar in foreground (Ctrl+C to stop)

cd /d "%~dp0"

if not exist config.ini (
    echo ERROR: config.ini belum ada. Jalankan install.bat dulu.
    pause
    exit /b 1
)

python fingerprint_sync.py
pause
