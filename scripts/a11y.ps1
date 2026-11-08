# Site accessibility gate: Preflight's a11y checker (vendored in
# tools/a11y/check.mjs from Preflight 0.3.1, pinned by tests/A11yCheckerPinTest.php),
# run in Docker so no host browser is needed. The same image, tags, color
# schemes and widths as the a11y-site CI job, so a page that passes here
# passes CI. Windows PowerShell 5.1 and PowerShell 7; on Linux and macOS use
# scripts/a11y.sh.
#
# The repo is mounted read-only; the checker's packages live in a named
# volume (npm installs once and later runs reuse them) and npm's download
# cache in stack-npm-cache. The reports (gl-a11y-junit.xml, gl-a11y-report.json)
# land in build\a11y (gitignored). Exit 0 clean, 1 a violation or a page that
# did not load, 2 a tool or configuration error.
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

# The read-only repo mount cannot create the named volume's mount point.
New-Item -ItemType Directory -Force -Path 'tools/a11y/node_modules' | Out-Null

# The a11y component's image default, digest included (templates/a11y/template.yml
# of Preflight 0.3.1). Its tag sets the playwright npm version, so the package
# and the image's browsers always match.
$image = "mcr.microsoft.com/playwright:v1.65.0-noble@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d"
$pw = ([regex]::Match($image, 'playwright:v(\d+\.\d+\.\d+)')).Groups[1].Value
if (-not $pw) { throw "cannot read the Playwright version from the image tag $image" }

# The a11y-site CI job's inputs, as JSON for the checker.
$config = '{"siteDir": "site", "pages": ["**/*.html"], "tags": ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"], "colorSchemes": ["light", "dark"], "widths": [320, 1280], "axeCoreVersion": "4.14.0"}'

New-Item -ItemType Directory -Force -Path (Join-Path $PWD "build/a11y") | Out-Null

# The checker reads its inputs from a JSON file next to its reports.
[System.IO.File]::WriteAllText((Join-Path $PWD "build/a11y/config.json"), $config, (New-Object System.Text.UTF8Encoding($false)))

# Install the checker's packages when they are missing or when the installed
# playwright stops matching the image tag; the installed version is stamped in
# node_modules/.a11y-pw-stamp after each install. Exact versions from
# tools/a11y/package.json, no install scripts: the image already carries
# Chromium for this playwright release. The inner code carries no quotes and
# no backslashes: PowerShell 5.1 mangles both when it builds docker's command
# line, and the .sh twin passes this very text through.
$inner = 'if [ ! -f tools/a11y/node_modules/.a11y-pw-stamp ] || [ $(cat tools/a11y/node_modules/.a11y-pw-stamp) != $A11Y_PW ]; then (cd tools/a11y && npm ci --ignore-scripts --no-audit --no-fund && printf %s $A11Y_PW > node_modules/.a11y-pw-stamp); fi
A11Y_REPORT_DIR=/report node tools/a11y/check.mjs /report/config.json'

# dash fails on CRLF line endings (after fi), so strip the carriage returns
# the string picked up from this CRLF-checked-out file before the inner shell
# sees them.
$inner = $inner -replace "`r", ''

docker run --rm `
    -v "${PWD}:/work:ro" `
    -v "${PWD}/build/a11y:/report" `
    -v booster-a11y-node-modules:/work/tools/a11y/node_modules `
    -v stack-npm-cache:/npm-cache `
    -e npm_config_cache=/npm-cache `
    -e "A11Y_PW=$pw" `
    -w /work `
    $image `
    sh -c $inner

exit $LASTEXITCODE
