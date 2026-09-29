' Porneste TableManager in mod kiosc, fara fereastra DOS (ascunsa).
' Lanseaza importul produselor (ascuns) si apoi launcher-ul .bat (ascuns).
' Se poate pune un shortcut la acest fisier in shell:startup.

Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
base = fso.GetParentFolderName(WScript.ScriptFullName)
sh.CurrentDirectory = base

py = base & "\.venv\Scripts\python.exe"
If Not fso.FileExists(py) Then
    MsgBox "Nu exista mediul virtual Python .venv." & vbCrLf & _
           "Rulati mai intai setup-venv.bat din radacina proiectului.", 16, "TableManager"
    WScript.Quit 1
End If

pf = sh.ExpandEnvironmentStrings("%ProgramFiles%")
pf86 = sh.ExpandEnvironmentStrings("%ProgramFiles(x86)%")
If Not (fso.FileExists(pf & "\Google\Chrome\Application\chrome.exe") _
    Or fso.FileExists(pf86 & "\Google\Chrome\Application\chrome.exe") _
    Or fso.FileExists(pf86 & "\Microsoft\Edge\Application\msedge.exe") _
    Or fso.FileExists(pf & "\Microsoft\Edge\Application\msedge.exe")) Then
    MsgBox "Nu am gasit Google Chrome sau Microsoft Edge.", 16, "TableManager"
    WScript.Quit 1
End If

' Import produse/grupe de pe server (scriptul decide daca ruleaza: tblSet.Server).
importScript = base & "\import_server_grp_prod.py"
If fso.FileExists(importScript) Then
    sh.Run """" & py & """ """ & importScript & """ --if-notes=run", 0, False
End If

' Launcher-ul kiosc, fara import (il lansam mai sus, ascuns).
sh.Run """" & base & "\Pornire-POS-Kiosk.bat"" --no-import", 0, False
