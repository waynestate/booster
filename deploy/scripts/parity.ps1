<#
.SYNOPSIS
    Run the app locally on the Citadel PHP image, behind a proxy, with MySQL 5.6 (Windows).

.DESCRIPTION
    The twin of scripts/parity.sh; both do the same thing. Needs Docker Desktop (WSL 2 backend) with Compose v2.
    No bash, no WSL shell required. Works in Windows PowerShell 5.1 and PowerShell 7.

.PARAMETER Command
    up, down, reset, logs, shell, status or pin. Default: up.

.PARAMETER Fallback
    With 'up': build the fallback base image (an approximation of Citadel's image) instead of using the real one.

.PARAMETER Service
    With 'logs' or 'shell': the compose service (default: all for logs, app for shell).

.EXAMPLE
    scripts\parity.ps1 up -Fallback
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('up', 'down', 'reset', 'logs', 'shell', 'status', 'pin')]
    [string]$Command = 'up',

    [switch]$Fallback,

    [Parameter(Position = 1)]
    [string]$Service = ''
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Join-Path -Path $PSScriptRoot -ChildPath '..')

$ComposeFile = 'docker-compose.squire.yml'
$EnvFile = '.env.squire'
$FallbackTag = 'squire/php-parity-base:8.4r5'

function Exit-Parity {
    param([string]$Message)
    # Not Write-Error: under 'Stop' it throws, so the message came wrapped in a stack of error-record lines and the
    # `exit 1` below never ran. One plain line on stderr, then exit 1, like parity.sh.
    [Console]::Error.WriteLine("parity: $Message")
    exit 1
}

function Invoke-NativeQuiet {
    # Runs a native program with its stderr dropped and returns its stdout; the exit code is in $LASTEXITCODE.
    # Windows PowerShell 5.1 turns redirected stderr into a terminating error while $ErrorActionPreference is
    # 'Stop', so `docker image inspect <missing> 2>$null` threw before the friendly message below could show.
    # The preference is 'Continue' inside this function only, which makes 5.1 behave like PowerShell 7.
    param([string]$Program, [string[]]$Arguments)
    $ErrorActionPreference = 'Continue'
    & $Program @Arguments 2>$null
}

function Get-EnvValue {
    param([string]$Name, [string]$Default)
    $line = Select-String -LiteralPath $EnvFile -Pattern "^$Name=" | Select-Object -Last 1
    if ($null -eq $line) {
        return $Default
    }
    return $line.Line.Substring($Name.Length + 1)
}

function Invoke-Compose {
    param([string[]]$ComposeArgs)
    & docker compose --env-file $EnvFile -f $ComposeFile @ComposeArgs
    if ($LASTEXITCODE -ne 0) {
        Exit-Parity "docker compose $($ComposeArgs -join ' ') failed with exit code $LASTEXITCODE"
    }
}

function Get-SourceUidGid {
    # The container's `citadel` user must match the host owner of the bind-mounted source, whatever number that is.
    # A mismatch means the release cannot write vendor/ into the source and the app container exits 1 on start.
    # On Windows ownership is emulated and Docker Desktop maps bind mounts to 1000:1000 by default, so the safe
    # default is right there; on Linux and macOS the current user's ids are the ones that matter.
    if ($env:OS -eq 'Windows_NT') {
        return @('1000', '1000')
    }
    $uid = (Invoke-NativeQuiet -Program 'id' -Arguments @('-u')) -join ''
    $gid = (Invoke-NativeQuiet -Program 'id' -Arguments @('-g')) -join ''
    if ($uid -notmatch '^\d+$' -or [long]$uid -lt 1 -or [long]$uid -gt 65534) { $uid = '1000' }
    if ($gid -notmatch '^\d+$' -or [long]$gid -lt 1 -or [long]$gid -gt 65534) { $gid = '1000' }
    return @($uid, $gid)
}

if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
    Exit-Parity 'docker is not installed or not on PATH'
}
$null = Invoke-NativeQuiet -Program 'docker' -Arguments @('compose', 'version')
if ($LASTEXITCODE -ne 0) {
    Exit-Parity "'docker compose' (Compose v2) is required"
}
if (-not (Test-Path -LiteralPath $ComposeFile)) {
    Exit-Parity "$ComposeFile not found; run 'vendor\bin\squire init' first"
}

