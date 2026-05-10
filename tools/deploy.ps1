<#
.SYNOPSIS
    Deploys httproot/ to the test server over SCP using OpenSSH.

.DESCRIPTION
    Reads .deploy.env at the project root for DEPLOY_HOST_ALIAS and DEPLOY_DOC_ROOT.
    Uses ~/.ssh/config for host, user, and key resolution.
    Pre-creates the remote directory tree, then scp -r every top-level entry
    in httproot/ into the document root. Existing runtime files (status.json,
    log/logs/*.log) on the server are not touched because they are not in the
    source tree.

.PARAMETER DryRun
    Skip scp; print what would be uploaded.

.PARAMETER Verbose
    Pass -v through to ssh and scp.

.EXAMPLE
    .\tools\deploy.ps1
    .\tools\deploy.ps1 -DryRun
#>

[CmdletBinding()]
param(
    [switch] $DryRun,
    # Permits the legacy `dev-1234…` placeholder secret. Use only for LAN-test
    # deployments. The script refuses to ship that placeholder by default.
    [switch] $AllowDevSecret
)

$ErrorActionPreference = 'Stop'

# ---- locate project root, regardless of cwd ----
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$Httproot    = Join-Path $ProjectRoot 'httproot'
$EnvFile     = Join-Path $ProjectRoot '.deploy.env'

if (-not (Test-Path $Httproot)) {
    throw "httproot/ not found at $Httproot"
}
if (-not (Test-Path $EnvFile)) {
    throw "Missing $EnvFile. Copy .deploy.env.example to .deploy.env and fill it in."
}

# Pre-flight: real config.php must exist and the placeholder secret must be
# replaced. Refusing to deploy here is much friendlier than discovering after
# the fact that every controller push is silently dropped on the live server.
$ConfigFile = Join-Path $Httproot 'config.php'
if (-not (Test-Path $ConfigFile)) {
    throw @"
Missing $ConfigFile.
Run once to start: Copy-Item httproot\config_template.php httproot\config.php
Then edit it and replace GH_SECRET_TOKEN with a random value.
See tools\README.md "One-time setup" for details.
"@
}
$cfg_text = Get-Content $ConfigFile -Raw
if ($cfg_text -match "REPLACE_ME_BEFORE_DEPLOY") {
    throw "httproot/config.php still contains the template placeholder REPLACE_ME_BEFORE_DEPLOY. Edit GH_SECRET_TOKEN first."
}
if ($cfg_text -match "dev-1234567890abcdef-please-rotate-in-prod") {
    if (-not $AllowDevSecret) {
        throw @"
httproot/config.php still uses the legacy `dev-1234…` placeholder secret.
This is unsafe for any non-LAN deployment.

  - To rotate: edit httproot\config.php and set GH_SECRET_TOKEN to a fresh
    random value (>= 16 chars). Update MOCK_SECRET in .deploy.env to match.
  - To deploy anyway (LAN test only), re-run with  -AllowDevSecret
"@
    }
    Write-Host "WARNING: deploying with the legacy `dev-1234…` placeholder secret (LAN-test override active)." -ForegroundColor Yellow
}

# ---- parse .deploy.env (KEY=VALUE, # comments) ----
$cfg = @{}
foreach ($line in Get-Content $EnvFile) {
    $trim = $line.Trim()
    if ($trim -eq '' -or $trim.StartsWith('#')) { continue }
    $kv = $trim -split '=', 2
    if ($kv.Count -eq 2) { $cfg[$kv[0].Trim()] = $kv[1].Trim() }
}

$HostAlias = $cfg['DEPLOY_HOST_ALIAS']
$DocRoot   = $cfg['DEPLOY_DOC_ROOT']

if (-not $HostAlias) { throw "DEPLOY_HOST_ALIAS not set in .deploy.env" }
if (-not $DocRoot)   { throw "DEPLOY_DOC_ROOT not set in .deploy.env" }

Write-Host "Target : $HostAlias`:$DocRoot"
Write-Host "Source : $Httproot"
Write-Host ""

# ---- enumerate items to upload ----
$items = Get-ChildItem -Path $Httproot -Force | Sort-Object Name
if ($items.Count -eq 0) { throw "httproot/ is empty" }

Write-Host "Items to upload:"
foreach ($i in $items) {
    $kind = if ($i.PSIsContainer) { 'dir ' } else { 'file' }
    Write-Host ("  [{0}] {1}" -f $kind, $i.Name)
}
Write-Host ""

if ($DryRun) {
    Write-Host "DryRun: skipping scp." -ForegroundColor Yellow
    return
}

# ---- ensure remote dir tree exists, with web-server-writable perms (idempotent) ----
# 2770 = group rwx + setgid, so files Apache creates inherit the www-data group.
$prepCmd = @(
    "mkdir -p '$DocRoot' '$DocRoot/assets' '$DocRoot/data' '$DocRoot/log/logs'",
    "chmod 2770 '$DocRoot/data' '$DocRoot/log' '$DocRoot/log/logs'"
) -join ' && '
Write-Host "Ensuring remote directories + perms..." -NoNewline
& ssh $HostAlias $prepCmd
if ($LASTEXITCODE -ne 0) { throw "ssh prep failed (exit $LASTEXITCODE)" }
Write-Host " ok"

# ---- scp each top-level entry ----
$paths = $items | ForEach-Object { $_.FullName }

Write-Host "Uploading..."
# Note: deliberately NOT passing -p. scp -p preserves source-side mode bits,
# which translate poorly from Windows (the assets/ dir came across as 0700,
# making Apache return 403 for everything inside it). Without -p the server's
# default umask applies, then we explicitly normalize below.
& scp -r @paths "${HostAlias}:${DocRoot}/"
if ($LASTEXITCODE -ne 0) { throw "scp failed (exit $LASTEXITCODE)" }

# Normalize file modes after scp so Apache (www-data) can read everything,
# and re-restrict the dirs that need group-only write access for status/log writes.
# `-user $USER` scopes the chmod to files owned by the deploy user, skipping
# runtime files Apache itself wrote (status.json, *.log) which we don't own
# and don't need to touch — they're already 0644 from Apache's umask.
$normalizeCmd = @(
    "find '$DocRoot' -type d -user `$USER -exec chmod 755 {} +",
    "find '$DocRoot' -type f -user `$USER -exec chmod 644 {} +",
    "chmod 2770 '$DocRoot/data' '$DocRoot/log' '$DocRoot/log/logs'"
) -join ' && '
Write-Host "Normalizing permissions..." -NoNewline
& ssh $HostAlias $normalizeCmd
if ($LASTEXITCODE -ne 0) { throw "ssh chmod normalize failed (exit $LASTEXITCODE)" }
Write-Host " ok"

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "  Visit http://$HostAlias/ (or the server's hostname / IP) to verify."
Write-Host "  Note: runtime state files on the server were not touched:"
Write-Host "        $DocRoot/data/status.json"
Write-Host "        $DocRoot/log/logs/*.log"
