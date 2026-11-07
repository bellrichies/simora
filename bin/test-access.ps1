param([ValidateSet('AccessIntegrationTest', 'PaymentsIntegrationTest', 'ExperienceIntegrationTest')][string]$Suite = 'AccessIntegrationTest')
$ErrorActionPreference = 'Stop'
$workspace = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$testDatabase = 'simora_access_test_' + [Guid]::NewGuid().ToString('N').Substring(0, 16)
$previousDatabase = $env:SIMORA_ACCESS_TEST_DB
$scriptBytes = [System.IO.File]::ReadAllBytes((Join-Path $workspace 'tests/access-test-db.sh'))
$encodedScript = [Convert]::ToBase64String($scriptBytes)
Push-Location $workspace
try {
    docker compose exec -T mysql bash -c "printf %s $encodedScript | base64 -d | bash -s -- create $testDatabase"
    if ($LASTEXITCODE -ne 0) { throw 'Could not create isolated access test database.' }
    $env:SIMORA_ACCESS_TEST_DB = $testDatabase
    php vendor/phpunit/phpunit/phpunit --bootstrap bootstrap.php "tests/$Suite.php"
    $testExit = $LASTEXITCODE
} finally {
    docker compose exec -T mysql bash -c "printf %s $encodedScript | base64 -d | bash -s -- drop $testDatabase"
    $cleanupExit = $LASTEXITCODE
    $env:SIMORA_ACCESS_TEST_DB = $previousDatabase
    Pop-Location
}
if ($cleanupExit -ne 0) { throw 'Test database cleanup failed; inspect the isolated test database.' }
exit $testExit
