<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";
include "../Admin/AdminNotifyHelper.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch admin details
$user_id = $_SESSION['user_id'];
$stmt = sqlsrv_prepare($conn, "SELECT username, profile_image FROM users WHERE id = ?", [$user_id]);
if ($stmt === false) {
    throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'Admin';
$profileImage = $user['profile_image'];
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifQuery = sqlsrv_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", [$user_id]);
if ($notifQuery === false) {
    throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($notifQuery)) {
    throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
}
$notifRow = sqlsrv_fetch_array($notifQuery, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Config file path
$configPath = __DIR__ . '/../backups_system/backup_config.php';
if (!file_exists($configPath)) {
    die("Error: Backup configuration file not found.");
}
$config = include $configPath;

// Helper to save configuration
function save_dashboard_config($config, $configPath)
{
    $newConfigContent = "<?php\n" .
        "/**\n" .
        " * Automated Backup Configuration File\n" .
        " * Generated/Updated by Admin Dashboard on " . date('Y-m-d H:i:s') . "\n" .
        " */\n\n" .
        "return [\n" .
        "    // --- System Executable Paths ---\n" .
        "    'php_bin'         => " . var_export($config['php_bin'] ?? 'C:\\\\xampp\\\\php\\\\php.exe', true) . ",\n" .
        "    'mysqldump_bin'   => " . var_export($config['mysqldump_bin'] ?? 'C:\\\\xampp\\\\mysql\\\\bin\\\\mysqldump.exe', true) . ",\n\n" .
        "    // --- Directory Settings ---\n" .
        "    'temp_dir'        => __DIR__ . '/temp/',\n" .
        "    'log_dir'         => __DIR__ . '/logs/',\n" .
        "    'log_file'        => __DIR__ . '/logs/backup.log',\n\n" .
        "    // --- Backup Naming ---\n" .
        "    'file_prefix'     => " . var_export($config['file_prefix'] ?? 'hms_backup_', true) . ",\n\n" .
        "    // --- Retention Settings ---\n" .
        "    'retention_days'      => " . (int) ($config['retention_days'] ?? 30) . ",\n" .
        "    'min_backups_to_keep' => " . (int) ($config['min_backups_to_keep'] ?? 5) . ",\n\n" .
        "    // --- Google Drive Integration Settings ---\n" .
        "    // Google Drive 1 (Primary)\n" .
        "    'gdrive1' => [\n" .
        "        'enabled'       => " . var_export($config['gdrive1']['enabled'] ?? false, true) . ",\n" .
        "        'client_id'     => " . var_export($config['gdrive1']['client_id'] ?? '', true) . ",\n" .
        "        'client_secret' => " . var_export($config['gdrive1']['client_secret'] ?? '', true) . ",\n" .
        "        'refresh_token' => " . var_export($config['gdrive1']['refresh_token'] ?? '', true) . ",\n" .
        "        'folder_id'     => " . var_export($config['gdrive1']['folder_id'] ?? '', true) . ",\n" .
        "    ],\n\n" .
        "    // Google Drive 2 (Secondary)\n" .
        "    'gdrive2' => [\n" .
        "        'enabled'       => " . var_export($config['gdrive2']['enabled'] ?? false, true) . ",\n" .
        "        'client_id'     => " . var_export($config['gdrive2']['client_id'] ?? '', true) . ",\n" .
        "        'client_secret' => " . var_export($config['gdrive2']['client_secret'] ?? '', true) . ",\n" .
        "        'refresh_token' => " . var_export($config['gdrive2']['refresh_token'] ?? '', true) . ",\n" .
        "        'folder_id'     => " . var_export($config['gdrive2']['folder_id'] ?? '', true) . ",\n" .
        "    ],\n" .
        "];\n";
    return file_put_contents($configPath, $newConfigContent) !== false;
}

// Handle Form Submissions & AJAX API Actions
$alertMessage = "";
$alertType = ""; // success, error, warning

// Action: Run Database Backup (AJAX Request)
if (isset($_GET['action']) && $_GET['action'] === 'run') {
    header('Content-Type: application/json');
    $phpBin = $config['php_bin'] ?? 'C:\\xampp\\php\\php.exe';
    $backupScript = __DIR__ . '/../backups_system/backup.php';

    if (!file_exists($phpBin)) {
        echo json_encode(['success' => false, 'output' => "Error: PHP binary not found at $phpBin\nVerify your backup settings."]);
        exit();
    }
    if (!file_exists($backupScript)) {
        echo json_encode(['success' => false, 'output' => "Error: Core backup script not found at $backupScript"]);
        exit();
    }

    // Command to execute
    $cmd = sprintf('"%s" -f "%s" 2>&1', $phpBin, $backupScript);

    // Execute command and capture output
    exec($cmd, $outputArray, $returnVar);
    $output = implode("\n", $outputArray);

    if ($returnVar === 0) {
        echo json_encode(['success' => true, 'output' => $output]);
    } else {
        echo json_encode(['success' => false, 'output' => $output]);
    }
    exit();
}

// Action: Fetch Latest Logs (AJAX Request)
if (isset($_GET['action']) && $_GET['action'] === 'get_logs') {
    header('Content-Type: application/json');
    $logFilePath = __DIR__ . '/../backups_system/logs/backup.log';
    $latestLogs = "No logs recorded yet.";
    if (file_exists($logFilePath)) {
        $logLines = file($logFilePath);
        if (!empty($logLines)) {
            $latestLines = array_slice($logLines, -40);
            $latestLogs = implode("", $latestLines);
        }
    }
    echo json_encode(['logs' => $latestLogs]);
    exit();
}

// Action: Save Credentials or Global Config (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_global_config'])) {
        $config['file_prefix'] = clean_input($_POST['file_prefix'] ?? 'hms_backup_');
        $config['retention_days'] = max(1, (int) ($_POST['retention_days'] ?? 30));
        $config['min_backups_to_keep'] = max(1, (int) ($_POST['min_backups_to_keep'] ?? 5));

        if (save_dashboard_config($config, $configPath)) {
            $alertMessage = "Global settings updated successfully!";
            $alertType = "success";
        } else {
            $alertMessage = "Failed to update backup_config.php. Check write permissions.";
            $alertType = "error";
        }
    }

    if (isset($_POST['save_drive_config'])) {
        $driveKey = $_POST['drive_key']; // gdrive1 or gdrive2
        if ($driveKey === 'gdrive1' || $driveKey === 'gdrive2') {
            $config[$driveKey]['client_id'] = trim($_POST['client_id'] ?? '');
            $config[$driveKey]['client_secret'] = trim($_POST['client_secret'] ?? '');
            $config[$driveKey]['folder_id'] = trim($_POST['folder_id'] ?? '');
            $config[$driveKey]['enabled'] = isset($_POST['enabled']) ? true : false;

            // Allow manual pasting of a refresh token
            if (!empty($_POST['refresh_token'])) {
                $config[$driveKey]['refresh_token'] = trim($_POST['refresh_token']);
            }

            if (save_dashboard_config($config, $configPath)) {
                $driveName = $driveKey === 'gdrive1' ? 'Primary' : 'Secondary';
                $alertMessage = "Google Drive ($driveName) settings updated!";
                $alertType = "success";
            } else {
                $alertMessage = "Failed to save Google Drive settings.";
                $alertType = "error";
            }
        }
    }

    if (isset($_POST['authorize_drive'])) {
        $driveKey = $_POST['drive_key']; // gdrive1 or gdrive2
        $authCode = trim($_POST['auth_code'] ?? '');

        if (($driveKey === 'gdrive1' || $driveKey === 'gdrive2') && !empty($authCode)) {
            $clientId = $config[$driveKey]['client_id'];
            $clientSecret = $config[$driveKey]['client_secret'];

            if (empty($clientId) || empty($clientSecret)) {
                $alertMessage = "Client ID and Client Secret must be configured and saved first.";
                $alertType = "error";
            } else {
                // Exchange Auth Code
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'code' => $authCode,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => 'http://localhost',
                    'grant_type' => 'authorization_code'
                ]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $tokenData = json_decode($response, true);
                    $refreshToken = $tokenData['refresh_token'] ?? null;

                    if ($refreshToken) {
                        $config[$driveKey]['refresh_token'] = $refreshToken;
                        $config[$driveKey]['enabled'] = true;

                        if (save_dashboard_config($config, $configPath)) {
                            $driveName = $driveKey === 'gdrive1' ? 'Primary' : 'Secondary';
                            $alertMessage = "Authorization Successful! Google Drive ($driveName) is now fully enabled.";
                            $alertType = "success";
                        } else {
                            $alertMessage = "Tokens exchanged, but failed to save to config.";
                            $alertType = "error";
                        }
                    } else {
                        $alertMessage = "Success response, but no Refresh Token was returned by Google. If you authorized before, go to Google security settings, remove application access and re-auth, or paste your refresh token manually.";
                        $alertType = "warning";
                    }
                } else {
                    $errResponse = json_decode($response, true);
                    $errMsg = $errResponse['error_description'] ?? 'Check your code and credentials.';
                    $alertMessage = "OAuth Token Exchange Failed (HTTP $httpCode): " . htmlspecialchars($errMsg);
                    $alertType = "error";
                }
            }
        } else {
            $alertMessage = "Authorization code cannot be empty.";
            $alertType = "error";
        }
    }
}

