[CmdletBinding()]
param([switch]$Recreate, [switch]$CompareSnapshot)
. (Join-Path $PSScriptRoot 'Common.ps1')
Initialize-Docker
if ($Recreate) { Invoke-Compose up -d --force-recreate --no-build --pull never --wait --wait-timeout 240 }
foreach ($service in @('web','app','worker','scheduler','database')) {
    $id = ((Invoke-Compose ps -q $service) -join '').Trim()
    if (!$id) { throw "$service is not running." }
    $inspection = ((Invoke-Docker inspect $id) -join "`n" | ConvertFrom-Json)[0]
    if ($inspection.State.Status -ne 'running' -or $inspection.State.Health.Status -ne 'healthy') { throw "$service is not healthy." }
    foreach ($mount in $inspection.Mounts) {
        if ($mount.Type -eq 'bind' -and !$mount.Destination.StartsWith('/run/secrets/')) { throw "Unexpected host bind mount on $service; source independence is not verified." }
    }
}
Invoke-Compose exec -T app php /usr/local/lib/procura/verify.php
if ($CompareSnapshot) {
    $null = Get-Snapshot
    $snapshotMount = (Join-Path $script:BundleRoot 'snapshot.json') + ':/tmp/snapshot.json:ro'
    Invoke-Compose run --rm -T --no-deps -v $snapshotMount app php /usr/local/lib/procura/verify.php /tmp/snapshot.json
}
$url = (Get-Setting APP_URL).TrimEnd('/')
foreach ($path in @('/up','/login','/assets/images/Allianz_logo.png')) {
    $response = Invoke-WebRequest -UseBasicParsing -Uri ($url + $path)
    if ($response.StatusCode -ne 200) { throw "HTTP verification failed: $path" }
}
Write-Host 'Verified five healthy services, baked app/assets, database access, and no source bind mounts.'
