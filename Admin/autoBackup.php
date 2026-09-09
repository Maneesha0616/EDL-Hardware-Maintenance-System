<?php
/**
 * Automated Database Backup and Multi-Google Drive Upload
 *
 * This script generates a SQL dump of the HMS database and uploads a copy
 * to multiple Google Drive Folders in succession using a Service Account.
 */

// Prevent direct web access for security, but allow CLI run
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: Direct web access is forbidden.\n");
}

// Set Sri Lankan time zone based on active workspace metadata
date_default_timezone_set('Asia/Colombo');

// Set execution time limit to unlimited
set_time_limit(0);

// Load Database configurations
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) {
    die("Error: Database connection file not found at db.php.\n");
}
include $dbPath;

if (!$conn) {
    die("Error: Database connection failed.\n");
}

// GOOGLE SERVICE ACCOUNT CONFIGURATION// 
$serviceAccountKeyPath = __DIR__ . '/google-service-account.json';

// GOOGLE DRIVE FOLDER CONFIGURATION// 







// Define your Google Drive Folder IDs here (supports multiple folder IDs / Google Drives)
$folderIds = [
    "1Tmothydy91NQ899soZJHEaKZ0TLBDpru",
    "YOUR_SECOND_GOOGLE_DRIVE_FOLDER_ID_HERE"
];

// --- STEP 1: EXPORT DATABASE ---
$timestamp = date('Y-m-d_H-i-s');
$backupFilename = "backup_" . $timestamp . ".sql";
$backupFilePath = __DIR__ . DIRECTORY_SEPARATOR . $backupFilename;

// Register shutdown function to automatically clean up the local SQL file in case of any failure
register_shutdown_function(function() {
    global $backupFilePath;
    if (!empty($backupFilePath) && file_exists($backupFilePath)) {
        unlink($backupFilePath);
    }
});

try {
    $dbName = $conn->query("SELECT DB_NAME()")->fetchColumn();
    
    $fp = fopen($backupFilePath, 'w');
    if (!$fp) {
        throw new Exception("Cannot open backup file for writing: $backupFilePath");
    }

    fwrite($fp, "-- HMS Database Backup (SQL Server)\n");
    fwrite($fp, "-- Generated at: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- Database: " . $dbName . "\n\n");

    // Disable constraints
    fwrite($fp, "EXEC sp_MSforeachtable \"ALTER TABLE ? NOCHECK CONSTRAINT all\";\n\n");

    // Get all user tables
    $tablesStmt = $conn->query("
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_TYPE = 'BASE TABLE' 
          AND TABLE_CATALOG = " . $conn->quote($dbName) . " 
        ORDER BY TABLE_NAME
    ");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        fwrite($fp, "-- Table: $table\n");
        
        // Dump rows
        $rowStmt = $conn->query("SELECT * FROM [" . $table . "]");
        while ($row = $rowStmt->fetch(PDO::FETCH_ASSOC)) {
            $cols = [];
            $vals = [];
            foreach ($row as $colName => $val) {
                $cols[] = "[" . $colName . "]";
                if ($val === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($val) || is_float($val)) {
                    $vals[] = $val;
                } elseif ($val instanceof DateTime) {
                    $vals[] = $conn->quote($val->format('Y-m-d H:i:s'));
                } else {
                    $vals[] = $conn->quote($val);
                }
            }
            fwrite($fp, "INSERT INTO [" . $table . "] (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n");
        }
        fwrite($fp, "\n");
    }

    // Re-enable constraints
    fwrite($fp, "EXEC sp_MSforeachtable \"ALTER TABLE ? WITH CHECK CHECK CONSTRAINT all\";\n");
    fclose($fp);
} catch (Exception $e) {
    die("Error: Database backup failed. Details: " . $e->getMessage() . "\n");
}

echo "Backup created successfully: " . $backupFilename . "\n";

// --- STEP 2: AUTHENTICATE GOOGLE SERVICE ACCOUNT ---
$accessToken = getServiceAccountAccessToken($serviceAccountKeyPath);
if (!$accessToken) {
    die("Error: Failed to obtain access token from Google Service Account.\n");
}

// --- STEP 3: UPLOAD TO EACH FOLDER ---
$driveIndex = 1;
foreach ($folderIds as $folderId) {
    // Trim and ignore placeholders
    $folderId = trim($folderId);
    if (empty($folderId) || strpos($folderId, 'YOUR_') === 0) {
        echo "Skipping Google Drive #$driveIndex (Invalid or placeholder Folder ID).\n";
        $driveIndex++;
        continue;
    }

    echo "Uploading backup to Google Drive #$driveIndex (Folder ID '$folderId')...\n";
    
    $fileId = uploadToGoogleDrive($backupFilePath, $backupFilename, $accessToken, $folderId);
    if ($fileId) {
        echo "Successfully uploaded to Google Drive #$driveIndex! File ID: $fileId\n";
    } else {
        echo "Failed to upload to Google Drive #$driveIndex.\n";
    }
    $driveIndex++;
}

// Cleanup temporary backup file after finishing uploads
if (file_exists($backupFilePath)) {
    unlink($backupFilePath);
}

// HELPER FUNCTIONS // 

function base64UrlEncode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function getServiceAccountAccessToken($jsonFilePath) {
    if (!file_exists($jsonFilePath)) {
        die("Error: Service account JSON file not found at $jsonFilePath. Please place your google-service-account.json in the Admin folder.\n");
    }

    $config = json_decode(file_get_contents($jsonFilePath), true);
    if (!$config || !isset($config['private_key']) || !isset($config['client_email'])) {
        die("Error: Invalid Service Account JSON file structure.\n");
    }

    $privateKey = $config['private_key'];
    $clientEmail = $config['client_email'];
    $tokenUri = $config['token_uri'] ?? 'https://oauth2.googleapis.com/token';

    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $payload = json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => $tokenUri,
        'exp' => $now + 3600,
        'iat' => $now
    ]);

    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode($payload);
    $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;

    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $privateKey, 'SHA256')) {
        die("Error: Failed to sign JWT. Please check your PHP OpenSSL extension.\n");
    }

    $base64UrlSignature = base64UrlEncode($signature);
    $jwt = $signatureInput . '.' . $base64UrlSignature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUri);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        die("Error: Failed to fetch access token from Google. HTTP Code: $httpCode. Response: $response\n");
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? false;
}

function uploadToGoogleDrive($filePath, $fileName, $accessToken, $folderId) {
    if (!file_exists($filePath)) {
        return false;
    }

    $boundary = '-------' . uniqid();
    $metadata = [
        'name' => $fileName
    ];
    if (!empty($folderId)) {
        $metadata['parents'] = [$folderId];
    }

    $metadataJson = json_encode($metadata);
    $fileData = file_get_contents($filePath);

    $body = "--$boundary\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $metadataJson . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    $data = json_decode($response, true);
    return $data['id'] ?? false;
}
