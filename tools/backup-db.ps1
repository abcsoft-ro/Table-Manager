<#
.SYNOPSIS
    Backup al bazei de date TableManager (MSSQL) - full, verificat, cu retentie.

.DESCRIPTION
    Citeste credentialele din api/db.local.php, executa BACKUP DATABASE prin ADO.NET
    (System.Data.SqlClient, fara dependinte externe gen sqlcmd/SQLPS) si verifica
    backup-ul cu RESTORE VERIFYONLY. Sterge apoi backup-urile mai vechi de
    RetentionDays zile - citind lista din msdb si stergand PER FISIER cu
    xp_delete_file, ca sa functioneze si cand SQL Server ruleaza pe alta masina
    (fisierele sunt pe acea masina) si fara sa atinga backup-urile altor baze care
    scriu in acelasi folder. Scrie un log in logs/backup_db.log.

    Este destinat rularii din Task Scheduler (tools/backup-db-task.xml), ca SYSTEM,
    zilnic la 03:00, dar poate fi rulat si manual.

.PARAMETER BackupDir
    Folderul unde se scriu fisierele .bak (implicit C:\ABCSoft\Data\backup).

.PARAMETER RetentionDays
    Numarul de zile de pastrare a backup-urilor (implicit 14).

.PARAMETER DbLocalPath
    Calea catre api/db.local.php (implicit ..\api\db.local.php fata de script).

.PARAMETER LogFile
    Calea catre fisierul de log (implicit ..\logs\backup_db.log).

.PARAMETER DryRun
    Nu executa backup-ul; afiseaza doar configuratia si calea generata.

.PARAMETER NoVerify
    Nu executa RESTORE VERIFYONLY dupa backup.

.PARAMETER NoPrune
    Nu sterge backup-urile expirate.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\backup-db.ps1
    powershell -ExecutionPolicy Bypass -File tools\backup-db.ps1 -DryRun
#>
[CmdletBinding()]
param(
    [string]$BackupDir = 'C:\ABCSoft\Data\backup',
    [int]$RetentionDays = 14,
    [string]$DbLocalPath,
    [string]$LogFile,
    [switch]$DryRun,
    [switch]$NoVerify,
    [switch]$NoPrune
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Data

$ScriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $DbLocalPath) { $DbLocalPath = Join-Path $ScriptDir '..\api\db.local.php' }
if (-not $LogFile)     { $LogFile     = Join-Path $ScriptDir '..\logs\backup_db.log' }
$DbLocalPath = [System.IO.Path]::GetFullPath($DbLocalPath)
$LogFile     = [System.IO.Path]::GetFullPath($LogFile)

function Write-Log {
    param([string]$Message)
    $line = '[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Host $line
    try {
        $dir = Split-Path -Parent $LogFile
        if ($dir -and -not (Test-Path -LiteralPath $dir)) {
            New-Item -ItemType Directory -Force -Path $dir | Out-Null
        }
        Add-Content -LiteralPath $LogFile -Value $line -Encoding UTF8
    } catch {
        # log-ul nu trebuie sa opreasca backup-ul
    }
}

function Read-DbLocal {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Lipseste $Path (creati-l din api/db.local.example.php)."
    }
    $text = Get-Content -Raw -LiteralPath $Path
    $cfg = @{}
    foreach ($key in @('Server', 'Database', 'UID', 'PWD')) {
        $m = [regex]::Match($text, "'" + $key + "'\s*=>\s*'([^']*)'")
        if ($m.Success) { $cfg[$key] = $m.Groups[1].Value }
    }
    foreach ($key in @('Server', 'Database', 'UID', 'PWD')) {
        if (-not $cfg.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($cfg[$key])) {
            throw "Valoarea '$key' lipseste din $Path."
        }
    }
    return $cfg
}

function Test-LocalServer {
    param([string]$Server)
    $s = $Server.Trim().ToLowerInvariant()
    # NU folosi $host: este o variabila automata read-only in PowerShell, iar
    # atribuirea arunca "Cannot overwrite variable Host because it is read-only
    # or constant." (cu Set-StrictMode + ErrorActionPreference='Stop' scriptul
    # murea imediat - vezi tests/REPORT.md B-01).
    $hostName = $env:COMPUTERNAME.ToLowerInvariant()
    if ($s -in @('.', '(local)', 'localhost')) { return $true }
    if ($s -like 'localhost\*' -or $s -like '.\*' -or $s -like '(local)\*') { return $true }
    if ($s -eq $hostName -or $s -like ($hostName + '\*')) { return $true }
    return $false
}

