#Requires -Version 7.0
<#
.SYNOPSIS
    Builds the gate image for each PHP version and checks its extensions.

.DESCRIPTION
    Builds the gate image for each PHP version and checks that it carries all
    of Citadel's extensions, so an Oracle app does not fail the gate at
    `composer install` with "requires ext-oci8".

    For each version the script:
      1. builds docker/Dockerfile with PHP_VERSION=<v>, tagged booster-target-check:<v>,
      2. runs `php -m` in the image,
      3. fails naming any of the 13 extensions missing from the output,
      4. prints `OK <v>` when every extension is present.

    Usage: .\scripts\check-target-image.ps1 [-PhpVersion 8.3 8.4 8.5]
    (the default is 8.3 8.4 8.5)

    The first build downloads Oracle Instant Client for oci8, so allow a few
    minutes per version. This is not part of composer ci on purpose: it needs
    Docker and real build time. The twin of check-target-image.sh for Windows;
    no bash involved. The gate must see what Citadel sees, extension for
    extension.
#>

[CmdletBinding()]
param(
    [string[]]$PhpVersion = @('8.3', '8.4', '8.5')
)

$ErrorActionPreference = 'Stop'

# The project root is the parent of the scripts/ directory this file lives in.
$Root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $Root

# Citadel's extension set, copied exactly. php -m prints them one per line,
# in this case (pdo_mysql, oci8, ...), so the match is exact.
$Expected = @('intl', 'ldap', 'soap', 'zip', 'gd', 'pdo_mysql', 'mysqli', 'bcmath', 'pcntl', 'exif', 'redis', 'imagick', 'oci8')

# Run a docker command with an argument array; fail the script on a non-zero exit.
# With -Capture the stdout lines come back as an array instead of being printed.
function Invoke-Docker {
    param(
        [Parameter(Mandatory = $true)][string[]]$DockerArgs,
        [switch]$Capture
    )

    if ($Capture) {
        $Output = & docker @DockerArgs
        if ($LASTEXITCODE -ne 0) {
            throw "docker $($DockerArgs -join ' ') failed with exit code $LASTEXITCODE"
        }
        return @($Output)
    }

    & docker @DockerArgs
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($DockerArgs -join ' ') failed with exit code $LASTEXITCODE"
    }
}

foreach ($V in $PhpVersion) {
    Write-Host "==> Building booster-target-check:$V ..."
    Invoke-Docker -DockerArgs @(
        'build', '--build-arg', "PHP_VERSION=$V", '-t', "booster-target-check:$V", 'docker/'
    )

    $Modules = @(Invoke-Docker -Capture -DockerArgs @('run', '--rm', "booster-target-check:$V", 'php', '-m'))

    $Listed = @($Modules | Where-Object { $_ -ne '' })
    $Missing = @($Expected | Where-Object { $Listed -notcontains $_ })

    if ($Missing.Count -gt 0) {
        Write-Error "FAIL $V : missing extensions: $($Missing -join ' ')"
        exit 1
    }

    Write-Host "OK $V"
}
