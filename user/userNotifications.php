<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user details
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$stmt = sqlsrv_prepare($conn, $sql, array(&$user_id));
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'User';
$profileImage = $user['profile_image'];
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Handle archive/delete actions
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'clear_all') {
        $delStmt = sqlsrv_prepare($conn, "DELETE FROM notifications WHERE user_id = ?", array(&$user_id));
        if ($delStmt !== false) { sqlsrv_execute($delStmt); }
    } elseif ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
        $notif_id = (int) $_POST['notification_id'];
        $delStmt = sqlsrv_prepare($conn, "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?", array(&$notif_id, &$user_id));
        if ($delStmt !== false) { sqlsrv_execute($delStmt); }
    }
    header("Location: userNotifications.php");
    exit();
}

// Mark all current notifications as read when page opens
$markStmt = sqlsrv_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ?", array(&$user_id));
if ($markStmt !== false) { sqlsrv_execute($markStmt); }

// Fetch notifications
$fetchSql = "SELECT notification_id, title, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC";
$fetchStmt = sqlsrv_prepare($conn, $fetchSql, array(&$user_id));
if ($fetchStmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($fetchStmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$notifications = [];
while ($row = sqlsrv_fetch_array($fetchStmt, SQLSRV_FETCH_ASSOC)) {
    $notifications[] = $row;
}

$unreadCount = 0; // Marked as read

// Helper to assign icons and colors
function getNotificationMeta($title)
{
    $title = strtolower($title);
    if (strpos($title, 'resolved') !== false || strpos($title, 'completed') !== false) {
        return ['icon' => 'fa-circle-check', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.1)'];
    } elseif (strpos($title, 'in progress') !== false) {
        return ['icon' => 'fa-wrench', 'color' => '#3b82f6', 'bg' => 'rgba(59, 130, 246, 0.1)'];
    } elseif (strpos($title, 'waiting') !== false || strpos($title, 'parts') !== false) {
        return ['icon' => 'fa-clock', 'color' => '#f97316', 'bg' => 'rgba(249, 115, 22, 0.1)'];
    } elseif (strpos($title, 'rejected') !== false) {
        return ['icon' => 'fa-circle-xmark', 'color' => '#ef4444', 'bg' => 'rgba(239, 68, 68, 0.1)'];
    } elseif (strpos($title, 'assigned') !== false || strpos($title, 'technician') !== false) {
        return ['icon' => 'fa-user-gear', 'color' => '#007c91', 'bg' => 'rgba(0, 124, 145, 0.1)'];
    } elseif (strpos($title, 'update') !== false || strpos($title, 'status') !== false) {
        return ['icon' => 'fa-pen-to-square', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.1)'];
    }
    return ['icon' => 'fa-bell', 'color' => '#64748b', 'bg' => 'rgba(100, 116, 139, 0.1)'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Notifications | Hardware Maintenance System</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --secondary: #1e2a38;
            --text-muted: #94a3b8;
            --sidebar-width: 260px;
            --bg-body: #f8fafc;
            --glass: rgba(255, 255, 255, 0.7);
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            --radius: 20px;
            --danger: #ef4444;
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
            padding: 2.5rem 1.5rem;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 25px rgba(0, 0, 0, 0.1);
        }

        .sidebar .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 3.5rem;
            padding-left: 0.5rem;
        }

        .sidebar .logo img {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            background: transparent;
            object-fit: contain;
        }

        .sidebar .logo h1 {
            font-size: 1.25rem;
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
            margin-bottom: 0.75rem;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 1rem 1.25rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            border-radius: 16px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .menu a:hover {
            color: white;
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(5px);
        }

        .menu a.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 8px 20px rgba(0, 124, 145, 0.3);
        }

        .menu i {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }

        .menu .logout {
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .menu .logout a {
            color: #f87171;
            background: rgba(248, 113, 113, 0.05);
        }

        .menu .logout a:hover {
            background: #ef4444;
            color: white;
        }

        /* Main Content */
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
            display: flex;
            align-items: center;
            gap: 12px;
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

        /* Notification List */
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
                width: 100%;
                position: relative;
                height: auto;
                padding: 1.5rem;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .layout {
                flex-direction: column;
            }

            header {
                padding: 1rem;
            }

            .container {
                padding: 1.5rem;
            }
        }
    </style>
</head>

<body>

    <div class="layout">
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='userdash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="userdash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="userissues.php"><i class="fas fa-plus-circle"></i> Raise Issue</a></li>
                <li><a href="myissues.php"><i class="fas fa-list-check"></i> My Issues</a></li>
                <li><a href="costReport.php"><i class="fas fa-file-invoice-dollar"></i> Cost Reports</a></li>

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
                        <a href="../userprofile.php"><i class="fas fa-user-circle"></i> Profile</a>
                        <a href="../logout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </header>

            <div class="container">
                <div class="page-header">
                    <h1>Your Alerts</h1>
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
                                <div class="notif-icon-wrap" style="background: <?= $meta['bg'] ?>; color: <?= $meta['color'] ?>;">
                                    <i class="fas <?= $meta['icon'] ?>"></i>
                                </div>
                                <div class="notif-body">
                                    <h3><?= htmlspecialchars($row['title']); ?></h3>
                                    <p><?= nl2br(htmlspecialchars(str_replace('\n', "\n", $row['message']))); ?></p>
                                    <div class="notif-footer">
                                        <div class="notif-meta">
                                            <i class="far fa-clock"></i>
                                            <?= ($row['created_at'] instanceof DateTime) ? $row['created_at']->format('F j, Y, h:i A') : date("F j, Y, h:i A", strtotime($row['created_at'])); ?>
                                        </div>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="notification_id" value="<?= $row['notification_id'] ?>">
                                            <button type="submit" name="action" value="delete" class="btn-delete" title="Archive Notification">
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
                <img src="../images/hardware_logo.png" alt="Logo">
                <h3>Hardware Maintenance System</h3>
                <p>&copy; <?php echo date('Y'); ?> All Rights Reserved.</p>
                <p style="margin-top: 10px; opacity: 0.6;">Ensuring peak performance through smart maintenance.</p>
            </footer>
        </main>
    </div>

    <script>
        function toggleDropdown() {
            const d = document.getElementById("profileDropdown");
            d.style.display = d.style.display === "block" ? "none" : "block";
        }

        window.onclick = function(event) {
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
