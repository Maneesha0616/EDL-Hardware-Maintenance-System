# =========================================================================
# HMS Automated Backup Scheduler
# =========================================================================
# This PowerShell script registers the HMS Database Backup in the Windows
# Task Scheduler. It is configured to run daily at 2:00 AM.
#
# MUST BE RUN AS ADMINISTRATOR.
#
# To run this script:
# 1. Open PowerShell as Administrator.
# 2. Run: Set-ExecutionPolicy Bypass -Scope Process -Force
# 3. Run: .\schedule_backup.ps1
# =========================================================================

$taskName = "HMS_Database_Backup"
$batPath = "C:\xampp\htdocs\HMS\backups_system\run_backup.bat"
$workingDir = "C:\xampp\htdocs\HMS\backups_system"

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "    HMS DATABASE BACKUP SCHEDULE REGISTER                        " -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host ""

# Check for administrative privileges
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "ERROR: This script must be run as Administrator." -ForegroundColor Red
    Write-Host "Please open PowerShell as Administrator and try again." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Alternative CMD One-Liner (Run in Admin Command Prompt):" -ForegroundColor White
    Write-Host "schtasks /create /tn `"$taskName`" /tr `"$batPath`" /sc daily /st 02:00 /f" -ForegroundColor Green
    Exit
}

Write-Host "Registering task '$taskName' to run daily at 2:00 AM..." -ForegroundColor Yellow

try {
    # Define Task Action (triggering the batch file in its working directory)
    $action = New-ScheduledTaskAction -Execute $batPath -WorkingDirectory $workingDir

    # Define Task Trigger (Daily at 2:00 AM)
    $trigger = New-ScheduledTaskTrigger -Daily -At "2:00 AM"

    # Define Task Settings (Allow running on batteries, start when available if missed)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

    # Register the Scheduled Task
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Description "Performs daily automated database export, ZIP compression, Google Drive backup upload, retention cleanup, and audit logging." -Force | Out-Null

    Write-Host ""
    Write-Host "=================================================================" -ForegroundColor Green
    Write-Host "SUCCESS: Scheduled Task registered successfully!" -ForegroundColor Green
    Write-Host "=================================================================" -ForegroundColor Green
    Write-Host "Task Details:" -ForegroundColor White
    Write-Host "  - Name:             $taskName" -ForegroundColor White
    Write-Host "  - Schedule:         Daily at 2:00 AM" -ForegroundColor White
    Write-Host "  - Target Batch:     $batPath" -ForegroundColor White
    Write-Host "  - Working Dir:      $workingDir" -ForegroundColor White
    Write-Host ""
    Write-Host "To test or run the task immediately, open Task Scheduler or run:" -ForegroundColor White
    Write-Host "  Start-ScheduledTask -TaskName `"$taskName`"" -ForegroundColor Green
    Write-Host "=================================================================" -ForegroundColor Green
}
catch {
    Write-Host "Failed to register scheduled task using PowerShell cmdlets." -ForegroundColor Red
    Write-Host "Attempting fallback with schtasks.exe..." -ForegroundColor Yellow

    $cmd = "schtasks /create /tn `"$taskName`" /tr `"$batPath`" /sc daily /st 02:00 /f"
    Invoke-Expression $cmd

    if ($LASTEXITCODE -eq 0) {
        Write-Host "SUCCESS: Scheduled Task registered via schtasks.exe!" -ForegroundColor Green
    } else {
        Write-Host "ERROR: Failed to register scheduled task. Ensure you have administrator rights." -ForegroundColor Red
    }
}
