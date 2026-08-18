@echo off
REM ============================================================
REM  Setup Windows Task Scheduler untuk HPY POS
REM  Jalankan SEKALI di tiap komputer kasir (klik kanan >
REM  Run as administrator). Mendaftarkan task "HPY POS Scheduler"
REM  yang menjalankan `php artisan schedule:run` tiap menit —
REM  fondasi auto sync ERP HPY, auto sync stok, dan backup harian.
REM ============================================================

setlocal

REM Folder project = folder induk dari folder scripts ini
set "PROJECT_DIR=%~dp0.."
for %%I in ("%PROJECT_DIR%") do set "PROJECT_DIR=%%~fI"

REM Cari php.exe: pakai yang di PATH, fallback ke lokasi XAMPP standar
set "PHP_EXE=php"
where php >nul 2>nul
if errorlevel 1 (
    if exist "C:\xampp\php\php.exe" (
        set "PHP_EXE=C:\xampp\php\php.exe"
    ) else (
        echo [GAGAL] php.exe tidak ditemukan di PATH maupun C:\xampp\php.
        echo         Install XAMPP/PHP dulu, atau edit skrip ini dan isi PHP_EXE manual.
        pause
        exit /b 1
    )
)

echo Project : %PROJECT_DIR%
echo PHP     : %PHP_EXE%
echo.

REM /sc minute /mo 1  = tiap 1 menit
REM /ru SYSTEM        = jalan di background tanpa perlu user login,
REM                     dan tidak memunculkan jendela terminal
schtasks /create /f ^
    /tn "HPY POS Scheduler" ^
    /sc minute /mo 1 ^
    /ru SYSTEM ^
    /tr "\"%PHP_EXE%\" \"%PROJECT_DIR%\artisan\" schedule:run"

if errorlevel 1 (
    echo.
    echo [GAGAL] Task tidak terdaftar. Pastikan skrip dijalankan sebagai Administrator.
    pause
    exit /b 1
)

echo.
echo [OK] Task "HPY POS Scheduler" terdaftar — schedule:run jalan tiap menit.
echo      Cek: schtasks /query /tn "HPY POS Scheduler"
echo      Hapus: schtasks /delete /tn "HPY POS Scheduler" /f
echo.
echo Langkah berikutnya: buka halaman Sync HPY di POS dan aktifkan
echo toggle "Auto Sync Semua dari ERP HPY".
pause
