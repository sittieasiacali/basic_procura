[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [switch]$ValidateOnly
)

$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$utf8 = New-Object System.Text.UTF8Encoding($false)
$changes = [ordered]@{}

function Read-Template([string]$relativePath) {
    $path = Join-Path $ProjectRoot $relativePath
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Missing template: $path. No templates have been changed." }
    return [System.IO.File]::ReadAllText($path)
}

function Replace-Once([string]$content, [string]$before, [string]$after, [string]$label) {
    if ($content.Contains($after)) { return $content }
    $count = ([regex]::Matches($content, [regex]::Escape($before))).Count
    if ($count -ne 1) { throw "Expected one $label, found $count. No templates have been changed." }
    return $content.Replace($before, $after)
}

# Validate every replacement before writing any files. Older servers may have
# the request/stage banner inline rather than in the shared partial.
$homeTemplatePath = 'resources/views/layouts/home.blade.php'
$homeTemplate = Read-Template $homeTemplatePath
$include = "@include('partials.workflow-handoff')"
$inline = "@if(session('workflow_request_code'))"
if ($homeTemplate.Contains($include)) {
    $homeTemplate = Replace-Once $homeTemplate $include ("@unless(request()->routeIs('purchase-requests.show'))`r`n            " + $include + "`r`n        @endunless") 'workflow banner include'
} elseif ($homeTemplate.Contains($inline) -or $homeTemplate.Contains("@if(session('workflow_request_code') && ! request()->routeIs('purchase-requests.show'))")) {
    $homeTemplate = Replace-Once $homeTemplate $inline "@if(session('workflow_request_code') && ! request()->routeIs('purchase-requests.show'))" 'inline workflow banner'
} else {
    throw 'Unrecognized workflow banner in home.blade.php. No templates have been changed.'
}
$homeTemplate = Replace-Once $homeTemplate "@if(session('success'))" "@if(session('success') && ! session('workflow_submission_notice'))" 'success banner'
$changes[$homeTemplatePath] = $homeTemplate

$showPath = 'resources/views/purchase-requests/show.blade.php'
$show = Read-Template $showPath
$show = Replace-Once $show 'if ($requestStageReceipt) {' 'if ($requestStageReceipt && ! in_array($requestStageReceipt[''action''], [''SUBMITTED_FOR_SCREENING'', ''RESUBMITTED_FOR_SCREENING''], true)) {' 'screening receipt condition'
$changes[$showPath] = $show

if ($ValidateOnly) {
    Write-Host 'Validation passed. Both templates are compatible; no files were changed.'
    return
}
if (!(Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP not found: $PhpPath. No templates have been changed." }
if (!(Test-Path -LiteralPath (Join-Path $ProjectRoot 'artisan') -PathType Leaf)) { throw 'artisan was not found. No templates have been changed.' }

$backupRoot = Join-Path $env:LOCALAPPDATA ('Procura\backups\banner-cleanup-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N'))
foreach ($relativePath in $changes.Keys) {
    $destination = Join-Path $backupRoot $relativePath
    $null = New-Item -ItemType Directory -Path (Split-Path $destination -Parent) -Force
    Copy-Item -LiteralPath (Join-Path $ProjectRoot $relativePath) -Destination $destination
}
Write-Host "Template backups: $backupRoot"
try {
    foreach ($relativePath in $changes.Keys) {
        [System.IO.File]::WriteAllText((Join-Path $ProjectRoot $relativePath), $changes[$relativePath], $utf8)
    }
    & $PhpPath (Join-Path $ProjectRoot 'artisan') view:clear
    if ($LASTEXITCODE -ne 0) { throw 'Unable to clear compiled views.' }
} catch {
    foreach ($relativePath in $changes.Keys) {
        Copy-Item -LiteralPath (Join-Path $backupRoot $relativePath) -Destination (Join-Path $ProjectRoot $relativePath)
    }
    throw "Update failed; original templates restored. $($_.Exception.Message)"
}
Write-Host 'Banner cleanup applied. Refresh the request page. No worker restart or frontend build is required.'
