param(
    [Parameter(Mandatory = $true)][ValidateSet('notifications', 'backup')][string]$Job,
    [string]$PhpPath = 'C:\xampp\php\php.exe'
)
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$logDirectory = Join-Path $projectRoot 'storage/logs'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
$scriptPath = Join-Path $PSScriptRoot ($Job + '.php')
$logFile = Join-Path $logDirectory ($Job + '.log')
# Keep one previous log when the current log exceeds 1 MiB.
if ((Test-Path -LiteralPath $logFile) -and (Get-Item -LiteralPath $logFile).Length -gt 1MB) {
    Copy-Item -LiteralPath $logFile -Destination ($logFile + '.previous') -Force
    Clear-Content -LiteralPath $logFile
}
Push-Location $projectRoot
try {
    $ErrorActionPreference = 'Continue'
    $output = & $PhpPath $scriptPath 2>&1
    $exitCode = $LASTEXITCODE
    ('[' + [DateTimeOffset]::Now.ToString('o') + '] ' + ($output -join [Environment]::NewLine)) | Add-Content -LiteralPath $logFile
    exit $exitCode
} finally { Pop-Location }
