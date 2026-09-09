<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch admin details
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$stmt = sqlsrv_query($conn, $sql, [$user_id]);
if($stmt === false){
    error_log('Database error (admin fetch): ' . print_r(sqlsrv_errors(), true));
    die('Failed to fetch admin data.');
}
$userRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

$username = $userRow['username'] ?? 'Admin';
$profileImage = $userRow['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Handle status updates or deletions
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'clear_all') {
        $sql = "DELETE FROM notifications WHERE user_id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$user_id]);
        if($stmt === false){
            error_log('Database error (clear all notifications): ' . print_r(sqlsrv_errors(), true));
        }
    } elseif ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
        $notif_id = (int) $_POST['notification_id'];
        $sql = "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$notif_id, $user_id]);
        if($stmt === false){
            error_log('Database error (delete notification): ' . print_r(sqlsrv_errors(), true));
        }
    }
    header("Location: adminNotifications.php");
    exit();
}

// Mark all current admin notifications as read when page opens
$sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
$stmt = sqlsrv_query($conn, $sql, [$user_id]);
if($stmt === false){
    error_log('Database error (mark read): ' . print_r(sqlsrv_errors(), true));
}

// Fetch notifications
$sql = "SELECT notification_id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
$stmt = sqlsrv_query($conn, $sql, [$user_id]);
if($stmt === false){
    error_log('Database error (fetch notifications): ' . print_r(sqlsrv_errors(), true));
    $notifications = [];
} else {
    $notifications = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)){
        // If created_at is DateTime, format it
        if($row['created_at'] instanceof DateTime){
            $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
        }
        $notifications[] = $row;
    }
}

// Normalize created_at if it's a DateTime object
foreach ($notifications as &$row) {
    if ($row['created_at'] instanceof DateTime) {
        $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
    }
}
unset($row);

$unreadCount = 0; // Already marked as read above

// Helper to assign icons and colors
function getNotificationMeta($title)
{
    $title = strtolower($title);
    if (strpos($title, 'stock') !== false || strpos($title, 'inventory') !== false) {
        return ['icon' => 'fa-box-open', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.1)'];
    } elseif (strpos($title, 'service') !== false || strpos($title, 'maintenance') !== false) {
        return ['icon' => 'fa-screwdriver-wrench', 'color' => '#007c91', 'bg' => 'rgba(0, 124, 145, 0.1)'];
    } elseif (strpos($title, 'user') !== false || strpos($title, 'account') !== false) {
        return ['icon' => 'fa-user-gear', 'color' => '#8b5cf6', 'bg' => 'rgba(139, 92, 246, 0.1)'];
    }
    return ['icon' => 'fa-bell', 'color' => '#64748b', 'bg' => 'rgba(100, 116, 139, 0.1)'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Notifications | Hardware Maintenance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
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
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            --glass: rgba(255, 255, 255, 0.7);
            --radius: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
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

        /* Main Content Area */
        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: linear-gradient(rgba(242, 247, 255, 0.7), rgba(242, 247, 255, 0.8)), url('../images/dashboard_bg.png');
            background-size: cover;
            background-attachment: fixed;
        }

        header {
            background: rgba(255, 255, 255, 0.8);
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
            font-weight: 700;
        }

        .profile-btn:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
        }

        .dropdown {
            display: none;
            position: absolute;
            right: 30px;
            top: 70px;
            background: #fff;
            width: 200px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            z-index: 1000;
        }

        .dropdown a {
            display: block;
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
        }

        .container {
            padding: 2.5rem;
            max-width: 1000px;
            margin: 0 auto;
            width: 100%;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--secondary);
            letter-spacing: -0.5px;
        }

        .btn-clear {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            padding: 0.75rem 1.5rem;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(239, 68, 68, 0.2);
            transition: all 0.3s;
            cursor: pointer;
        }

        .btn-clear:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }

        /* Notification List - Timeline Style */
        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            position: relative;
        }

        .notif-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            padding: 1.5rem;
            border-radius: var(--radius);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: var(--shadow);
            display: flex;
            gap: 1.25rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .notif-card:hover {
            transform: scale(1.02) translateX(10px);
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.08);
        }

        .notif-icon-wrap {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .notif-body {
            flex-grow: 1;
        }

        .notif-body h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 0.5rem;
        }

        .notif-body p {
            font-size: 0.95rem;
            color: #4b5563;
            line-height: 1.6;
            margin-bottom: 0.75rem;
        }

        .notif-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notif-meta {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-delete {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: 0.2s;
            font-size: 0.9rem;
        }

        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 5rem 2rem;
            background: var(--glass);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            border: 1px dashed rgba(148, 163, 184, 0.5);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            opacity: 0.3;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Footer */
        .footer {
            background: rgba(255, 255, 255, 0.5);
            padding: 3rem;
            text-align: center;
            border-top: 1px solid rgba(226, 232, 240, 0.5);
            margin-top: auto;
        }

        .footer img {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            margin-bottom: 1rem;
            opacity: 0.8;
        }

        .footer h3 {
            font-size: 1.1rem;
            color: var(--secondary);
            margin-bottom: 0.5rem;
        }

        .footer p {
            font-size: 0.85rem;
            color: var(--text-muted);
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
                

                <li class="logout">
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>Notifications</h2>
                <div class="header-right">
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

            <div class="container">
                <div class="page-header">
                    <h1>Recent Alerts</h1>
                    <?php if (count($notifications) > 0): ?>
                        <form method="POST" style="display:inline;">
                            <button type="submit" name="action" value="clear_all" class="btn-clear"
                                onclick="return confirm('Attention: This will permanently delete all notifications. Proceed?')">
                                <i class="fas fa-trash-sweep"></i> Clear Hub
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="notif-list">
                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $row):
                            $meta = getNotificationMeta($row['title']);
                            ?>
                            <div class="notif-card">
                                <div class="notif-icon-wrap"
                                    style="background: <?= $meta['bg'] ?>; color: <?= $meta['color'] ?>;">
                                    <i class="fas <?= $meta['icon'] ?>"></i>
                                </div>
                                <div class="notif-body">
                                    <h3><?= htmlspecialchars($row['title']); ?></h3>
                                    <p><?= nl2br(htmlspecialchars(str_replace('\n', "\n", $row['message']))); ?></p>
                                    <div class="notif-footer">
                                        <div class="notif-meta">
                                            <i class="far fa-clock"></i>
                                            <?= date("F j, Y, g:i a", strtotime($row['created_at'])); ?>
                                        </div>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="notification_id" value="<?= $row['notification_id'] ?>">
                                            <button type="submit" name="action" value="delete" class="btn-delete"
                                                title="Archive Notification">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <p>Your notification hub is currently empty.</p>
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