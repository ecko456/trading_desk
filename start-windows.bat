@echo off
setlocal
title Trading Desk
chcp 65001 >nul

echo.
echo   Spoustim Trading Desk...
echo.

where wsl >nul 2>nul
if errorlevel 1 (
    echo   WSL neni k dispozici.
    echo   Otevri soubor INSTALL.md a projdi sekci "Windows".
    echo.
    pause
    exit /b 1
)

rem Nastartuje Ubuntu. Diky nastaveni v /etc/wsl.conf se pritom spusti i Apache.
wsl -e true >nul 2>nul
if errorlevel 1 (
    echo   Ubuntu se nepodarilo nastartovat.
    echo   Zkus otevrit Ubuntu z nabidky Start a pak spustit tento soubor znovu.
    echo.
    pause
    exit /b 1
)

rem Pojistka, kdyby se Apache sam nespustil.
wsl -e bash -lc "pgrep apache2 >/dev/null || service apache2 start >/dev/null 2>&1 || true" >nul 2>nul

echo   Cekam, az naskoci server...
set TRIES=0
:wait
set /a TRIES+=1
wsl -e bash -lc "curl -fsS 'http://localhost/trading/api.php?action=health' >/dev/null" >nul 2>nul
if not errorlevel 1 goto ready
if %TRIES% GEQ 15 goto failed
timeout /t 1 /nobreak >nul
goto wait

:failed
echo.
echo   Server neodpovida.
echo   Otevri Ubuntu a spust:  sudo service apache2 start
echo   Pak zkus tento soubor znovu.
echo.
pause
exit /b 1

:ready
echo   Hotovo. Otevriram prohlizec.
echo.
start "" http://localhost/trading/
timeout /t 2 /nobreak >nul
