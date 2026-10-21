@echo off
chcp 65001 > nul
title Servidor contaBuzu
echo ========================================================
echo         Iniciando Servidor contaBuzu (PHP 8.2)
echo ========================================================
echo Acesse no seu navegador: http://127.0.0.1:8000
echo Pressione Ctrl+C para encerrar o servidor.
echo ========================================================
start http://127.0.0.1:8000

where php >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    php -S 127.0.0.1:8000
) else (
    "%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" -S 127.0.0.1:8000
)
