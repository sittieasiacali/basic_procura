[CmdletBinding()]
param([string]$Destination = (Join-Path $PSScriptRoot ('backups/' + (Get-Date -Format 'yyyyMMdd-HHmmss'))))
. (Join-Path $PSScriptRoot 'Common.ps1')
Initialize-Docker
$backupRoot = [IO.Path]::GetFullPath($Destination)
if (Test-Path -LiteralPath $backupRoot) { throw 'Choose a new backup directory; existing backups will not be overwritten.' }
$running = @(Invoke-Compose ps --status running --services)
$pause = @($running | Where-Object { $_ -in @('web','worker','scheduler') })
if ($pause.Count -gt 0) { Invoke-Compose stop @pause }
$remoteId = [guid]::NewGuid().ToString('N')
$remoteSql = '/tmp/procura-' + $remoteId + '.sql'
$remoteTar = '/tmp/procura-' + $remoteId + '.tar'
$remoteManifest = '/tmp/procura-' + $remoteId + '.json'
try {
    $relative = 'data/' + (Get-Date -Format 'yyyyMMdd-HHmmss')
    $dataPath = Join-Path $backupRoot $relative
    New-Item -ItemType Directory -Path $dataPath -Force | Out-Null
    $dbContainer = ((Invoke-Compose ps -q database) -join '').Trim()
    $appContainer = ((Invoke-Compose ps -q app) -join '').Trim()
    if (!$appContainer -or !$dbContainer) { throw 'The database and PHP app must be running before backup.' }
    Invoke-DatabaseShell -ShellScript 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" exec mariadb-dump -uroot --single-transaction --quick --routines --events --triggers --hex-blob --default-character-set=utf8mb4 --result-file="$1" "$MARIADB_DATABASE"' -ShellArguments @($remoteSql)
    Invoke-Compose exec -T app tar -cf $remoteTar -C /var/www/html/storage/app private public
    Invoke-Compose exec -T app php /usr/local/lib/procura/manifest.php $remoteManifest
    Invoke-Docker cp ($dbContainer + ':' + $remoteSql) (Join-Path $dataPath 'database.sql')
    Invoke-Docker cp ($appContainer + ':' + $remoteTar) (Join-Path $dataPath 'uploads.tar')
    Invoke-Docker cp ($appContainer + ':' + $remoteManifest) (Join-Path $dataPath 'manifest.json')
    $manifest = Get-Content -Raw -LiteralPath (Join-Path $dataPath 'manifest.json') | ConvertFrom-Json
    $manifest | Add-Member -NotePropertyName data_directory -NotePropertyValue $relative -Force
    $manifest | Add-Member -NotePropertyName database_sha256 -NotePropertyValue ((Get-FileHash -LiteralPath (Join-Path $dataPath 'database.sql') -Algorithm SHA256).Hash.ToLowerInvariant()) -Force
    $manifest | Add-Member -NotePropertyName uploads_sha256 -NotePropertyValue ((Get-FileHash -LiteralPath (Join-Path $dataPath 'uploads.tar') -Algorithm SHA256).Hash.ToLowerInvariant()) -Force
    $json = $manifest | ConvertTo-Json -Depth 12
    [IO.File]::WriteAllText((Join-Path $backupRoot 'snapshot.json'), $json, [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $dataPath 'manifest.json'), $json, [Text.UTF8Encoding]::new($false))
    foreach ($file in @('compose.yaml','runtime.env','release.json','Common.ps1','Start.ps1','Import-Data.ps1','Verify.ps1','Backup.ps1','README.md')) {
        Copy-Item -LiteralPath (Join-Path $script:BundleRoot $file) -Destination (Join-Path $backupRoot $file)
    }
    Copy-Item -LiteralPath (Join-Path $script:BundleRoot 'secrets') -Destination (Join-Path $backupRoot 'secrets') -Recurse
    $release = Get-Content -Raw -LiteralPath (Join-Path $script:BundleRoot 'release.json') | ConvertFrom-Json
    if ($release.image_archive -notmatch '^images-[a-zA-Z0-9_.-]+\.tar$') { throw 'Invalid release image archive.' }
    Copy-Item -LiteralPath (Join-Path $script:BundleRoot $release.image_archive) -Destination (Join-Path $backupRoot $release.image_archive)
    Write-Host "Full deployment backup saved to $backupRoot"
} finally {
    if ((Get-Variable dbContainer -ErrorAction SilentlyContinue) -and $dbContainer) { Invoke-Docker exec $dbContainer rm -f $remoteSql }
    if ((Get-Variable appContainer -ErrorAction SilentlyContinue) -and $appContainer) { Invoke-Docker exec $appContainer rm -f $remoteTar $remoteManifest }
    if ($pause.Count -gt 0) { Invoke-Compose start @pause }
}
