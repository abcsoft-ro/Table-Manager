@echo off
setlocal EnableExtensions
set "TASKNAME=TableManager\Backup DB"

rem Locatia proiectului = parintele folderului acestui script (tools\)
for %%I in ("%~dp0..") do set "APP=%%~fI"
set "TOOLS=%APP%\tools"
set "PS1=%TOOLS%\backup-db.ps1"
set "XMLIN=%TOOLS%\backup-db-task.xml"
set "XMLTMP=%TEMP%\tablemanager-backup-task.xml"

echo === TableManager - montare task backup baza de date ===
echo Folder aplicatie: %APP%
echo.

rem Task-ul ruleaza ca SYSTEM, deci inregistrarea cere drepturi de administrator.
net session >nul 2>&1
if errorlevel 1 (
  echo [EROARE] Rulati acest script cu drepturi de administrator
  echo          ^(click dreapta -^> "Run as administrator"^).
  exit /b 1
)

if not exist "%PS1%" (
  echo [EROARE] Nu gasesc "%PS1%".
  exit /b 1
)
if not exist "%XMLIN%" (
  echo [EROARE] Nu gasesc "%XMLIN%".
  exit /b 1
)

rem Rescrie calea din XML cu locatia reala a proiectului.
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p = Get-Content -Raw -LiteralPath '%XMLIN%'; $p = $p.Replace('C:\Apache24\htdocs\rual','%APP%'); [System.IO.File]::WriteAllText('%XMLTMP%', $p, (New-Object System.Text.UTF8Encoding($false)))"
if errorlevel 1 (
  echo [EROARE] Nu am putut pregati XML-ul.
  exit /b 1
)

schtasks /Create /TN "%TASKNAME%" /XML "%XMLTMP%" /F
if errorlevel 1 (
  echo [EROARE] Inregistrarea task-ului a esuat.
  del "%XMLTMP%" >nul 2>&1
  exit /b 1
)

del "%XMLTMP%" >nul 2>&1
echo.
echo [OK] Task "%TASKNAME%" inregistrat ^(zilnic la 03:00, ca SYSTEM^).
echo      Test manual acum : schtasks /Run /TN "%TASKNAME%"
echo      Verificare log   : type "%APP%\logs\backup_db.log"
endlocal
exit /b 0
