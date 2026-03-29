param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

$dateStamp = Get-Date -Format 'yyyyMMdd'
$releaseName = "hosp_kpis_release_$dateStamp.zip"
$releasePath = Join-Path $ProjectRoot $releaseName
$stagingRoot = Join-Path $env:TEMP ("hosp_kpis_release_" + [guid]::NewGuid().ToString('N'))
$stagingApp = Join-Path $stagingRoot 'hosp_kpis'

if (Test-Path $releasePath) {
    Remove-Item $releasePath -Force
}

New-Item -ItemType Directory -Path $stagingApp -Force | Out-Null

$excludeDirNames = @('.git', 'cache', 'logs', 'tmp', 'uploads', 'uploads_kpi_templates')
$excludeFileNames = @('database.local.php', 'install.lock')

Get-ChildItem -LiteralPath $ProjectRoot -Force | ForEach-Object {
    if ($_.Name -eq 'scripts') {
        Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $stagingApp $_.Name) -Recurse -Force
        return
    }
    if ($excludeDirNames -contains $_.Name) {
        return
    }
    if ($excludeFileNames -contains $_.Name) {
        return
    }
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $stagingApp $_.Name) -Recurse -Force
}

$localConfig = Join-Path $stagingApp 'config\database.local.php'
if (Test-Path $localConfig) {
    Remove-Item $localConfig -Force
}

$lockFile = Join-Path $stagingApp 'install\install.lock'
if (Test-Path $lockFile) {
    Remove-Item $lockFile -Force
}

Compress-Archive -Path (Join-Path $stagingApp '*') -DestinationPath $releasePath -Force
Remove-Item $stagingRoot -Recurse -Force

Write-Host "Created $releasePath"
