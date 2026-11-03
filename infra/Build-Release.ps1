[CmdletBinding()]
param(
    [string]$Destination = 'C:\Procura-deploy',
    [string]$Php = 'C:\xampp\php\php.exe',
    [string]$MysqlDump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [ValidateRange(1,65535)][int]$Port = 8080,
    [ValidatePattern('^[a-zA-Z0-9_.-]+$')][string]$Tag = (Get-Date -Format 'yyyyMMdd-HHmmss')
)
. (Join-Path $PSScriptRoot 'Common.ps1')
Initialize-Docker
$sourceRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')).TrimEnd('\')
$bundlePath = [IO.Path]::GetFullPath($Destination).TrimEnd('\')
if ($bundlePath -eq $sourceRoot -or $bundlePath.StartsWith($sourceRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'The final release must be OUTSIDE the source folder so it survives source deletion.'
}
& (Join-Path $PSScriptRoot 'Prepare-Bundle.ps1') -Destination $bundlePath -Php $Php -MysqlDump $MysqlDump -Port $Port -Tag $Tag
$script:BundleRoot = $bundlePath
$previousContext = [Environment]::GetEnvironmentVariable('PROCURA_SOURCE_CONTEXT', 'Process')
try {
    $env:PROCURA_SOURCE_CONTEXT = $sourceRoot.Replace('\','/')
    Invoke-Docker compose --env-file (Join-Path $bundlePath 'runtime.env') -f (Join-Path $bundlePath 'compose.yaml') -f (Join-Path $PSScriptRoot 'compose.build.yaml') build app web
} finally {
    [Environment]::SetEnvironmentVariable('PROCURA_SOURCE_CONTEXT', $previousContext, 'Process')
}
$images = @((Get-Setting PROCURA_APP_IMAGE), (Get-Setting PROCURA_WEB_IMAGE), (Get-Setting PROCURA_DB_IMAGE))
Invoke-Docker pull $images[2]
$archiveName = 'images-' + $Tag + '.tar'
$archive = Join-Path $bundlePath $archiveName
Invoke-Docker image save --output $archive @images
$records = @()
foreach ($image in $images) { $records += @{ name=$image; id=((Invoke-Docker image inspect --format '{{.Id}}' $image) -join '').Trim() } }
$release = @{ version=1; created_at=(Get-Date).ToUniversalTime().ToString('o'); image_archive=$archiveName; image_archive_sha256=(Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant(); images=$records }
$release | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $bundlePath 'release.json') -Encoding UTF8
Write-Host "Release built and exported to $bundlePath. Run Start.ps1 and Verify.ps1 there before removing any source."