function Ensure-BackupDir {
    param([string]$Dir, [string]$Server)
    # Cand SQL Server ruleaza pe alta masina, folderul de backup este pe ACEA
    # masina: nu are sens sa-l cream local, nici sa dam drepturi unui serviciu
    # local. Verificam intai, ca sa nu lasam un folder inutil pe statia asta.
    if (-not (Test-LocalServer $Server)) {
        Write-Log "AVERTISMENT: serverul '$Server' pare remote; folderul '$Dir' trebuie sa existe si sa fie scriibil pe masina SQL (nu il creez local)."
        return
    }
    if (-not (Test-Path -LiteralPath $Dir)) {
        New-Item -ItemType Directory -Force -Path $Dir | Out-Null
        Write-Log "Folder creat: $Dir"
    }
    $instance = 'MSSQLSERVER'
    if ($Server -match '\\') { $instance = 'MSSQL$' + (($Server -split '\\')[-1]) }
    try {
        $svc = Get-CimInstance -ClassName Win32_Service -Filter ("Name='{0}'" -f $instance) -ErrorAction Stop
        if ($svc -and $svc.StartName) {
            & icacls $Dir /grant ("{0}:(OI)(CI)M" -f $svc.StartName) | Out-Null
            Write-Log "Drepturi acordate pentru $($svc.StartName) pe $Dir"
        }
    } catch {
        Write-Log "AVERTISMENT: nu am putut detecta serviciul SQL '$instance' ($($_.Exception.Message))."
    }
}

function Invoke-Sql {
    param([string]$ConnString, [string]$Query, [int]$TimeoutSec = 0)
    $conn = New-Object System.Data.SqlClient.SqlConnection($ConnString)
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $Query
        $cmd.CommandTimeout = $TimeoutSec
        $cmd.ExecuteNonQuery() | Out-Null
    } finally {
        $conn.Dispose()
    }
}

function Invoke-SqlScalar {
    param([string]$ConnString, [string]$Query, [int]$TimeoutSec = 0)
    $conn = New-Object System.Data.SqlClient.SqlConnection($ConnString)
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $Query
        $cmd.CommandTimeout = $TimeoutSec
        return $cmd.ExecuteScalar()
    } finally {
        $conn.Dispose()
    }
}

function Invoke-SqlReader {
    param([string]$ConnString, [string]$Query, [int]$TimeoutSec = 0)
    $conn = New-Object System.Data.SqlClient.SqlConnection($ConnString)
    try {
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $Query
        $cmd.CommandTimeout = $TimeoutSec
        $rd = $cmd.ExecuteReader()
        $rows = @()
        while ($rd.Read()) {
            $row = @{}
            for ($i = 0; $i -lt $rd.FieldCount; $i++) { $row[$rd.GetName($i)] = $rd.GetValue($i) }
            $rows += (New-Object psobject -Property $row)
        }
        return $rows
    } finally {
        $conn.Dispose()
    }
}

$started = Get-Date
$stamp   = $started.ToString('yyyyMMdd_HHmmss')
$ok      = $false
$bakPath = $null

