@echo off
REM Porneste serviciul de sincronizare catre server (temp_Send_Sql -> tblConectare).
REM Se poate pune un shortcut in shell:startup pentru pornire automata, DOAR in
REM contul de utilizator al statiei (NICIODATA in contul de service): pe statiile
REM cu doua conturi ar porni doua instante ale serviciului.
setlocal
cd /d "%~dp0"

if not exist "logs" mkdir "logs"

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
rem alta sesiune / de alt cont, sau din Setari -> Servicii), NU pornim a doua
rem oara. Pe Windows SO_REUSEADDR ar permite al doilea bind pe acelasi port,
rem deci verificarea asta este singura protectie.
netstat -an | findstr ":8757" | findstr /C:"LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo Serviciul de sincronizare ruleaza deja pe portul 8757 - nu pornesc o a doua instanta.
    exit /b 0
)

echo Pornire serviciu sincronizare cu: %PYEXE%
%PYEXE% server.py

echo.
echo Serviciul s-a oprit.
pause
