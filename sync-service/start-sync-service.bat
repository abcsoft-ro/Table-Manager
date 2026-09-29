@echo off
REM Porneste serviciul de sincronizare catre server (temp_Send_Sql -> tblConectare).
REM Se poate pune un shortcut in shell:startup pentru pornire automata.
setlocal
cd /d "%~dp0"

if not exist "logs" mkdir "logs"

set "PYEXE="
set "CANDIDATE=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
if exist "%CANDIDATE%" set "PYEXE=%CANDIDATE%"
if not defined PYEXE if exist "%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe" set "PYEXE=%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe"
if not defined PYEXE (py -3 --version >nul 2>nul && set "PYEXE=py -3")
if not defined PYEXE set "PYEXE=python"

echo Pornire serviciu sincronizare cu: %PYEXE%
%PYEXE% server.py

echo.
Serviciul s-a oprit.
pause
