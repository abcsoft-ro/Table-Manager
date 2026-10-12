<#
.SYNOPSIS
    Backup al bazei de date TableManager (MSSQL) - full, verificat, cu retentie.

.DESCRIPTION
    Citeste credentialele din api/db.local.php, executa BACKUP DATABASE prin ADO.NET
    (System.Data.SqlClient, fara dependinte externe gen sqlcmd/SQLPS), verifica
    backup-ul cu RESTORE VERIFYONLY si sterge fisierele mai vechi de RetentionDays
    zile. Scrie un log in logs/backup_db.log.

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
    $host = $env:COMPUTERNAME.ToLowerInvariant()
    if ($s -in @('.', '(local)', 'localhost')) { return $true }
    if ($s -like 'localhost\*' -or $s -like '.\*' -or $s -like '(local)\*') { return $true }
    if ($s -eq $host -or $s -like ($host + '\*')) { return $true }
    return $false
}

function Ensure-BackupDir {
    param([string]$Dir, [string]$Server)
    if (-not (Test-Path -LiteralPath $Dir)) {
        New-Item -ItemType Directory -Force -Path $Dir | Out-Null
        Write-Log "Folder creat: $Dir"
    }
    if (-not (Test-LocalServer $Server)) {
        Write-Log "AVERTISMENT: serverul '$Server' pare remote; folderul '$Dir' trebuie sa existe si sa fie scriibil pe masina SQL."
        return
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

    $ok = $true
    $sizeMb = [math]::Round((Get-Item -LiteralPath $bakPath).Length / 1MB, 2)
    $dur    = [int]((Get-Date) - $started).TotalSeconds
    Write-Log ("rezultat=ok fisier={0} marime={1}MB durata={2}s" -f (Split-Path -Leaf $bakPath), $sizeMb, $dur)

    if (-not $NoPrune) {
        try {
            $cutoff = (Get-Date).AddDays(-1 * $RetentionDays)
            $expired = Get-ChildItem -LiteralPath $BackupDir -File |
                Where-Object { $_.Name -like ($database + '_*.bak') -and $_.LastWriteTime -lt $cutoff }
            foreach ($f in $expired) {
                Remove-Item -LiteralPath $f.FullName -Force
                Write-Log "Sters backup expirat: $($f.Name)"
            }
        } catch {
            Write-Log "AVERTISMENT la curatare: $($_.Exception.Message)"
        }
    }
} catch {
    Write-Log "rezultat=eroare detaliu=""$($_.Exception.Message)"""
}

if ($ok) { exit 0 } else { exit 1 }
