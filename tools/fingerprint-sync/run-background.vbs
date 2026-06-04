' Run fingerprint sidecar di background (no visible window).
' Cocok untuk Windows Task Scheduler dengan trigger "At system startup".

Set objShell = CreateObject("WScript.Shell")
objShell.CurrentDirectory = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
objShell.Run "python fingerprint_sync.py", 0, False
