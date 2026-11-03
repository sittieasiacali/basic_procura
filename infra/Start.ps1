[CmdletBinding()]
param([switch]$SkipImageLoad)
. (Join-Path $PSScriptRoot 'Common.ps1')
Initialize-Docker
$releasePath = Join-Path $script:BundleRoot 'release.json'
if (!(Test-Path -LiteralPath $releasePath)) { throw 'Build-Release.ps1 must finish successfully before starting this bundle.' }
$release = Get-Content -Raw -LiteralPath $releasePath | ConvertFrom-Json
if ($release.image_archive -notmatch '^images-[a-zA-Z0-9_.-]+\.tar$') { throw 'Invalid release archive path.' }
if (!$SkipImageLoad) {
    $archive = Join-Path $script:BundleRoot $release.image_archive
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $release.image_archive_sha256) { throw 'Image archive checksum mismatch.' }
    Invoke-Docker image load --input $archive
}
foreach ($image in $release.images) {
    $actual = ((Invoke-Docker image inspect --format '{{.Id}}' $image.name) -join '').Trim()
    if ($actual -ne $image.id) { throw "Image differs from the exported release: $($image.name)" }
}
Invoke-Compose up -d --wait --wait-timeout 180 database
$state = Get-ImportState
if ($state.stage -ne 'complete') { & (Join-Path $script:BundleRoot 'Import-Data.ps1') }
Invoke-Compose run --rm -T --no-deps app php artisan migrate --force --no-interaction
Invoke-Compose up -d --wait --wait-timeout 240
Write-Host ('PROCURA: ' + (Get-Setting APP_URL))
Write-Host 'The worker processes queued emails using secrets/smtp_password.txt. No demo users were seeded.'
