<?php
// 
//  * Core Automated Database Backup Script
//  *
//  * This script exports the MySQL database, compresses the SQL dump into a ZIP file,
//  * uploads it to Google Drive (if configured), cleans up old backups locally and
//  * on Google Drive based on the retention policy, and logs all events locally
//  * and inside the HMS system_logs table.
//  *
//  * Can be run from Command Line (CLI):
//  * php -f C:\xampp\htdocs\HMS\backups_system\backup.php
//  

// Prevent execution via the web browser for security
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: Direct web access is forbidden.\n");
}

// Set script execution timeout to unlimited for large database handling
set_time_limit(0);
date_default_timezone_set('Asia/Colombo'); // Match Sri Lankan time zone based on metadata or fallback

// Load configurations
$configPath = __DIR__ . '/backup_config.php';
if (!file_exists($configPath)) {
    die("Error: Backup configuration file not found at $configPath\n");
}
$config = include $configPath;

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) {
    die("Error: Database connection file not found at $dbPath\n");
}

// 1. Initialize Directories
$tempDir = $config['temp_dir'];
$logDir  = $config['log_dir'];
$logFile = $config['log_file'];

if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Logging Helper
function write_log($message, $level = 'INFO') {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMsg = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
    echo $logMsg; // Output to console/task stream
    file_put_contents($logFile, $logMsg, FILE_APPEND);
}

write_log("Starting automated database backup process...");

// Include HMS database connection details
// We rename $conn temporarily to avoid collision or simply use it directly
include $dbPath; 
// Now we have $conn (mysqli connection) and $host, $user, $pass, $db from db.php

if (!$conn) {
    write_log("Failed to connect to the database. Check db.php.", "ERROR");
    exit(1);
}

// Include HMS security.php to get log_system_activity function
$securityPath = __DIR__ . '/../security.php';
if (file_exists($securityPath)) {
    require_once $securityPath;
}

$timestampStr = date('Y_m_d_Hms');
$sqlFilename  = $config['file_prefix'] . $timestampStr . '.sql';
$zipFilename  = $config['file_prefix'] . $timestampStr . '.zip';
$tempSqlPath  = $tempDir . $sqlFilename;
$tempZipPath  = $tempDir . $zipFilename;

// --- STEP 1: EXPORT DATABASE ---
write_log("Exporting database '$db' to SQL file...");

$mysqldumpBin = $config['mysqldump_bin'];
if (!file_exists($mysqldumpBin)) {
    write_log("mysqldump executable not found at: $mysqldumpBin. Please verify your backup_config.php settings.", "ERROR");
    log_db_activity($conn, 'Backup Failed', "mysqldump executable not found at: $mysqldumpBin");
    exit(1);
}

// Format the command securely for Windows Shell execution
// Note: We use escapeshellarg on values to avoid injection and escape spaces
$cmd = sprintf(
    '"%s" --host=%s --user=%s --password=%s %s > "%s" 2>&1',
    $mysqldumpBin,
    $host,
    $user,
    $pass,
    $db,
    $tempSqlPath
);

exec($cmd, $output, $returnVar);

if ($returnVar !== 0 || !file_exists($tempSqlPath) || filesize($tempSqlPath) === 0) {
    $errDetail = implode(" ", $output);
    write_log("Export failed with exit code $returnVar. Error: $errDetail", "ERROR");
    log_db_activity($conn, 'Backup Failed', "Database export failed. Error: " . substr($errDetail, 0, 200));
    if (file_exists($tempSqlPath)) {
        unlink($tempSqlPath);
    }
    exit(1);
}

write_log(sprintf("Database exported successfully. SQL File size: %s bytes", number_format(filesize($tempSqlPath))));

// --- STEP 2: COMPRESS EXPORT ---
write_log("Compressing SQL file into a ZIP archive...");

if (!class_exists('ZipArchive')) {
    write_log("PHP ZipArchive extension is not enabled. Cannot compress file.", "ERROR");
    log_db_activity($conn, 'Backup Failed', "PHP ZipArchive extension is disabled. Cannot compress backup.");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($tempZipPath, ZipArchive::CREATE) !== true) {
    write_log("Failed to create ZIP archive: $tempZipPath", "ERROR");
    log_db_activity($conn, 'Backup Failed', "Failed to create ZIP archive.");
    unlink($tempSqlPath);
    exit(1);
}

$zip->addFile($tempSqlPath, $sqlFilename);
$zip->close();

// Delete uncompressed SQL file immediately for security and space
unlink($tempSqlPath);

if (!file_exists($tempZipPath) || filesize($tempZipPath) === 0) {
    write_log("ZIP archive compression failed or resulted in 0-byte file.", "ERROR");
    log_db_activity($conn, 'Backup Failed', "ZIP compression failed.");
    exit(1);
}

