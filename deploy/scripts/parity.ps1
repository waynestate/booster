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
$FallbackTag = 'squire/php-parity-base:8.4'

function Exit-Parity {
    param([string]$Message)
    Write-Error "parity: $Message"
    exit 1
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

if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
    Exit-Parity 'docker is not installed or not on PATH'
}
& docker compose version *> $null
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
            Write-Output "parity: building the fallback base image $FallbackTag (an approximation of Citadel's image)"
            & docker build --build-arg 'CITADEL_SOURCE_UID=1000' --build-arg 'CITADEL_SOURCE_GID=1000' `
                -f .squire/base-fallback.Dockerfile -t $FallbackTag .squire
            if ($LASTEXITCODE -ne 0) {
                Exit-Parity 'the fallback base image failed to build'
            }
            $env:SQUIRE_BASE_IMAGE = $FallbackTag
        } else {
            $base = Get-EnvValue -Name 'SQUIRE_BASE_IMAGE' -Default 'citadel/php-apache:8.4r5'
            & docker image inspect $base *> $null
            if ($LASTEXITCODE -ne 0) {
                Exit-Parity "base image '$base' is not on this machine. Build it from the Citadel repository (php citadel build_images), pull it from your registry, or re-run with -Fallback."
            }
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
            $digest = & docker image inspect --format '{{index .RepoDigests 0}}' $image 2>$null
            if ([string]::IsNullOrEmpty($digest)) {
                $digest = "not pulled yet (run 'up' first)"
            }
            Write-Output "$image  ->  $digest"
        }
    }
}
