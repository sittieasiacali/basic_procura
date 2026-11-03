Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:BundleRoot = $PSScriptRoot
$script:DockerExe = $null

function Initialize-Docker {
    $command = Get-Command docker -ErrorAction SilentlyContinue
    if ($command) { $script:DockerExe = $command.Source }
    elseif (Test-Path -LiteralPath 'C:\Program Files\Docker\Docker\resources\bin\docker.exe') { $script:DockerExe = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe' }
    else { throw 'Install/start Docker Desktop with Linux containers, then open a new PowerShell terminal.' }
    $osType = & $script:DockerExe info --format '{{.OSType}}' 2>$null
    if ($LASTEXITCODE -ne 0 -or $osType -ne 'linux') { throw 'Docker Desktop must be running in Linux-container mode.' }
    & $script:DockerExe compose version | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose v2 is required.' }
}
function Invoke-Docker {
    # Keep this a simple function so Docker flags such as -e and -d pass through.
    & $script:DockerExe @args
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed (exit $LASTEXITCODE): $($args[0])" }
}
function Invoke-Compose {
    Invoke-Docker compose --env-file (Join-Path $script:BundleRoot 'runtime.env') --project-directory $script:BundleRoot -f (Join-Path $script:BundleRoot 'compose.yaml') @args
}
function Get-Setting([string]$Name) {
    $line = Get-Content -LiteralPath (Join-Path $script:BundleRoot 'runtime.env') | Where-Object { $_.StartsWith($Name + '=') } | Select-Object -First 1
    if (!$line) { throw "Missing runtime setting: $Name" }
    return $line.Substring($Name.Length + 1).Trim().Trim([char[]]@([char]39,[char]34))
}
function Invoke-DatabaseShell {
    param([string]$ShellScript, [string[]]$ShellArguments = @())
    # stdin preserves shell quotes on Windows PowerShell 5.1 native-command calls.
    ($ShellScript + "`n") | & $script:DockerExe compose --env-file (Join-Path $script:BundleRoot 'runtime.env') --project-directory $script:BundleRoot -f (Join-Path $script:BundleRoot 'compose.yaml') exec -T database sh -s -- @ShellArguments
    if ($LASTEXITCODE -ne 0) { throw 'Database container command failed.' }
}
function Get-Snapshot {
    $manifest = Get-Content -Raw -LiteralPath (Join-Path $script:BundleRoot 'snapshot.json') | ConvertFrom-Json
    if ($manifest.data_directory -notmatch '^data/[a-zA-Z0-9_-]+$') { throw 'Invalid snapshot directory.' }
    $directory = Join-Path $script:BundleRoot $manifest.data_directory
    foreach ($entry in @(@('database.sql', $manifest.database_sha256), @('uploads.tar', $manifest.uploads_sha256))) {
        if ((Get-FileHash -LiteralPath (Join-Path $directory $entry[0]) -Algorithm SHA256).Hash.ToLowerInvariant() -ne $entry[1]) { throw "Snapshot checksum failed: $($entry[0])" }
    }
    return $manifest
}
function Get-ImportState {
    $raw = Invoke-Compose run --rm -T --no-deps -e PROCURA_SKIP_BOOTSTRAP=1 app php /usr/local/lib/procura/state.php read
    $parsed = $raw -join "`n" | ConvertFrom-Json
    if (!$parsed -or !$parsed.PSObject.Properties['stage']) { return [pscustomobject]@{ stage='empty'; database_sha256='' } }
    return $parsed
}
