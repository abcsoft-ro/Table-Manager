' Creeaza shortcut-urile TableManager:
'   - in Startup (pornire automata): print-service si sync-service (lansatoare ascunse);
'   - pe Desktop: Pornire-POS-Kiosk.vbs, cu iconita img\abcicon.ico.
' Se poate rula direct sau din setup-venv.bat (cscript //B //Nologo).
Option Explicit

Dim fso, shell, base, startup, desktop, ico, sc

Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

' Radacina proiectului = folderul parinte al folderului acestui script (tools).
base = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName))
If Right(base, 1) <> "\" Then base = base & "\"

startup = shell.SpecialFolders("Startup")
desktop = shell.SpecialFolders("Desktop")
ico = base & "img\abcicon.ico"

If Not fso.FolderExists(startup) Then
    WScript.Echo "ATENTIE: nu exista folderul Startup: " & startup
End If
If Not fso.FolderExists(desktop) Then
    WScript.Echo "ATENTIE: nu exista folderul Desktop: " & desktop
End If

' --- Startup: serviciul de tiparire -----------------------------------------
Set sc = shell.CreateShortcut(startup & "\TableManager print-service.lnk")
sc.TargetPath = base & "print-service\start-print-service-hidden.vbs"
sc.WorkingDirectory = base & "print-service"
sc.Description = "TableManager - serviciul de tiparire"
If fso.FileExists(ico) Then sc.IconLocation = ico
sc.Save

' --- Startup: serviciul de sincronizare -------------------------------------
Set sc = shell.CreateShortcut(startup & "\TableManager sync-service.lnk")
sc.TargetPath = base & "sync-service\start-sync-service-hidden.vbs"
sc.WorkingDirectory = base & "sync-service"
sc.Description = "TableManager - serviciul de sincronizare"
If fso.FileExists(ico) Then sc.IconLocation = ico
sc.Save

' --- Desktop: lansatorul POS (kiosk) ----------------------------------------
Set sc = shell.CreateShortcut(desktop & "\TableManager POS.lnk")
sc.TargetPath = base & "Pornire-POS-Kiosk.vbs"
sc.WorkingDirectory = base
sc.Description = "TableManager POS"
If fso.FileExists(ico) Then sc.IconLocation = ico
sc.Save

WScript.Echo "Shortcut-uri create:"
WScript.Echo "  " & startup & "\TableManager print-service.lnk"
WScript.Echo "  " & startup & "\TableManager sync-service.lnk"
WScript.Echo "  " & desktop & "\TableManager POS.lnk"
