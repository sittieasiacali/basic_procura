[CmdletBinding()]
param()
. (Join-Path $PSScriptRoot 'Common.ps1')
Initialize-Docker
$snapshot = Get-Snapshot
$dataPath = Join-Path $script:BundleRoot $snapshot.data_directory
Invoke-Compose up -d --wait --wait-timeout 180 database
$state = Get-ImportState
if ($state.stage -eq 'complete') { Write-Host 'A verified import already exists; existing data was not overwritten.'; return }
$tableCount = ((Invoke-DatabaseShell -ShellScript 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" exec mariadb -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" "$MARIADB_DATABASE"') -join '').Trim()
if ($tableCount -notmatch '^\d+$') { throw 'Could not determine whether the target database is empty.' }
if ([int]$tableCount -gt 0 -and ($state.stage -ne 'database' -or $state.database_sha256 -ne $snapshot.database_sha256)) {
    throw 'Target database already contains data. Import refused; no existing database was overwritten.'
}
Invoke-Compose stop web worker scheduler app
$dbContainer = ((Invoke-Compose ps -q database) -join '').Trim()
if ([int]$tableCount -eq 0) {
    $remoteSql = '/tmp/procura-import-' + [guid]::NewGuid().ToString('N') + '.sql'
    try {
        Invoke-Docker cp (Join-Path $dataPath 'database.sql') ($dbContainer + ':' + $remoteSql)
        Invoke-DatabaseShell -ShellScript 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" exec mariadb --default-character-set=utf8mb4 -uroot "$MARIADB_DATABASE" < "$1"' -ShellArguments @($remoteSql)
    } finally { Invoke-Docker exec $dbContainer rm -f $remoteSql }
    Invoke-Compose run --rm -T --no-deps -e PROCURA_SKIP_BOOTSTRAP=1 app php /usr/local/lib/procura/state.php database $snapshot.database_sha256
}
$importName = 'procura-upload-import-' + [guid]::NewGuid().ToString('N')
try {
    # Docker cannot copy into tmpfs on a container with a read-only root filesystem.
    $uploadMount = (Join-Path $dataPath 'uploads.tar') + ':/tmp/uploads.tar:ro'
    $snapshotMount = (Join-Path $script:BundleRoot 'snapshot.json') + ':/tmp/snapshot.json:ro'
    Invoke-Compose run -d --no-deps --name $importName -v $uploadMount -v $snapshotMount -e PROCURA_SKIP_BOOTSTRAP=1 app sleep infinity | Out-Host
    Invoke-Docker exec $importName procura-restore-uploads /tmp/uploads.tar
    Invoke-Docker exec $importName procura-entrypoint php /usr/local/lib/procura/verify.php /tmp/snapshot.json
    Invoke-Docker exec $importName php /usr/local/lib/procura/state.php complete $snapshot.database_sha256
} finally { Invoke-Docker rm -f $importName | Out-Host }
Write-Host 'Imported database, preserved APP_KEY, and verified all uploaded files against the snapshot.'