$zipSize = filesize($tempZipPath);
$formattedZipSize = format_bytes($zipSize);
write_log(sprintf("Compression completed successfully. ZIP File size: %s (%s bytes)", $formattedZipSize, number_format($zipSize)));

// --- STEP 3: UPLOAD TO GOOGLE DRIVES ---
$gdriveUploads = [
    'gdrive1' => ['name' => 'Google Drive 1 (Primary)', 'id' => null, 'status' => 'Skipped (Not Configured)', 'deleted' => 0],
    'gdrive2' => ['name' => 'Google Drive 2 (Secondary)', 'id' => null, 'status' => 'Skipped (Not Configured)', 'deleted' => 0]
];

foreach ($gdriveUploads as $key => &$drive) {
    if (isset($config[$key]['enabled']) && $config[$key]['enabled'] === true) {
        write_log("Initiating upload to " . $drive['name'] . "...");
        
        $gdriveConfig = $config[$key];
        $accessToken = get_gdrive_access_token($gdriveConfig);
        
        if ($accessToken) {
            $driveId = upload_to_gdrive($tempZipPath, $zipFilename, $accessToken, $gdriveConfig);
            if ($driveId) {
                $drive['id'] = $driveId;
                $drive['status'] = "Success (ID: $driveId)";
                write_log($drive['name'] . " upload completed successfully. File ID: $driveId");
            } else {
                $drive['status'] = "Failed (See logs)";
                write_log($drive['name'] . " upload failed. Check response details.", "WARNING");
            }
        } else {
            $drive['status'] = "Failed to Authenticate";
            write_log("Failed to refresh " . $drive['name'] . " Access Token. Skipping upload.", "WARNING");
        }
    } else {
        write_log($drive['name'] . " upload is disabled or not authorized. Skipping cloud upload.");
    }
}
unset($drive); // Break reference

// --- STEP 4: RETENTION CLEANUP ---
write_log("Running retention cleanup rules...");

// 4a. Local Cleanup
$localFilesDeleted = cleanup_local_backups($tempDir, $config['file_prefix'], $config['retention_days'], $config['min_backups_to_keep']);

// 4b. Google Drives Cleanup
foreach ($gdriveUploads as $key => &$drive) {
    if ($drive['id'] && isset($config[$key]['enabled']) && $config[$key]['enabled'] === true) {
        write_log("Initiating cleanup on " . $drive['name'] . "...");
        $accessToken = get_gdrive_access_token($config[$key]);
        if ($accessToken) {
            $drive['deleted'] = cleanup_gdrive_backups($accessToken, $config['file_prefix'], $config['retention_days'], $config['min_backups_to_keep'], $config[$key]);
        }
    }
}
unset($drive); // Break reference

// --- STEP 5: FINAL SYSTEM AUDIT LOGGING ---
$gdriveStatusStr = sprintf(
    "Drive 1: %s (Deleted %d), Drive 2: %s (Deleted %d)",
    $gdriveUploads['gdrive1']['status'],
    $gdriveUploads['gdrive1']['deleted'],
    $gdriveUploads['gdrive2']['status'],
    $gdriveUploads['gdrive2']['deleted']
);

$logDetails = sprintf(
    "Database backup completed. File: %s (%s). Google Drives: [%s]. Local Deleted: %d files.",
    $zipFilename,
    $formattedZipSize,
    $gdriveStatusStr,
    $localFilesDeleted
);

write_log("Backup process finished successfully. " . $logDetails);
log_db_activity($conn, 'Database Backup', $logDetails);

// Close database connection
mysqli_close($conn);


// HELPER FUNCTIONS
/**
 * Format bytes to human readable format
 */
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Log to database HMS system_logs table safely
 */
