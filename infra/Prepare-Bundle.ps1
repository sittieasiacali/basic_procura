[CmdletBinding()]
param(
    [string]$Destination = (Join-Path $PSScriptRoot 'release'),
    [string]$Php = 'C:\xampp\php\php.exe',
    [string]$MysqlDump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [ValidateRange(1,65535)][int]$Port = 8080,
    [ValidatePattern('^[a-zA-Z0-9_.-]+$')][string]$Tag = 'local'
)
$ErrorActionPreference = 'Stop'
$sourceRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$bundlePath = [IO.Path]::GetFullPath($Destination)
if ($bundlePath.TrimEnd('\') -eq $sourceRoot.TrimEnd('\')) { throw 'Choose a deployment directory, not the source root.' }
if (!(Test-Path -LiteralPath $Php) -or !(Test-Path -LiteralPath $MysqlDump)) { throw 'PHP and mysqldump must be available from XAMPP.' }
foreach ($disk in @('private','public')) {
    $uploadRoot = Join-Path $sourceRoot ('storage/app/' + $disk)
    $links = @(Get-ChildItem -LiteralPath $uploadRoot -Recurse -Force | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint })
    if ($links.Count -gt 0) { throw 'Remove upload symlinks/junctions before exporting a portable snapshot.' }
}
New-Item -ItemType Directory -Path $bundlePath -Force | Out-Null
foreach ($file in @('compose.yaml','Common.ps1','Start.ps1','Import-Data.ps1','Verify.ps1','Backup.ps1')) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination (Join-Path $bundlePath $file) -Force
}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'README.md') -Destination (Join-Path $bundlePath 'README.md') -Force
& $Php (Join-Path $PSScriptRoot 'tools/export-xampp.php') $bundlePath $MysqlDump $Port $Tag
if ($LASTEXITCODE -ne 0) { throw 'Snapshot export failed; no containers were started.' }
Write-Host "Prepared $bundlePath. Images must still be built/exported before deleting any source files."