// Fetch stats for view
$tempBackupDir = __DIR__ . '/../backups_system/temp/';
$localBackupCount = 0;
$localBackupTotalSize = 0;
if (is_dir($tempBackupDir)) {
    $backupFiles = glob($tempBackupDir . ($config['file_prefix'] ?? 'hms_backup_') . '*.zip');
    if ($backupFiles) {
        $localBackupCount = count($backupFiles);
        foreach ($backupFiles as $file) {
            if (file_exists($file)) {
                $localBackupTotalSize += filesize($file);
            }
        }
    }
}

function format_bytes_dashboard($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
$localBackupSizeStr = format_bytes_dashboard($localBackupTotalSize);

// Database size info (SQL Server)
$dbSizeSql = "SELECT CAST(SUM(size) * 8.0 / 1024 AS DECIMAL(10,2)) AS size_mb FROM sys.database_files WHERE type_desc='ROWS'";
$dbSizeResult = sqlsrv_query($conn, $dbSizeSql);
if ($dbSizeResult === false) {
    throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
}
$dbSizeRow = sqlsrv_fetch_array($dbSizeResult, SQLSRV_FETCH_ASSOC);
$dbSizeMb = $dbSizeRow['size_mb'] ?? 'Unknown';

// Load logs
$logFilePath = __DIR__ . '/../backups_system/logs/backup.log';
$latestLogs = "No logs recorded yet.";
if (file_exists($logFilePath)) {
    $logLines = file($logFilePath);
    if (!empty($logLines)) {
        $latestLines = array_slice($logLines, -40);
        $latestLogs = htmlspecialchars(implode("", $latestLines));
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Database Backup & Cloud Sync - Admin HMS</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --primary-light: #00a8c6;
            --secondary: #0f172a;
            --bg-body: #f8fafc;
            --text-muted: #64748b;
            --glass: rgba(255, 255, 255, 0.85);
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
            --sidebar-width: 270px;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-body);
            color: var(--secondary);
            min-height: 100vh;
        }

        /* Side bar */
        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: #94a3b8;
            padding: 2rem 1.5rem;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.05);
        }

        .sidebar .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
            padding-left: 0.5rem;
        }

        .sidebar .logo img {
            width: 55px;
            height: 55px;
            border-radius: 10px;
            object-fit: contain;
        }

        .sidebar .logo h1 {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(90deg, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .menu {
            list-style: none;
        }

        .menu li {
            margin-bottom: 0.25rem;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.7rem 1.25rem;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.25s ease;
            font-size: 0.95rem;
        }

        .menu a:hover {
            color: white;
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(4px);
        }

        .menu a.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 124, 145, 0.35);
        }

        .menu i {
            font-size: 1rem;
            width: 22px;
            text-align: center;
        }

        .menu .logout {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.07);
        }

        .menu .logout a {
            color: #f87171;
            background: rgba(248, 113, 113, 0.06);
        }

        .menu .logout a:hover {
            background: #ef4444;
            color: white;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        header {
            background: var(--glass);
            backdrop-filter: blur(10px);
            padding: 1rem 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 900;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        header h2 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--secondary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .notification-bell {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            text-decoration: none;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .notification-bell:hover {
            background: var(--bg-body);
            color: var(--primary);
            transform: scale(1.05);
        }

        .notification-count {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            padding: 0 4px;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.4rem 1rem;
            background: #fff;
            border-radius: 100px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
        }

        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
        }

        .profile-btn span {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .content {
            padding: 2rem 2.5rem;
            flex-grow: 1;
        }

        /* Banner Hero */
        .welcome-hero {
            background: linear-gradient(135deg, #007c91 0%, #005662 100%);
            border-radius: 24px;
            padding: 2.5rem;
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px rgba(0, 124, 145, 0.15);
        }

        .welcome-hero::after {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .welcome-hero h2 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .welcome-hero p {
            font-size: 1rem;
            opacity: 0.9;
            max-width: 600px;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }

        /* Alert styling */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid transparent;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            color: #065f46;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.12);
            color: #991b1b;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.12);
            color: #92400e;
            border-color: rgba(245, 158, 11, 0.2);
        }

        /* Layout Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 2rem;
            align-items: start;
        }

        @media (max-width: 1100px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: white;
            border-radius: 24px;
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid #f1f5f9;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 1rem;
        }

        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h3 i {
            color: var(--primary);
        }

        /* Stats Blocks */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-box {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 16px;
            padding: 1.2rem;
            text-align: center;
            transition: all 0.3s;
        }

        .stat-box:hover {
            border-color: var(--primary-light);
            background: #fff;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--secondary);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Action Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0.8rem 1.8rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            border: none;
            transition: all 0.25s ease;
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 124, 145, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 124, 145, 0.45);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--secondary);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn:disabled {
            background: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Google Drive Cards */
        .gdrive-card {
            border: 1px solid #f1f5f9;
            background: #fff;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .gdrive-card.enabled {
            border-left: 5px solid var(--success);
        }

        .gdrive-card.disabled {
            border-left: 5px solid var(--text-muted);
        }

        .gdrive-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .gdrive-title {
            font-weight: 700;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-pill {
            font-size: 0.75rem;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 100px;
            text-transform: uppercase;
        }

        .pill-enabled {
            background: rgba(16, 185, 129, 0.12);
            color: var(--success);
        }

        .pill-disabled {
            background: rgba(100, 116, 139, 0.12);
            color: var(--text-muted);
        }

        /* Forms UI */
        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #475569;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-family: inherit;
            font-size: 0.9rem;
            color: var(--secondary);
            background-color: #f8fafc;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(0, 124, 145, 0.15);
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .checkbox-container input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        /* Terminal Console */
        .terminal-container {
            margin-top: 1rem;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #1e293b;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.15);
        }

        .terminal-header {
            background: #1e293b;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .terminal-actions {
            display: flex;
            gap: 8px;
        }

        .terminal-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.85rem;
            transition: color 0.2s;
        }

        .terminal-btn:hover {
            color: #fff;
        }

        .terminal-dots {
            display: flex;
            gap: 6px;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .dot-red {
            background: #ef4444;
        }

        .dot-yellow {
            background: #f59e0b;
        }

        .dot-green {
            background: #10b981;
        }

        .terminal-body {
            background: #0f172a;
            color: #38bdf8;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            padding: 1.5rem;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            line-height: 1.5;
        }

        .terminal-body::-webkit-scrollbar {
            width: 8px;
        }

        .terminal-body::-webkit-scrollbar-track {
            background: #0f172a;
        }

        .terminal-body::-webkit-scrollbar-thumb {
            background: #1e293b;
            border-radius: 4px;
        }

        /* Instruction Box */
        .auth-instructions {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 1rem;
            font-size: 0.82rem;
            line-height: 1.5;
            color: #475569;
            margin-bottom: 1.2rem;
        }

        .auth-instructions ol {
            padding-left: 20px;
            margin-top: 5px;
        }

        .auth-instructions li {
            margin-bottom: 4px;
        }

        .auth-link {
            color: var(--primary);
            font-weight: 700;
            text-decoration: underline;
            word-break: break-all;
        }

        .footer {
            background: #ffffff;
            padding: 40px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            margin-top: 3rem;
        }

        .footer img {
            width: 50px;
            margin-bottom: 12px;
        }

        .footer h3 {
            color: #1e2a38;
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .footer p {
            color: #64748b;
            font-size: 0.9rem;
        }

        /* Loader animation styles */
        .spinner {
            animation: rotate 2s linear infinite;
            width: 20px;
            height: 20px;
            display: inline-block;
        }

        .spinner .path {
            stroke: #ffffff;
            stroke-linecap: round;
            animation: dash 1.5s ease-in-out infinite;
        }

        @keyframes rotate {
            100% {
                transform: rotate(360deg);
            }
        }

        @keyframes dash {
            0% {
                stroke-dasharray: 1, 150;
                stroke-dashoffset: 0;
            }

            50% {
                stroke-dasharray: 90, 150;
                stroke-dashoffset: -35;
            }

            100% {
                stroke-dasharray: 90, 150;
                stroke-dashoffset: -124;
            }
        }
    </style>
</head>

<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='Admindash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="Admindash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="assignIssues.php"><i class="fas fa-user-cog"></i> Assign Issues</a></li>
                <li><a href="approveParts.php"><i class="fas fa-check-circle"></i> Approve Parts</a></li>
                <li><a href="issueReport.php"><i class="fas fa-file-alt"></i> Issue Reports</a></li>
                <li><a href="manageUsers.php"><i class="fas fa-users"></i> Manage Users</a></li>
                <li><a href="adminManageInventory.php"><i class="fas fa-boxes"></i> Manage Inventory</a></li>
                <li><a href="storeReport.php"><i class="fas fa-warehouse"></i> Store Reports</a></li>
                <li><a href="technicianEfficiency.php"><i class="fas fa-chart-line"></i> Technician Efficiency</a></li>
                <li><a href="costReport.php"><i class="fas fa-coins"></i> Cost Report</a></li>
                <li><a href="purchaseRequest.php"><i class="fas fa-shopping-cart"></i> Purchase Requests</a></li>


                <li class="logout">
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Body -->
        <main class="main-content">
            <header>
                <h2>Backup & Sync Settings</h2>
                <div class="header-right">
                    <a href="adminNotifications.php" class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-count"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>

                    <div class="profile-btn" onclick="window.location.href='../userprofile.php';">
                        <?php if ($hasProfileImage): ?>
                            <img src="../uploads/<?= htmlspecialchars($profileImage); ?>" class="profile-pic">
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size: 1.2rem; color: #64748b;"></i>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </header>

            <div class="content">
                <!-- Welcome/Status Hero -->
                <div class="welcome-hero">
                    <h2>Database Backup Management Panel</h2>
                    <p>Trigger instant manual database backups, configure automated retention limits, and manage
                        authentication for up to two independent Google Drives for secure offsite cloud storage
                        redundancy.</p>
                </div>

                <!-- Action Alerts -->
                <?php if (!empty($alertMessage)): ?>
                    <div class="alert alert-<?= $alertType ?>">
                        <?php if ($alertType === 'success'): ?>
                            <i class="fas fa-check-circle" style="font-size: 1.3rem;"></i>
                        <?php elseif ($alertType === 'error'): ?>
                            <i class="fas fa-times-circle" style="font-size: 1.3rem;"></i>
                        <?php else: ?>
                            <i class="fas fa-exclamation-triangle" style="font-size: 1.3rem;"></i>
                        <?php endif; ?>
                        <span><?= $alertMessage ?></span>
                    </div>
                <?php endif; ?>

                <div class="dashboard-grid">

                    <div>

                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-server"></i> System Backup Status</h3>
                            </div>

                            <div class="quick-stats">
                                <div class="stat-box">
                                    <div class="stat-value"><?= htmlspecialchars($dbSizeMb) ?> MB</div>
                                    <div class="stat-label">Active DB Size</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value"><?= $localBackupCount ?></div>
                                    <div class="stat-label">Local Backups</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value"><?= $localBackupSizeStr ?></div>
                                    <div class="stat-label">Archive Space</div>
                                </div>
                            </div>

                            <div style="margin-top: 1rem;">
                                <button type="button" class="btn btn-primary" id="btnRunBackup"
                                    onclick="triggerManualBackup()">
                                    <i class="fas fa-bolt"></i> <span id="btnText">Run Database Backup & Sync Now</span>
                                </button>
                            </div>
                        </div>

                        <!-- Terminal log output -->
                        <div class="card" style="padding: 1.5rem;">
                            <div class="card-header" style="margin-bottom: 0.8rem; padding-bottom: 0.8rem;">
                                <h3><i class="fas fa-terminal"></i> Live Backup Engine Console</h3>
                                <div class="terminal-actions">
                                    <button class="terminal-btn" title="Refresh Log" onclick="refreshConsoleLogs()"><i
                                            class="fas fa-sync"></i> Refresh</button>
                                </div>
                            </div>

                            <div class="terminal-container">
                                <div class="terminal-header">
                                    <div class="terminal-dots">
                                        <span class="dot dot-red"></span>
                                        <span class="dot dot-yellow"></span>
                                        <span class="dot dot-green"></span>
                                    </div>
                                    <span>backup_engine.log</span>
                                </div>
                                <div class="terminal-body" id="consoleLogs"><?= $latestLogs ?></div>
                            </div>
                        </div>

                        <!-- Global config settings -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fas fa-cog"></i> Global Archiving Rules</h3>
                            </div>
                            <form method="post" action="triggerBackup.php">
                                <input type="hidden" name="save_global_config" value="1">
                                <div class="form-group">
                                    <label>Backup File Prefix</label>
                                    <input type="text" name="file_prefix" class="form-control"
                                        value="<?= htmlspecialchars($config['file_prefix'] ?? 'hms_backup_') ?>"
                                        required>
                                </div>
                                <div class="quick-stats" style="grid-template-columns: 1fr 1fr; margin-bottom: 1.2rem;">
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>Retention Window (Days)</label>
                                        <input type="number" name="retention_days" class="form-control"
                                            value="<?= (int) ($config['retention_days'] ?? 30) ?>" min="1" required>
                                    </div>
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>Safety Keep Limit (Count)</label>
                                        <input type="number" name="min_backups_to_keep" class="form-control"
                                            value="<?= (int) ($config['min_backups_to_keep'] ?? 5) ?>" min="1" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save Global
                                    Rules</button>
                            </form>
                        </div>
                    </div>

                    <!-- Column Right: Google Drives configurations -->
                    <div>
                        <!-- Google Drive 1 (Primary) -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fab fa-google-drive"></i> Google Drive 1 (Primary)</h3>
                                <span
                                    class="status-pill <?= ($config['gdrive1']['enabled'] ?? false) ? 'pill-enabled' : 'pill-disabled' ?>">
                                    <?= ($config['gdrive1']['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </div>

                            <form method="post" action="triggerBackup.php">
                                <input type="hidden" name="save_drive_config" value="1">
                                <input type="hidden" name="drive_key" value="gdrive1">

                                <label class="checkbox-container">
                                    <input type="checkbox" name="enabled" value="1" <?= ($config['gdrive1']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    Enable Primary Drive Uploads
                                </label>

                                <div class="form-group">
                                    <label>Client ID</label>
                                    <input type="text" name="client_id" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive1']['client_id'] ?? '') ?>"
                                        placeholder="Google API Client ID">
                                </div>
                                <div class="form-group">
                                    <label>Client Secret</label>
                                    <input type="password" name="client_secret" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive1']['client_secret'] ?? '') ?>"
                                        placeholder="Google API Client Secret">
                                </div>
                                <div class="form-group">
                                    <label>Destination Folder ID (Optional)</label>
                                    <input type="text" name="folder_id" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive1']['folder_id'] ?? '') ?>"
                                        placeholder="Root Drive if blank">
                                </div>
                                <div class="form-group">
                                    <label>Refresh Token (Auto-generated or Manual Paste)</label>
                                    <input type="text" name="refresh_token" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive1']['refresh_token'] ?? '') ?>"
                                        placeholder="Paste token if already obtained">
                                </div>
                                <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save
                                    Credentials</button>
                            </form>

                            <!-- OAuth Assistant Slot 1 -->
                            <?php if (!empty($config['gdrive1']['client_id']) && !empty($config['gdrive1']['client_secret'])): ?>
                                <div style="margin-top: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1.2rem;">
                                    <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.5rem;"><i
                                            class="fas fa-key" style="color: var(--warning)"></i> OAuth 2.0 Authorization
                                        Assistant</h4>

                                    <div class="auth-instructions">
                                        1. Click this link to open authorization screen in a new tab:<br>
                                        <?php
                                        $authUrl1 = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
                                            'client_id' => $config['gdrive1']['client_id'],
                                            'redirect_uri' => 'http://localhost',
                                            'response_type' => 'code',
                                            'scope' => 'https://www.googleapis.com/auth/drive.file',
                                            'access_type' => 'offline',
                                            'prompt' => 'consent'
                                        ]);
                                        ?>
                                        <a href="<?= $authUrl1 ?>" target="_blank" class="auth-link">Authorize Primary Drive
                                            <i class="fas fa-external-link-alt"></i></a><br>
                                        2. Sign in and approve security permissions.<br>
                                        3. Copy the <strong>code=...</strong> parameter from the URL address bar of the page
                                        that loads (or redirects to http://localhost).<br>
                                        4. Paste it in the field below and verify.
                                    </div>

                                    <form method="post" action="triggerBackup.php">
                                        <input type="hidden" name="authorize_drive" value="1">
                                        <input type="hidden" name="drive_key" value="gdrive1">
                                        <div class="form-group">
                                            <label>Authorization Code</label>
                                            <input type="text" name="auth_code" class="form-control"
                                                placeholder="Pasted code starting with '4/...' or full redirect URL"
                                                required>
                                        </div>
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Verify &
                                            Authorize</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Google Drive 2 (Secondary) -->
                        <div class="card">
                            <div class="card-header">
                                <h3><i class="fab fa-google-drive"></i> Google Drive 2 (Secondary)</h3>
                                <span
                                    class="status-pill <?= ($config['gdrive2']['enabled'] ?? false) ? 'pill-enabled' : 'pill-disabled' ?>">
                                    <?= ($config['gdrive2']['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </div>

                            <form method="post" action="triggerBackup.php">
                                <input type="hidden" name="save_drive_config" value="1">
                                <input type="hidden" name="drive_key" value="gdrive2">

                                <label class="checkbox-container">
                                    <input type="checkbox" name="enabled" value="1" <?= ($config['gdrive2']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    Enable Secondary Drive Uploads
                                </label>

                                <div class="form-group">
                                    <label>Client ID</label>
                                    <input type="text" name="client_id" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive2']['client_id'] ?? '') ?>"
                                        placeholder="Google API Client ID">
                                </div>
                                <div class="form-group">
                                    <label>Client Secret</label>
                                    <input type="password" name="client_secret" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive2']['client_secret'] ?? '') ?>"
                                        placeholder="Google API Client Secret">
                                </div>
                                <div class="form-group">
                                    <label>Destination Folder ID (Optional)</label>
                                    <input type="text" name="folder_id" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive2']['folder_id'] ?? '') ?>"
                                        placeholder="Root Drive if blank">
                                </div>
                                <div class="form-group">
                                    <label>Refresh Token (Auto-generated or Manual Paste)</label>
                                    <input type="text" name="refresh_token" class="form-control"
                                        value="<?= htmlspecialchars($config['gdrive2']['refresh_token'] ?? '') ?>"
                                        placeholder="Paste token if already obtained">
                                </div>
                                <button type="submit" class="btn btn-secondary"><i class="fas fa-save"></i> Save
                                    Credentials</button>
                            </form>

                            <!-- OAuth Assistant Slot 2 -->
                            <?php if (!empty($config['gdrive2']['client_id']) && !empty($config['gdrive2']['client_secret'])): ?>
                                <div style="margin-top: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1.2rem;">
                                    <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.5rem;"><i
                                            class="fas fa-key" style="color: var(--warning)"></i> OAuth 2.0 Authorization
                                        Assistant</h4>

                                    <div class="auth-instructions">
                                        1. Click this link to open authorization screen in a new tab:<br>
                                        <?php
                                        $authUrl2 = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
                                            'client_id' => $config['gdrive2']['client_id'],
                                            'redirect_uri' => 'http://localhost',
                                            'response_type' => 'code',
                                            'scope' => 'https://www.googleapis.com/auth/drive.file',
                                            'access_type' => 'offline',
                                            'prompt' => 'consent'
                                        ]);
                                        ?>
                                        <a href="<?= $authUrl2 ?>" target="_blank" class="auth-link">Authorize Secondary
                                            Drive <i class="fas fa-external-link-alt"></i></a><br>
                                        2. Sign in and approve security permissions.<br>
                                        3. Copy the <strong>code=...</strong> parameter from the URL address bar of the page
                                        that loads (or redirects to http://localhost).<br>
                                        4. Paste it in the field below and verify.
                                    </div>

                                    <form method="post" action="triggerBackup.php">
                                        <input type="hidden" name="authorize_drive" value="1">
                                        <input type="hidden" name="drive_key" value="gdrive2">
                                        <div class="form-group">
                                            <label>Authorization Code</label>
                                            <input type="text" name="auth_code" class="form-control"
                                                placeholder="Pasted code starting with '4/...' or full redirect URL"
                                                required>
                                        </div>
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Verify &
                                            Authorize</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Section -->
            <footer class="footer">
                <div class="container" style="padding: 0; text-align: center;">
                    <img src="../images/hardware_logo.png" alt="Logo">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem; color: #64748b;">&copy; <?= date('Y'); ?> Electricity Distribution Lanka
                        (Private) Limited</p>
                    <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5; color: #64748b;">Helping you secure and
                        recover your critical systems.</p>
                </div>
            </footer>
        </main>
    </div>

    <!-- JavaScript Actions -->
    <script>
        function triggerManualBackup() {
            const btn = document.getElementById('btnRunBackup');
            const btnText = document.getElementById('btnText');

            btn.disabled = true;
            btnText.innerHTML = 'Running database export & cloud sync...';

            // Inject spinner
            const origIcon = btn.querySelector('i');
            const spinner = document.createElement('span');
            spinner.className = 'spinner';
            spinner.innerHTML = '<svg class="spinner" viewBox="0 0 50 50"><circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle></svg>';
            btn.replaceChild(spinner, origIcon);

            const consoleLogs = document.getElementById('consoleLogs');
            consoleLogs.innerText += "\n[System] Directing console output... backup process started.\n";
            consoleLogs.scrollTop = consoleLogs.scrollHeight;

            fetch('triggerBackup.php?action=run')
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btnText.innerHTML = 'Run Database Backup & Sync Now';

                    const newIcon = document.createElement('i');
                    newIcon.className = 'fas fa-bolt';
                    btn.replaceChild(newIcon, spinner);

                    if (data.success) {
                        consoleLogs.innerText = data.output;
                        alert("Backup completed successfully! See engine console logs below for complete details.");
                    } else {
                        consoleLogs.innerText = data.output;
                        alert("Backup run encountered errors. Please review the engine logs.");
                    }
                    consoleLogs.scrollTop = consoleLogs.scrollHeight;
                })
                .catch(error => {
                    btn.disabled = false;
                    btnText.innerHTML = 'Run Database Backup & Sync Now';
                    const newIcon = document.createElement('i');
                    newIcon.className = 'fas fa-bolt';
                    btn.replaceChild(newIcon, spinner);

                    consoleLogs.innerText += "\n[Error SAPI] AJAX Network/Process execution failed: " + error + "\n";
                    consoleLogs.scrollTop = consoleLogs.scrollHeight;
                    alert("A communication error occurred when attempting to trigger the backup script.");
                });
        }

        function refreshConsoleLogs() {
            const consoleLogs = document.getElementById('consoleLogs');
            fetch('triggerBackup.php?action=get_logs')
                .then(response => response.json())
                .then(data => {
                    consoleLogs.innerText = data.logs;
                    consoleLogs.scrollTop = consoleLogs.scrollHeight;
                });
        }

        // Auto-scroll terminal log to bottom on page load
        window.onload = function () {
            const consoleLogs = document.getElementById('consoleLogs');
            if (consoleLogs) {
                consoleLogs.scrollTop = consoleLogs.scrollHeight;
            }
        };
    </script>
</body>

</html>