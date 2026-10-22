@echo off
chcp 65001 > nul
title Enviar branch atual para o GitHub
echo ========================================================
echo   Enviando branch atual para o GitHub
echo ========================================================
echo.
set "PATH=%LOCALAPPDATA%\Microsoft\WinGet\Packages\Git.MinGit_Microsoft.Winget.Source_8wekyb3d8bbwe\cmd;%LOCALAPPDATA%\Microsoft\WinGet\Packages\Git.MinGit_Microsoft.Winget.Source_8wekyb3d8bbwe\ucrt64\bin;%PATH%"

for /f "tokens=*" %%b in ('git branch --show-current') do set "CURR_BRANCH=%%b"
if "%CURR_BRANCH%"=="" set "CURR_BRANCH=ajuste-selecao-veiculos"

echo Branch detectada: %CURR_BRANCH%
echo Enviando para: origin/%CURR_BRANCH% ...
echo.

git push -u origin %CURR_BRANCH%

echo.
if %ERRORLEVEL% EQU 0 (
    echo ========================================================
    echo   [SUCESSO] Branch %CURR_BRANCH% enviada com sucesso!
    echo ========================================================
) else (
    echo ========================================================
    echo   Ocorreu um erro no envio. Verifique o login acima.
    echo ========================================================
)
echo.
pause

