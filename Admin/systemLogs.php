<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch admin details
$user_id = $_SESSION['user_id'];
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$stmt = sqlsrv_prepare($conn, $sql, array($user_id));
if ($stmt === false) { die(print_r(sqlsrv_errors(), true)); }
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'Admin';
$profileImage = $user['profile_image'];
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifQuery = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$notifStmt = sqlsrv_prepare($conn, $notifQuery, array($user_id));
if ($notifStmt === false) { die(print_r(sqlsrv_errors(), true)); }
sqlsrv_execute($notifStmt);
$notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Pagination variables
$limit = 20; // Entries per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Search variables
$searchStr = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereClause = "";
$searchParam = "%" . $searchStr . "%";

if (!empty($searchStr)) {
    $whereClause = "WHERE (u.username LIKE ? OR sl.action LIKE ? OR sl.details LIKE ?)";
}

// Period variables
$period = isset($_GET['period']) ? trim($_GET['period']) : 'all';
$dateCondition = "";

switch ($period) {
    case 'today':
        $dateCondition = "sl.created_at >= CAST(GETDATE() AS DATE)";
        break;
    case 'yesterday':
        $dateCondition = "sl.created_at >= DATEADD(DAY, -1, CAST(GETDATE() AS DATE)) AND sl.created_at < CAST(GETDATE() AS DATE)";
        break;
    case 'week':
        $dateCondition = "sl.created_at >= DATEADD(DAY, -7, CAST(GETDATE() AS DATE))";
        break;
    case 'month':
        $dateCondition = "sl.created_at >= DATEADD(DAY, -30, CAST(GETDATE() AS DATE))";
        break;
}

if (!empty($dateCondition)) {
    if (!empty($whereClause)) {
        $whereClause .= " AND " . $dateCondition;
    } else {
        $whereClause = "WHERE " . $dateCondition;
    }
}