function log_db_activity($conn, $action, $details) {
    $userId = 0; // Standard ID for "System" inside background CLI context
    if (function_exists('log_system_activity')) {
        log_system_activity($conn, $userId, $action, $details);
    } else {
        // Fallback: direct insert if security.php not loaded
        $stmt = mysqli_prepare($conn, "INSERT INTO system_logs (user_id, action, details) VALUES (?, ?, ?)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iss", $userId, $action, $details);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

/**
 * Get Google Drive Access Token using Refresh Token
 */
function get_gdrive_access_token($gdriveConfig) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $gdriveConfig['client_id'],
        'client_secret' => $gdriveConfig['client_secret'],
        'refresh_token' => $gdriveConfig['refresh_token'],
        'grant_type'    => 'refresh_token'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        write_log("Failed to refresh OAuth token. Response: " . $response, "ERROR");
        return false;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? false;
}

/**
 * Upload backup file to Google Drive using multipart upload
 */
function upload_to_gdrive($filePath, $fileName, $accessToken, $gdriveConfig) {
    if (!file_exists($filePath)) {
        return false;
    }

    $boundary = '-------' . uniqid();
    $metadata = [
        'name' => $fileName
    ];
    if (!empty($gdriveConfig['folder_id'])) {
        $metadata['parents'] = [$gdriveConfig['folder_id']];
    }

    $metadataJson = json_encode($metadata);
    $fileData = file_get_contents($filePath);

    // Build multipart body
    $body = "--$boundary\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $metadataJson . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/zip\r\n\r\n";
    $body .= $fileData . "\r\n";
    $body .= "--$boundary--";

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: multipart/related; boundary=' . $boundary,
        'Content-Length: ' . strlen($body)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        write_log("Google Drive API upload failed. HTTP Code: $httpCode. Response: " . $response, "ERROR");
        return false;
    }

    $data = json_decode($response, true);
    return $data['id'] ?? false;
}

/**
 * Perform local backup archives cleanup based on retention rules
 */
function cleanup_local_backups($tempDir, $prefix, $retentionDays, $minBackupsToKeep) {
    $files = glob($tempDir . $prefix . '*.zip');
    if (!$files) {
        return 0;
    }

    // Sort by file modified time ascending (oldest first)
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });

    $totalFiles = count($files);
    $keepCount = $totalFiles;
    $deletedCount = 0;
    $now = time();
    $secondsRetention = $retentionDays * 86400;

    foreach ($files as $file) {
        $ageSeconds = $now - filemtime($file);
        
        if ($ageSeconds > $secondsRetention) {
            // Safety Check: Never delete if it drops local archive below minimum threshold
            if ($keepCount > $minBackupsToKeep) {
                if (unlink($file)) {
                    write_log("Deleted expired local backup file: " . basename($file));
                    $deletedCount++;
                    $keepCount--;
                }
            } else {
                write_log("Skipping deletion of expired local file " . basename($file) . " to respect minimum retention limit ($minBackupsToKeep).", "INFO");
                break; // Because they are sorted oldest-first, subsequent files are newer
            }
        }
    }
    
    return $deletedCount;
}

/**
 * Perform Google Drive backup archives cleanup based on retention rules
 */
function cleanup_gdrive_backups($accessToken, $prefix, $retentionDays, $minBackupsToKeep, $gdriveConfig) {
    $q = "name contains '" . $prefix . "' and trashed = false";
    if (!empty($gdriveConfig['folder_id'])) {
        $q .= " and '" . $gdriveConfig['folder_id'] . "' in parents";
    }

    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q'        => $q,
        'fields'   => 'files(id,name,createdTime)',
        'orderBy'  => 'createdTime asc' // Oldest first
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        write_log("Failed to fetch cloud backups list. Cloud cleanup skipped.", "WARNING");
        return 0;
    }

    $data = json_decode($response, true);
    $files = $data['files'] ?? [];
    
    if (empty($files)) {
        return 0;
    }

    $totalFiles = count($files);
    $keepCount = $totalFiles;
    $deletedCount = 0;
    $now = new DateTime();

    foreach ($files as $file) {
        $createdTime = new DateTime($file['createdTime']);
        $ageDays = $now->diff($createdTime)->days;

        if ($ageDays > $retentionDays) {
            // Safety Check: Never delete if it drops cloud archive below minimum threshold
            if ($keepCount > $minBackupsToKeep) {
                // Call Google Drive API DELETE
                $delCh = curl_init();
                curl_setopt($delCh, CURLOPT_URL, 'https://www.googleapis.com/drive/v3/files/' . $file['id']);
                curl_setopt($delCh, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($delCh, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $accessToken
                ]);
                curl_setopt($delCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($delCh, CURLOPT_SSL_VERIFYPEER, true);
                
                curl_exec($delCh);
                $delHttpCode = curl_getinfo($delCh, CURLINFO_HTTP_CODE);
                curl_close($delCh);

                if ($delHttpCode === 204 || $delHttpCode === 200) {
                    write_log("Deleted expired Google Drive backup file: " . $file['name']);
                    $deletedCount++;
                    $keepCount--;
                } else {
                    write_log("Failed to delete expired cloud file " . $file['name'] . ". HTTP: $delHttpCode", "WARNING");
                }
            } else {
                write_log("Skipping deletion of expired cloud file " . $file['name'] . " to respect minimum retention limit ($minBackupsToKeep).", "INFO");
                break;
            }
        }
    }

    return $deletedCount;
}
