@echo off
chcp 65001 >nul
cd /d "%~dp0admin"
set "PHPEXE="
where php >nul 2>nul && set "PHPEXE=php"
if not defined PHPEXE for /d %%D in (C:\laragon\bin\php\php-*) do if exist "%%D\php.exe" set "PHPEXE=%%D\php.exe"
if not defined PHPEXE (
  echo PHP tidak ditemukan.
  exit /b 1
)
"%PHPEXE%" backup_cli.php --simpan=14
