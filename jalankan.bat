@echo off
chcp 65001 >nul
title WMS Dekor Asia Jayakarya - Server
cd /d "%~dp0"
set "PHPEXE="
where php >nul 2>nul && set "PHPEXE=php"
if not defined PHPEXE for /d %%D in (C:\laragon\bin\php\php-*) do if exist "%%D\php.exe" set "PHPEXE=%%D\php.exe"
if not defined PHPEXE (
  echo PHP tidak ditemukan. Install Laragon atau tambahkan PHP ke PATH.
  pause
  exit /b 1
)
echo Memakai PHP: %PHPEXE%
echo.
echo Pastikan MySQL sudah hijau di Laragon (Start All).
echo Server berjalan di http://localhost:8081  - tutup jendela ini untuk menghentikan.
echo.
start "" http://localhost:8081/admin/cek_sistem.php
"%PHPEXE%" -S localhost:8081
echo.
echo Server berhenti. Jika ada pesan "Failed to listen", port 8081 dipakai program lain.
pause