if (-not (Test-Path -LiteralPath $EnvFile)) {
    if (-not (Test-Path -LiteralPath '.env.squire.example')) {
        Exit-Parity "$EnvFile and .env.squire.example are both missing"
    }
    Copy-Item -LiteralPath '.env.squire.example' -Destination $EnvFile
    Exit-Parity "created $EnvFile from the example; edit the passwords, then run this again"
}

switch ($Command) {
    'up' {
        if ($Fallback) {
            $sourceIds = Get-SourceUidGid
            Write-Output "parity: building the fallback base image $FallbackTag (an approximation of Citadel's image, citadel user $($sourceIds[0]):$($sourceIds[1]))"
            & docker build --build-arg "CITADEL_SOURCE_UID=$($sourceIds[0])" --build-arg "CITADEL_SOURCE_GID=$($sourceIds[1])" `
                -f .squire/base-fallback.Dockerfile -t $FallbackTag .squire
            if ($LASTEXITCODE -ne 0) {
                Exit-Parity 'the fallback base image failed to build'
            }
            $env:SQUIRE_BASE_IMAGE = $FallbackTag
        } else {
            $base = Get-EnvValue -Name 'SQUIRE_BASE_IMAGE' -Default 'citadel/php-apache:8.4r5'
            $null = Invoke-NativeQuiet -Program 'docker' -Arguments @('image', 'inspect', $base)
            if ($LASTEXITCODE -ne 0) {
                Exit-Parity "base image '$base' is not on this machine. Build it from the Citadel repository (php citadel build_images), pull it from your registry, or re-run with -Fallback."
            }
        }
        # Every app left on the default name is one compose project: one database, one logs volume, and in it the
        # record of which deployments/ files already ran. A second app then finds its release "already run".
        $project = $env:SQUIRE_PROJECT
        if ([string]::IsNullOrEmpty($project)) {
            $project = Get-EnvValue -Name 'SQUIRE_PROJECT' -Default 'squire-app'
        }
        if ([string]::IsNullOrEmpty($project) -or $project -eq 'squire-app') {
            Write-Warning "SQUIRE_PROJECT is the default 'squire-app'. Apps on one name share a database and a release record, so a second app's release never runs; give this app its own name in $EnvFile."
        }
        if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') {
            Write-Warning 'ARM64 detected. MySQL 5.6 and the real Citadel images are amd64, so Docker emulates them; builds can take 30 minutes or more.'
        }
        Invoke-Compose -ComposeArgs @('up', '--build', '-d', '--wait')
        $port = Get-EnvValue -Name 'SQUIRE_HTTP_PORT' -Default '8080'
        Write-Output "parity: up. Open http://127.0.0.1:$port"
    }
    'down' { Invoke-Compose -ComposeArgs @('down') }
    'reset' { Invoke-Compose -ComposeArgs @('down', '--volumes') }
    'status' { Invoke-Compose -ComposeArgs @('ps') }
    'logs' {
        $logArgs = @('logs', '--follow')
        if ($Service -ne '') {
            $logArgs += $Service
        }
        Invoke-Compose -ComposeArgs $logArgs
    }
    'shell' {
        $target = 'app'
        if ($Service -ne '') {
            $target = $Service
        }
        Invoke-Compose -ComposeArgs @('exec', $target, 'bash')
    }
    'pin' {
        $images = & docker compose --env-file $EnvFile -f $ComposeFile config --images | Sort-Object -Unique
        foreach ($image in $images) {
            if ($image -like 'squire-local/*') {
                continue
            }
            $digest = (Invoke-NativeQuiet -Program 'docker' -Arguments @('image', 'inspect', '--format', '{{index .RepoDigests 0}}', $image)) -join ''
            if ([string]::IsNullOrEmpty($digest)) {
                $digest = "not pulled yet (run 'up' first)"
            }
            Write-Output "$image  ->  $digest"
        }
    }
}
