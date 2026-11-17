param([string]$PhpPath = 'C:\xampp\php\php.exe')
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Resolve-Path -LiteralPath $PhpPath).Path
$powershellExecutable = (Get-Command powershell.exe).Source
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$prefix = 'GreenPulse-' + (Split-Path -Leaf $projectRoot)
# UTC+08:00 is Manila; an ISO boundary with an offset avoids host timezone assumptions.
$manila = [TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTimeOffset]::UtcNow, 'Singapore Standard Time')
$nextBackup = [DateTimeOffset]::new($manila.Year, $manila.Month, $manila.Day, 2, 0, 0, [TimeSpan]::FromHours(8))
if ($nextBackup -le [DateTimeOffset]::UtcNow) { $nextBackup = $nextBackup.AddDays(1) }
$daily = New-ScheduledTaskTrigger -Daily -At $nextBackup.LocalDateTime
$daily.StartBoundary = $nextBackup.ToString('yyyy-MM-ddTHH:mm:sszzz')
$minute = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$jobs = @(
    @{ Name = "$prefix-Notifications"; Script = 'notifications.php'; Trigger = $minute },
    @{ Name = "$prefix-Backup"; Script = 'backup.php'; Trigger = $daily }
)
foreach ($job in $jobs) {
    $scriptPath = Join-Path $PSScriptRoot 'run-maintenance.ps1'
    $jobArgument = [IO.Path]::GetFileNameWithoutExtension($job.Script)
    $arguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $scriptPath + '" -Job ' + $jobArgument + ' -PhpPath "' + $phpExecutable + '"'
    $action = New-ScheduledTaskAction -Execute $powershellExecutable -Argument $arguments -WorkingDirectory $projectRoot
    $existing = Get-ScheduledTask -TaskName $job.Name -ErrorAction SilentlyContinue
    if ($existing) {
        if ($existing.Actions.Execute -ne $powershellExecutable -or $existing.Actions.Arguments -ne $arguments) { throw "Task $($job.Name) already exists with a different action. No replacement performed." }
        Write-Output "Already installed: $($job.Name)"
        continue
    }
    Register-ScheduledTask -TaskName $job.Name -Action $action -Trigger $job.Trigger -Principal $principal -Settings $settings -Description "GreenPulse maintenance for $projectRoot. Runs while $identity is signed in." | Select-Object TaskName, State
}
