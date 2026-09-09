@echo off
REM =========================================================================
REM HMS Automated Backup Execution Wrapper
REM =========================================================================
REM This batch file is executed by Windows Task Scheduler to trigger the 
REM PHP backup CLI script. It outputs standard stream details.

echo [ %date% %time% ] Triggering database backup execution...
"C:\xampp\php\php.exe" -f "C:\xampp\htdocs\HMS\backups_system\backup.php"
echo [ %date% %time% ] Execution finished.