// Helper function to map activity category attributes
if (!function_exists('get_activity_category')) {
    function get_activity_category($action) {
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

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM system_logs sl LEFT JOIN users u ON sl.user_id = u.id $whereClause";
try {
    // Prepare count statement with parameters if needed
    $countParams = [];
    if (!empty($searchStr)) {
        $countParams = [$searchParam, $searchParam, $searchParam];
    }
    $countStmt = sqlsrv_prepare($conn, $countQuery, $countParams);
    if ($countStmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    sqlsrv_execute($countStmt);
    $totalRows = 0;
    if ($row = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC)) {
        $totalRows = $row['total'] ?? 0;
    }
} catch (PDOException $e) {
    error_log("Database Error in systemLogs.php (count): " . $e->getMessage());
    $totalRows = 0;
}
$totalPages = ceil($totalRows / $limit);

// Fetch logs — SQL Server uses OFFSET/FETCH NEXT instead of LIMIT/OFFSET
$fetchQuery = "SELECT sl.id, sl.action, sl.details, sl.created_at, u.username, u.role 
               FROM system_logs sl 
               LEFT JOIN users u ON sl.user_id = u.id 
               $whereClause 
               ORDER BY sl.created_at DESC 
               OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";

try {
    // Prepare fetch statement with parameters based on search
    $fetchParams = [];
    if (!empty($searchStr)) {
        $fetchParams = [$searchParam, $searchParam, $searchParam, $offset, $limit];
    } else {
        $fetchParams = [$offset, $limit];
    }
    $fetchStmt = sqlsrv_prepare($conn, $fetchQuery, $fetchParams);
    if ($fetchStmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    sqlsrv_execute($fetchStmt);
    $logs = [];
    while ($row = sqlsrv_fetch_array($fetchStmt, SQLSRV_FETCH_ASSOC)) {
        $logs[] = $row;
    }
} catch (PDOException $e) {
    error_log("Database Error in systemLogs.php (fetch): " . $e->getMessage());
    $logs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Logs | Admin HMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --secondary: #1e2a38;
            --text-muted: #94a3b8;
            --sidebar-width: 260px;
            --bg-body: #f8fafc;
        }

        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Poppins', sans-serif; 
        }

        body { 
            background-color: var(--bg-body); 
            color: #1e293b; 
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
            background: linear-gradient(90deg, #fff, var(--text-muted)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; 
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


        /* Main Content */
        .main-content { 
            margin-left: var(--sidebar-width); 
            width: calc(100% - var(--sidebar-width)); 
            display: flex; 
            flex-direction: column; 
            min-height: 100vh; 
        }

        header { 
            background: #fff; 
            padding: 20px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); 
            position: sticky; 
            top: 0; 
            z-index: 50; 
        }

        header h2 { 
            font-size: 1.4rem; 
            font-weight: 800; 
            color: #1e2a38; 
        }

        .header-right { 
            display: flex; 
            align-items: center; gap: 20px; 
        }

        .profile-btn { 
            background: #f1f5f9; 
            color: #1e2a38; 
            border: 1px solid #e2e8f0; 
            padding: 8px 18px; 
            border-radius: 100px; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            font-weight: 700; 
            transition: 0.2s; 
        }

        .profile-btn:hover { 
            background: #e2e8f0; 
        }

        .profile-pic { 
            width: 32px; 
            height: 32px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid #fff; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); 
        }

        .dropdown { 
            display: none; 
            position: absolute; 
            right: 20px; 
            top: 70px; 
            background: #fff; 
            width: 200px; 
            border-radius: 16px; 
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); 
            border: 1px solid #f1f5f9; 
            overflow: hidden; 
            z-index: 100; 
        }

        .dropdown a { 
            display: block; 
            padding: 12px 20px;
            color: #1e2a38; 
            text-decoration: none; 
            font-weight: 600; 
            font-size: 0.9rem; 
            transition: background 0.2s; 
        }

        .dropdown a:hover { 
            background: #f8fafc; 
            color: #007c91; 
        }

        .notification-bell { 
            position: relative; 
            color: #64748b; 
            font-size: 1.3rem; 
            text-decoration: none; 
            width: 45px; 
            height: 45px; 
            background: #f1f5f9; 
            border-radius: 12px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            transition: 0.2s; 
        }

        .notification-bell:hover { 
            color: #1e2a38; 
            background: #e2e8f0; 
        }

        .notification-count { 
            position: absolute; 
            top: -5px; 
            right: -5px; 
            background: #ef4444; 
            color: #fff; 
            font-size: 10px; 
            font-weight: 800; 
            min-width: 20px; 
            height: 20px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            border: 2px solid #fff; 
        }


        /* Page Container */
        .page-container { 
            padding: 30px 40px; 
            flex-grow: 1; 
        }

        .section-card { 
            background: #ffffff; 
            border-radius: 20px; 
            padding: 30px; 
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05); 
            margin-bottom: 25px; 
            border: 1px solid #f1f5f9; 
        }

        .section-title { 
            font-size: 1.35rem; 
            font-weight: 800; 
            margin-bottom: 24px; 
            color: #1e2a38; 
        }
        
        .top-actions { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 24px; 
            flex-wrap: wrap; 
            gap: 16px; 
        }

        .search-box { 
            display: flex; 
            gap: 10px; 
            flex: 1; 
            max-width: 400px; 
        }

        .search-box input { 
            width: 100%; 
            padding: 12px 16px; 
            border-radius: 10px; 
            border: 1px solid #d1d5db; 
            font-size: 0.95rem; 
            outline: none; 
        }

        .search-box input:focus { 
            border-color: #007c91; 
            box-shadow: 0 0 0 3px rgba(0, 124, 145, 0.15); 
        }
        .btn { 
            background: #007c91; 
            color: #fff; 
            border: none; 
            padding: 12px 20px; 
            border-radius: 10px; 
            cursor: pointer; 
            font-weight: 700; 
            transition: 0.2s; 
            text-decoration: none; 
            display: inline-block; 
        }

        .btn:hover { 
            background: #005662; 
        }
        
        /* Table */
        .table-wrapper {
             overflow-x: auto; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 0.9rem; 
            margin-bottom: 20px; 
        }

        thead th { 
            background: #007c91; 
            color: #fff; 
            font-weight: 600; 
            padding: 14px 16px; 
            text-align: left; 
        }

        tbody td { 
            padding: 14px 16px; 
            border-bottom: 1px solid #e2e8f0; 
            vertical-align: top; 
        }

        tbody tr:hover { 
            background-color: #f8fafc; 
        }
        
        .role-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 6px; 
            font-size: 0.75rem; 
            font-weight: 700; 
            background: #e2e8f0; 
            color: #475569; 
            text-transform: capitalize; 
        }

        
        /* Time Filter Pills */
        .period-filters { 
            display: flex; 
            gap: 10px; 
            margin-bottom: 24px; 
            flex-wrap: wrap; 
        }

        .filter-pill { 
            padding: 8px 18px; 
            background: #f1f5f9; 
            color: #475569; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 700; 
            text-decoration: none; 
            transition: all 0.25s ease; 
            border: 1px solid #e2e8f0; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
        }

        .filter-pill:hover { 
            background: #e2e8f0; 
            color: #0f172a; 
            transform: translateY(-1px); 
        }

        .filter-pill.active { 
            background: #007c91; 
            color: white; 
            border-color: #007c91; 
            box-shadow: 0 4px 12px rgba(0, 124, 145, 0.25); 
        }


        /* Pagination */
        .pagination { 
            display: flex; 
            gap: 8px; 
            justify-content: flex-end; 
            margin-top: 20px; 
        }

        .pagination a { 
            padding: 8px 14px; 
            background: #f1f5f9; 
            color: #1e2a38; 
            border-radius: 8px; 
            text-decoration: none; 
            font-weight: 600; 
            font-size: 0.9rem; 
            transition: 0.2s; 
        }

        .pagination a:hover { 
            background: #e2e8f0; 
        }

        .pagination a.active { 
            background: #007c91; 
            color: white; 
        }


        /* Footer */
        .footer { 
            background: #ffffff; 
            padding: 30px; 
            text-align: center; 
            border-top: 1px solid #f1f5f9; 
            margin-top: auto; 
        }

        .footer img { 
            width: 40px; 
            border-radius: 8px; 
            margin-bottom: 10px; 
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
                <li class="logout"><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>System Logs</h2>
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

            <div class="page-container">
                <div class="section-card">
                    <div class="top-actions">
                        <h3 class="section-title" style="margin-bottom:0;">System History &amp; Audit Log</h3>
                        <form method="GET" action="systemLogs.php" class="search-box">
                            <?php if(!empty($period) && $period !== 'all'): ?>
                                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                            <?php endif; ?>
                            <input type="text" name="search" placeholder="Search logs (user, action, details)..." value="<?= htmlspecialchars($searchStr) ?>">
                            <button type="submit" class="btn"><i class="fas fa-search"></i></button>
                            <?php if(!empty($searchStr)): ?>
                                <a href="systemLogs.php?period=<?= urlencode($period) ?>" class="btn" style="background:#64748b;"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Time Period filters pills -->
                    <div class="period-filters">
                        <a href="?period=all<?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>" class="filter-pill <?= $period === 'all' ? 'active' : '' ?>"><i class="fas fa-history"></i> All Time</a>
                        <a href="?period=today<?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>" class="filter-pill <?= $period === 'today' ? 'active' : '' ?>"><i class="fas fa-calendar-day"></i> Today</a>
                        <a href="?period=yesterday<?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>" class="filter-pill <?= $period === 'yesterday' ? 'active' : '' ?>"><i class="fas fa-calendar-minus"></i> Yesterday</a>
                        <a href="?period=week<?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>" class="filter-pill <?= $period === 'week' ? 'active' : '' ?>"><i class="fas fa-calendar-week"></i> This Week</a>
                        <a href="?period=month<?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>" class="filter-pill <?= $period === 'month' ? 'active' : '' ?>"><i class="fas fa-calendar-alt"></i> This Month</a>
                    </div>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($logs) > 0): ?>
                                    <?php foreach ($logs as $row):
                                        $cat = get_activity_category($row['action']);
                                        ?>
                                        <tr>
                                            <td style="white-space: nowrap;"><?= is_object($row['created_at']) ? $row['created_at']->format('M d, Y H:i') : date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($row['username'] ?? 'System') ?></strong><br>
                                                <?php if($row['role']): ?>
                                                    <span class="role-badge"><?= htmlspecialchars($row['role']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; color: #1e293b;">
                                                    <span style="display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: <?= $cat['bg'] ?>; color: <?= $cat['color'] ?>; font-size: 0.85rem;">
                                                        <i class="<?= $cat['icon'] ?>"></i>
                                                    </span>
                                                    <?= htmlspecialchars($row['action']) ?>
                                                </span>
                                                <span style="display: block; font-size: 0.7rem; font-weight: 700; color: <?= $cat['color'] ?>; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em;">
                                                    <?= $cat['label'] ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($row['details'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 30px;">
                                            <i class="fas fa-folder-open" style="font-size: 40px; color: #cbd5e1; margin-bottom: 15px; display: block;"></i>
                                            <span style="color: #64748b;">No system logs found matching your criteria.</span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if($page > 1): ?>
                            <a href="?page=<?= $page-1 ?><?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>&period=<?= urlencode($period) ?>">&laquo; Prev</a>
                        <?php endif; ?>
                        
                        <?php 
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for($i = $start; $i <= $end; $i++): 
                        ?>
                            <a href="?page=<?= $i ?><?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>&period=<?= urlencode($period) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        
                        <?php if($page < $totalPages): ?>
                            <a href="?page=<?= $page+1 ?><?= !empty($searchStr) ? '&search='.urlencode($searchStr) : '' ?>&period=<?= urlencode($period) ?>">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
