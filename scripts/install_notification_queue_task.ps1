$ErrorActionPreference = 'Stop'
$phpCli = 'C:\xampp\php\php.exe'
$phpWindowed = 'C:\xampp\php\php-win.exe'
$php = if (Test-Path -LiteralPath $phpWindowed) { $phpWindowed } else { $phpCli }
$worker = 'C:\xampp\htdocs\BenePeso\scripts\process_notification_queue.php'
$taskName = 'BENEPESO Notification Queue'

if (-not (Test-Path -LiteralPath $phpCli) -or -not (Test-Path -LiteralPath $worker)) {
    throw 'BENEPESO notification worker files were not found.'
}

$action = New-ScheduledTaskAction -Execute $php -Argument ('"{0}" 25' -f $worker)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 20) -StartWhenAvailable -Hidden
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Description 'Reliably delivers queued BENEPESO beneficiary notifications.' -Force | Out-Null
Write-Output "Installed scheduled task: $taskName"
