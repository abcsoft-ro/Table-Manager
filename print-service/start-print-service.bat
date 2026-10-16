@echo off
REM Porneste serviciul de tiparire TableManager.
REM Se poate pune un shortcut in shell:startup pentru pornire automata, DOAR in
REM contul de utilizator al statiei (NICIODATA in contul de service): pe statiile
REM cu doua conturi ar porni doua instante ale serviciului.
setlocal
cd /d "%~dp0"

if not exist "logs" mkdir "logs"
if not exist "preview" mkdir "preview"
if not exist "spool\fiscal" mkdir "spool\fiscal"

rem Mediul virtual Python (obligatoriu), in radacina proiectului.
set "PYEXE=%~dp0..\.venv\Scripts\python.exe"
if not exist "%PYEXE%" (
    echo.
    echo EROARE: nu exista mediul virtual Python "%PYEXE%"
    echo Rulati mai intai setup-venv.bat din radacina proiectului.
    pause
    exit /b 1
)

rem O SINGURA instanta: daca portul serviciului asculta deja (a fost pornit in
rem alta sesiune / de alt cont, sau a fost pornit manual din Setari -> Servicii),
rem NU pornim a doua oara. Pe Windows SO_REUSEADDR ar permite al doilea bind pe
rem acelasi port, deci verificarea asta este singura protectie.
netstat -an | findstr ":8756" | findstr /C:"LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo Serviciul de tiparire ruleaza deja pe portul 8756 - nu pornesc o a doua instanta.
    exit /b 0
)

echo Pornire serviciu tiparire cu: %PYEXE%
%PYEXE% server.py

echo.
echo Serviciul s-a oprit.
pause
