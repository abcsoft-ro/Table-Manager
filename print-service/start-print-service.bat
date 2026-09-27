@echo off
REM Porneste serviciul de tiparire TableManager.
REM Se poate pune un shortcut in shell:startup pentru pornire automata.
setlocal
cd /d "%~dp0"

if not exist "logs" mkdir "logs"
if not exist "preview" mkdir "preview"
if not exist "spool\fiscal" mkdir "spool\fiscal"

set "PYEXE="
set "CANDIDATE=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
if exist "%CANDIDATE%" set "PYEXE=%CANDIDATE%"
if not defined PYEXE if exist "C:\Users\Florian\AppData\Local\Programs\Python\Python312\python.exe" set "PYEXE=C:\Users\Florian\AppData\Local\Programs\Python\Python312\python.exe"
if not defined PYEXE (py -3 --version >nul 2>nul && set "PYEXE=py -3")
if not defined PYEXE set "PYEXE=python"

echo Pornire serviciu tiparire cu: %PYEXE%
%PYEXE% server.py

echo.
echo Serviciul s-a oprit.
pause
