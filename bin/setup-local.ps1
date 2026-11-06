$ErrorActionPreference = 'Stop'
$workspace = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$utf8 = New-Object System.Text.UTF8Encoding($false)
$secretDirectory = Join-Path $workspace '.secrets'
$envPath = Join-Path $workspace '.env'
function New-SecureHex {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
}
if (-not (Test-Path -LiteralPath $envPath)) {
    if (Test-Path -LiteralPath $secretDirectory) {
        throw 'Existing secrets found without .env; reconcile rather than rotating database passwords.'
    }
    New-Item -ItemType Directory -Path $secretDirectory | Out-Null
    $owner = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    & icacls $secretDirectory /inheritance:r /grant:r "${owner}:(OI)(CI)F" 'SYSTEM:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not protect local secret directory.' }
    $rootPassword = New-SecureHex
    $migrationPassword = New-SecureHex
    $appPassword = New-SecureHex
    [System.IO.File]::WriteAllText((Join-Path $secretDirectory 'mysql-root-password'), $rootPassword, $utf8)
    [System.IO.File]::WriteAllText((Join-Path $secretDirectory 'mysql-migration-password'), $migrationPassword, $utf8)
    [System.IO.File]::WriteAllText((Join-Path $secretDirectory 'mysql-app-password'), $appPassword, $utf8)
    $template = [System.IO.File]::ReadAllText((Join-Path $workspace '.env.example'))
    $template = $template.Replace('APP_KEY=', ('APP_KEY=' + (New-SecureHex)))
    $template = $template.Replace('SIMORA_DB_PASSWORD=', ('SIMORA_DB_PASSWORD=' + $appPassword))
    $template = $template.Replace('SIMORA_MIGRATION_DB_PASSWORD=', ('SIMORA_MIGRATION_DB_PASSWORD=' + $migrationPassword))
    [System.IO.File]::WriteAllText($envPath, $template, $utf8)
    & icacls $envPath /inheritance:r /grant:r "${owner}:F" 'SYSTEM:F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not protect .env.' }
    Write-Output 'Created protected local environment and random database secrets.'
} else {
    foreach ($secret in @('mysql-root-password', 'mysql-migration-password', 'mysql-app-password')) {
        if (-not (Test-Path -LiteralPath (Join-Path $secretDirectory $secret))) {
            throw 'Missing database secret; reconcile existing configuration without rotating passwords.'
        }
    }
    Write-Output 'Preserving existing environment and database secrets.'
}
Push-Location $workspace
try {
    & docker compose up -d --wait --wait-timeout 180 mysql
    if ($LASTEXITCODE -ne 0) { throw 'MySQL startup failed; secrets preserved for retry.' }
} finally { Pop-Location }
