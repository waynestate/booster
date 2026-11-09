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
         with the Composer Booster itself passes (2.2 below PHP 7.2),
      2. runs `php -m` in the image,
      3. fails naming any of the 13 extensions missing from the output,
      4. fails when Composer does not run in the image,
      5. on an archived Debian release other than jessie, runs `apt-get update` in
         the image and fails when apt cannot verify the archive's signatures
         (jessie's archive is signed only by expired keys, so it is skipped),
      6. prints `OK <v>` when all of that holds.

    Usage: .\scripts\check-target-image.ps1 [-PhpVersion 8.3 8.4 8.5]
    (the default is 8.3 8.4 8.5)
    The old images: .\scripts\check-target-image.ps1 -PhpVersion '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0'
    Quote each version: PowerShell reads a bare 8.0 as the number 8, which would build php:8-cli.

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

# Runs in the image: archived releases other than jessie must verify their
# archive. apt prints a GPG error or "not signed" when it cannot.
$AptProbe = @'
. /etc/os-release
codename="${VERSION_CODENAME:-$(printf "%s" "${VERSION:-}" | sed -n "s/.*(\([a-z]*\)).*/\1/p")}"
case "$codename" in
    stretch|buster|bullseye) out="$(apt-get update 2>&1)" || { printf "%s\n" "$out"; exit 1; }
        if printf "%s\n" "$out" | grep -E "GPG error|not signed|NO_PUBKEY|EXPKEYSIG" >&2; then exit 1; fi
        rm -rf /var/lib/apt/lists/*; echo "apt verified $codename" ;;
    jessie) echo "apt not checked on jessie (its archive keys have all expired)" ;;
    *) echo "apt untouched on $codename" ;;
esac
'@ -replace "`r", ''

foreach ($V in $PhpVersion) {
    if ($V -notmatch '^\d+\.\d+$') {
        Write-Error "FAIL $V : not a major.minor PHP version (quote it: '8.0', not 8.0)"
        exit 1
    }
    Write-Host "==> Building booster-target-check:$V ..."
    # What Booster passes (TargetImageRunner): Composer 2.3 and later need PHP
    # 7.2.5, so builds below 7.2 get the 2.2 line; the rest keep the recipe default.
    $BuildArgs = @('build', '--build-arg', "PHP_VERSION=$V")
    if ($V -match '^(5\.\d+|7\.0|7\.1)$') {
        $BuildArgs += @('--build-arg', 'COMPOSER_VERSION=2.2')
    }
    Invoke-Docker -DockerArgs ($BuildArgs + @('-t', "booster-target-check:$V", 'docker/'))

    $Modules = @(Invoke-Docker -Capture -DockerArgs @('run', '--rm', "booster-target-check:$V", 'php', '-m'))

    $Listed = @($Modules | Where-Object { $_ -ne '' })
    $Missing = @($Expected | Where-Object { $Listed -notcontains $_ })

    if ($Missing.Count -gt 0) {
        Write-Error "FAIL $V : missing extensions: $($Missing -join ' ')"
        exit 1
    }

    & docker run --rm "booster-target-check:$V" composer --version
    if ($LASTEXITCODE -ne 0) {
        Write-Error "FAIL $V : composer does not run in the image"
        exit 1
    }

    & docker run --rm "booster-target-check:$V" sh -c $AptProbe
    if ($LASTEXITCODE -ne 0) {
        Write-Error "FAIL $V : apt cannot verify the Debian archive in the image"
        exit 1
    }

    Write-Host "OK $V"
}
