@echo off
REM Setup Python dependencies + create config from template

cd /d "%~dp0"

echo === Cek Python ===
python --version
if errorlevel 1 (
    echo.
    echo ERROR: Python tidak terinstall atau tidak ada di PATH.
    echo Download dari https://www.python.org/downloads/ dan centang "Add to PATH".
    pause
    exit /b 1
)

echo.
echo === Install dependencies ===
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
if errorlevel 1 (
    echo ERROR: gagal install dependencies.
    pause
    exit /b 1
)

echo.
echo === Create config.ini ===
if exist config.ini (
    echo config.ini sudah ada, skip.
) else (
    copy config.example.ini config.ini
    echo config.ini dibuat dari template. SILAKAN EDIT nilai-nya kalau perlu.
)

echo.
echo === Selesai ===
echo Next steps:
echo   1. Edit config.ini kalau IP/SN mesin atau endpoint ERP berbeda
echo   2. Jalankan test koneksi:  python test_connection.py
echo   3. Kalau test OK, jalankan: run.bat
echo.
pause
