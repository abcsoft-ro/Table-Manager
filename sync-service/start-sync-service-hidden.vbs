' Porneste serviciul de sincronizare in fundal, fara fereastra de consola.
' Se poate pune un shortcut in shell:startup pentru pornire automata.
Set sh = CreateObject("WScript.Shell")
base = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
sh.CurrentDirectory = base
sh.Run """" & base & "\start-sync-service.bat""", 0, False