try {
    $cfg      = Read-DbLocal $DbLocalPath
    $server   = $cfg['Server']
    $database = $cfg['Database']
    $bakPath  = Join-Path $BackupDir ("{0}_{1}.bak" -f $database, $stamp)

    if ($DryRun) {
        Write-Host "Server    : $server"
        Write-Host "Database  : $database"
        Write-Host "Backup    : $bakPath"
        Write-Host "Retentie  : $RetentionDays zile"
        Write-Host "Verificare: $(-not $NoVerify)"
        Write-Host "Sterge vechi: $(-not $NoPrune)"
        Write-Log "DRYRUN server=$server db=$database backup=$bakPath retentie=${RetentionDays}z"
        exit 0
    }

    Ensure-BackupDir $BackupDir $server

    $cs  = "Server={0};Database=master;User ID={1};Password={2};Encrypt=False;TrustServerCertificate=True;Connect Timeout=15" -f $server, $cfg['UID'], $cfg['PWD']
    $sql = $bakPath.Replace("'", "''")

    Invoke-Sql -ConnString $cs -Query ("BACKUP DATABASE [{0}] TO DISK = N'{1}' WITH INIT, CHECKSUM, STATS = 10" -f $database, $sql)
    Write-Log "Backup scris: $bakPath"

    if (-not $NoVerify) {
        Invoke-Sql -ConnString $cs -Query ("RESTORE VERIFYONLY FROM DISK = N'{0}' WITH CHECKSUM" -f $sql)
        Write-Log "Verificare OK: $bakPath"
    }

    $dbQ = $database.Replace("'", "''")
    $ok = $true

    # Marimea se citeste din msdb, nu de pe disc: fisierul .bak este pe masina
    # SQL, care poate fi alta masina decat cea care ruleaza scriptul (inainte se
    # folosea Get-Item pe cale locala si scriptul murea cu "Cannot find path").
    $sizeTxt = '?'
    try {
        $sizeMb = Invoke-SqlScalar -ConnString $cs -Query ("SELECT TOP 1 CAST(backup_size AS float) / 1048576 FROM msdb.dbo.backupset WHERE database_name = '{0}' AND type = 'D' ORDER BY backup_finish_date DESC" -f $dbQ)
        if ($null -ne $sizeMb) { $sizeTxt = ([math]::Round([double]$sizeMb, 2)).ToString() + 'MB' }
    } catch {
        Write-Log "AVERTISMENT: nu am putut citi marimea din msdb ($($_.Exception.Message))."
    }
    $dur = [int]((Get-Date) - $started).TotalSeconds
    Write-Log ("rezultat=ok fisier={0} marime={1} durata={2}s" -f (Split-Path -Leaf $bakPath), $sizeTxt, $dur)

    if (-not $NoPrune) {
        # Curatarea se face pe masina SQL, din msdb, si sterge PER FISIER.
        # NU se foloseste forma pe folder (xp_delete_file 0, folder, 'bak', data):
        # in acelasi folder scriu si alte baze (ex. DataRON_CIORB_NT, DataRON_new),
        # iar un filtru pe folder le-ar sterge si lor backup-urile.
        try {
            $expired = @(Invoke-SqlReader -ConnString $cs -Query ("SELECT DISTINCT mf.physical_device_name AS Cale
                FROM msdb.dbo.backupset bs
                JOIN msdb.dbo.backupmediafamily mf ON mf.media_set_id = bs.media_set_id
                WHERE bs.database_name = '{0}' AND bs.type = 'D'
                  AND bs.backup_finish_date < DATEADD(day, -{1}, GETDATE())" -f $dbQ, $RetentionDays))
            if ($expired.Count -eq 0) {
                Write-Log "Curatare: niciun backup mai vechi de $RetentionDays zile."
            }
            foreach ($e in $expired) {
                $cale = [string]$e.Cale
                if ([string]::IsNullOrWhiteSpace($cale)) { continue }
                try {
                    Invoke-Sql -ConnString $cs -Query ("EXEC master.dbo.xp_delete_file 0, N'{0}'" -f $cale.Replace("'", "''"))
                    Write-Log "Sters backup expirat: $(Split-Path -Leaf $cale)"
                } catch {
                    $mesaj = $_.Exception.Message
                    if ($mesaj -match 'cannot find the file') {
                        # Fisierul nu mai exista (sters manual, sau istoric msdb vechi
                        # ramas fara fisier): retentia si-a atins scopul, nu e eroare.
                        Write-Log "Curatare: fisier deja inexistent (istoric msdb): $cale"
                    } else {
                        Write-Log "AVERTISMENT: nu am putut sterge '$cale' ($mesaj)."
                    }
                }
            }
        } catch {
            Write-Log "AVERTISMENT la curatare: $($_.Exception.Message)"
        }
    }
} catch {
    Write-Log "rezultat=eroare detaliu=""$($_.Exception.Message)"""
}

if ($ok) { exit 0 } else { exit 1 }
