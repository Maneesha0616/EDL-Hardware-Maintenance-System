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

$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$stmt = sqlsrv_prepare($conn, $sql, array($user_id));
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'Admin';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifSql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt = sqlsrv_prepare($conn, $notifSql, array($user_id));
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
sqlsrv_execute($stmt);
$notifRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Fetch Stats for Dashboard
$stats = [];

// Total Users
$sql = "SELECT COUNT(*) as total FROM users";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$stats['users'] = $row['total'] ?? 0;

// Pending Issues
$sql = "SELECT COUNT(*) as total FROM issues WHERE status = 'Pending'";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$stats['pending_issues'] = $row['total'] ?? 0;

// Assigned Issues
$sql = "SELECT COUNT(*) as total FROM issues WHERE status = 'Assigned'";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$stats['assigned_issues'] = $row['total'] ?? 0;

// Completed Issues
$sql = "SELECT COUNT(*) as total FROM issues WHERE status = 'Completed'";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$stats['completed_issues'] = $row['total'] ?? 0;

// Low Stock Items
$lowStockCount = 0;
$stockSql = "SELECT COUNT(*) AS cnt FROM sys.tables WHERE name = 'items'";
$stockStmt = sqlsrv_query($conn, $stockSql);
if ($stockStmt !== false) {
    $stockRow = sqlsrv_fetch_array($stockStmt, SQLSRV_FETCH_ASSOC);
    if (($stockRow['cnt'] ?? 0) > 0) {
        $sql = "SELECT COUNT(*) as total FROM items WHERE quantity <= COALESCE(low_stock_threshold, 5)";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $lowStockCount = $row['total'] ?? 0;
        }
    }
}
$stats['low_stock'] = $lowStockCount;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | HMS</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --secondary: #1e2a38;
            --text-muted: #94a3b8;
            --sidebar-width: 260px;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --glass: rgba(255, 255, 255, 0.8);
            --radius: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            overflow-x: hidden;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--secondary) 0%, #0f172a 100%);
            color: white;
            padding: 1.5rem 1.25rem;
            position: fixed;
            height: 100vh;
            overflow-y: hidden;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.12);
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
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
            background: transparent;
            object-fit: contain;
        }

        .sidebar .logo h1 {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(90deg, #fff, var(--text-muted));
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
            color: var(--text-muted);
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

        /* MAIN CONTENT AREA */
        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* HEADER STYLING - Using .header-right, .notification-bell, .profile-btn */
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

        .profile-btn:hover {
            border-color: var(--primary);
            background: #fafafa;
        }

        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .profile-btn span {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .dropdown {
            display: none;
            position: absolute;
            right: 2.5rem;
            top: 5rem;
            background: #fff;
            width: 200px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            z-index: 1000;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: 0.2s;
        }

        .dropdown a:hover {
            background: #f8fafc;
            color: var(--primary);
            padding-left: 25px;
        }

        /* DASHBOARD CONTENT */
        .content {
            padding: 2rem 2.5rem;
            flex-grow: 1;
        }

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
            opacity: 0.8;
            max-width: 500px;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }

        .hero-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.25rem;
            position: relative;
            z-index: 1;
        }

        .hero-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.6rem 1.25rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.25s ease;
            border: 1.5px solid rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(4px);
        }

        .hero-btn-backup {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .hero-btn-backup:hover {
            background: rgba(255, 255, 255, 0.28);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .hero-btn-logs {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .hero-btn-logs:hover {
            background: rgba(255, 255, 255, 0.28);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border: 1px solid rgba(0, 124, 145, 0.2);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-info .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--secondary);
            line-height: 1.2;
        }

        .stat-info .stat-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        .icon-users {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .icon-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .icon-ongoing {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .icon-stock {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .dashboard-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
        }

        .data-card {
            background: white;
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-header h2 {
            font-size: 1.125rem;
            font-weight: 800;
            color: var(--secondary);
        }

        .card-action {
            font-size: 0.875rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
        }

        .card-action:hover {
            text-decoration: underline;
        }

        .list-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .list-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 12px;
            background: #f8fafc;
            transition: background 0.2s;
        }

        .list-item:hover {
            background: #f1f5f9;
        }

        .item-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--primary);
            border: 1px solid #e2e8f0;
        }

        .item-content {
            flex-grow: 1;
        }

        .item-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .item-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .item-status {
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.25rem 0.75rem;
            border-radius: 100px;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }

        .footer {
            background: #ffffff;
            padding: 40px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            margin-top: auto;
        }

        .footer img {
            width: 50px;
            height: 50px;
            border-radius: 10px;
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

        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <div class="layout">
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='Admindash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a class="active" href="Admindash.php"><i class="fas fa-home"></i> Dashboard</a></li>
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


        <main class="main-content">
            <header>
                <h2>Admin Dashboard</h2>
                <div class="header-right">
                    <a href="adminNotifications.php" class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-count"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>

                    <button class="profile-btn" onclick="toggleDropdown()">
                        <?php if ($hasProfileImage): ?>
                            <img src="../uploads/<?= htmlspecialchars($profileImage); ?>" class="profile-pic">
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size: 1.2rem; color: #64748b;"></i>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($username); ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 0.8rem; color: #64748b;"></i>
                    </button>

                    <div class="dropdown" id="profileDropdown">
                        <a href="../userprofile.php"><i class="fas fa-user"></i> Profile</a>
                        <a href="../logout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </header>

            <div class="content">
                <?php if (isset($_SESSION['drive_success'])): ?>
                    <div
                        style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #065f46; padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-check-circle" style="color: var(--success); font-size: 1.2rem;"></i>
                        <span><?php echo $_SESSION['drive_success'];
                        unset($_SESSION['drive_success']); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Welcome Hero -->
                <div class="welcome-hero">
                    <h2>Welcome back, <?php echo $username; ?>!</h2>
                    <p>Monitor your management system performance, track maintenance issues, and manage user roles all
                        in one place.</p>
                    <div class="hero-actions">
                        <a href="triggerBackup.php" class="hero-btn hero-btn-backup">
                            <i class="fas fa-database"></i> Backup System
                        </a>
                        <a href="systemLogs.php" class="hero-btn hero-btn-logs">
                            <i class="fas fa-history"></i> System Logs
                        </a>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <a href="manageUsers.php" class="stat-card">
                        <div class="stat-icon icon-users"><i class="fas fa-users"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $stats['users']; ?></div>
                            <div class="stat-label">Total Users</div>
                        </div>
                    </a>

                    <a href="assignIssues.php" class="stat-card">
                        <div class="stat-icon icon-pending"><i class="fas fa-clock"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $stats['pending_issues']; ?></div>
                            <div class="stat-label">Pending Issues</div>
                        </div>
                    </a>

                    <a href="issueReport.php" class="stat-card">
                        <div class="stat-icon icon-ongoing"><i class="fas fa-spinner"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $stats['assigned_issues']; ?></div>
                            <div class="stat-label">Assigned Issues</div>
                        </div>
                    </a>

                    <a href="storeReport.php" class="stat-card">
                        <div class="stat-icon icon-stock"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $stats['low_stock']; ?></div>
                            <div class="stat-label">Low Stock Items</div>
                        </div>
                    </a>
                </div>

                <!-- Visual Dashboard Row -->
                <div class="dashboard-row">
                    <!-- Maintenance Performance Chart -->
                    <div class="data-card">
                        <div class="card-header">
                            <h2>Maintenance Status Overview</h2>
                        </div>
                        <canvas id="maintenanceChart" style="max-height: 250px;"></canvas>
                    </div>

                    <!-- Recent Activity / System Log -->
                    <div class="data-card">
                        <div class="card-header">
                            <h2>Recent System Activity</h2>
                            <a href="systemLogs.php" class="card-action">View All</a>
                        </div>
                        <div class="list-container">
                            <?php
                            // Helper function to map activity category attributes
                            if (!function_exists('get_activity_category')) {
                                function get_activity_category($action)
                                {
                                    $action_lower = strtolower($action);
                                    if (strpos($action_lower, 'login') !== false || strpos($action_lower, 'logout') !== false || strpos($action_lower, 'failed') !== false) {
                                        return [
                                            'label' => 'Auth',
                                            'icon' => 'fas fa-shield-alt',
                                            'bg' => 'rgba(59, 130, 246, 0.1)',
                                            'color' => '#2563eb'
                                        ];
                                    } elseif (strpos($action_lower, 'backup') !== false) {
                                        return [
                                            'label' => 'System',
                                            'icon' => 'fas fa-database',
                                            'bg' => 'rgba(139, 92, 246, 0.1)',
                                            'color' => '#7c3aed'
                                        ];
                                    } elseif (strpos($action_lower, 'user') !== false) {
                                        return [
                                            'label' => 'User Mgmt',
                                            'icon' => 'fas fa-users-cog',
                                            'bg' => 'rgba(16, 185, 129, 0.1)',
                                            'color' => '#059669'
                                        ];
                                    } elseif (strpos($action_lower, 'inventory') !== false || strpos($action_lower, 'stock') !== false || strpos($action_lower, 'item') !== false) {
                                        return [
                                            'label' => 'Inventory',
                                            'icon' => 'fas fa-boxes',
                                            'bg' => 'rgba(245, 158, 11, 0.1)',
                                            'color' => '#d97706'
                                        ];
                                    } else {
                                        return [
                                            'label' => 'Maintenance',
                                            'icon' => 'fas fa-wrench',
                                            'bg' => 'rgba(239, 68, 68, 0.1)',
                                            'color' => '#dc2626'
                                        ];
                                    }
                                }
                            }

                            // Fetch 4 most recent system logs
                            $activitySql = "SELECT TOP 4 sl.action, sl.details, sl.created_at, u.username 
                                            FROM system_logs sl 
                                            LEFT JOIN users u ON sl.user_id = u.id 
                                            ORDER BY sl.created_at DESC";
                            $activities = sqlsrv_query($conn, $activitySql);

                            if ($activities && sqlsrv_has_rows($activities)):
                                while ($act = sqlsrv_fetch_array($activities, SQLSRV_FETCH_ASSOC)):
                                    $cat = get_activity_category($act['action']);
                                    ?>
                                    <div class="list-item">
                                        <div class="item-icon"
                                            style="color: <?= $cat['color']; ?>; background: <?= $cat['bg']; ?>; display: flex; align-items: center; justify-content: center;">
                                            <i class="<?= $cat['icon']; ?>"></i>
                                        </div>
                                        <div class="item-content">
                                            <div class="item-title"
                                                style="font-weight: 600; font-size: 0.85rem; color: #1e293b;">
                                                <?= htmlspecialchars($act['action']); ?>
                                            </div>
                                            <div class="item-meta" style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                                <?= htmlspecialchars($act['details'] ?? ''); ?>
                                                <span
                                                    style="display: block; font-size: 0.7rem; color: #94a3b8; margin-top: 1px;">
                                                    By: <?= htmlspecialchars($act['username'] ?? 'System'); ?> &bull;
                                                    <?= is_object($act['created_at']) ? $act['created_at']->format('M d, H:i') : date('M d, H:i', strtotime($act['created_at'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <span class="item-status"
                                            style="background: <?= $cat['bg']; ?>; color: <?= $cat['color']; ?>; font-size: 0.7rem; font-weight: 700; padding: 4px 8px; border-radius: 8px; white-space: nowrap;">
                                            <?= htmlspecialchars($cat['label']); ?>
                                        </span>
                                    </div>
                              <?php endwhile; ?>
<?php else: ?>
    <p style="text-align:center; color:var(--text-muted); padding:1rem;">No recent activity recorded.</p>
<?php endif; ?>
                         </div>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <div class="container" style="padding: 0; text-align: center;">
                    <img src="../images/hardware_logo.png" alt="Logo"
                        style="width: 50px; border-radius: 10px; margin-bottom: 1rem;">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem; color: #64748b;">&copy; <?php echo date('Y'); ?> Electricity
                        Distribution Lanka (Private) Limited</p>
                    <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5; color: #64748b;">Helping you manage
                        hardware tools and maintenance tasks.</p>
                </div>
            </footer>
        </main>
    </div>

    <script>
        // Initialize Chart
        const ctx = document.getElementById('maintenanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Assigned', 'Completed'],
                datasets: [{
                    data: [<?php echo $stats['pending_issues']; ?>, <?php echo $stats['assigned_issues']; ?>, <?php echo $stats['completed_issues']; ?>],
                    backgroundColor: [
                        '#f59e0b', // Pending
                        '#3b82f6', // Assigned
                        '#10b981'  // Completed
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: {
                                weight: '600',
                                family: "'Outfit', sans-serif"
                            }
                        }
                    }
                },
                cutout: '70%'
            }
        });

        // Mobile Sidebar Toggle (Can be expanded if a hamburger menu is added)
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

        function toggleDropdown() {
            const d = document.getElementById("profileDropdown");
            d.style.display = d.style.display === "block" ? "none" : "block";
        }

        window.onclick = function (event) {
            if (!event.target.closest('.profile-btn')) {
                const dropdown = document.getElementById("profileDropdown");
                if (dropdown && dropdown.style.display === "block") {
                    dropdown.style.display = "none";
                }
            }
        }


    </script>

    <script src="/HMS/desktop_notify.php"></script>
</body>

</html>