<?php
// require_once dirname(__DIR__) . '/security.php';
require_once dirname(__DIR__) . '/security.php';

session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

// Fetch user details
$user_id = $_SESSION['user_id'];
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

// Unread notification count
$notifStmt = sqlsrv_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", array(&$user_id));
if ($notifStmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($notifStmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Fetch simple stats for common user
// Total issues raised by this user
$stmt1 = sqlsrv_prepare($conn, "SELECT COUNT(*) as total FROM issues WHERE user_id = ?", array(&$user_id));
if ($stmt1 === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt1)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$totalIssues = sqlsrv_fetch_array($stmt1, SQLSRV_FETCH_ASSOC)['total'] ?? 0;

// Pending issues for this user
$stmt2 = sqlsrv_prepare($conn, "SELECT COUNT(*) as total FROM issues WHERE user_id = ? AND status = 'Pending'", array(&$user_id));
if ($stmt2 === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt2)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$pendingIssues = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)['total'] ?? 0;

// Completed issues for this user
$stmt3 = sqlsrv_prepare($conn, "SELECT COUNT(*) as total FROM issues WHERE user_id = ? AND status = 'Completed'", array(&$user_id));
if ($stmt3 === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt3)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$completedIssues = sqlsrv_fetch_array($stmt3, SQLSRV_FETCH_ASSOC)['total'] ?? 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard | HMS</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
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
            padding: 2rem 1.25rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
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
            margin-bottom: 2.5rem;
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
            margin-bottom: 0.5rem;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.875rem 1.25rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            border-radius: 12px;
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
            box-shadow: 0 4px 15px rgba(0, 124, 145, 0.3);
        }

        .menu i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }

        .menu .logout {
            margin-top: 2rem;
            padding-top: 1rem;
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
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: linear-gradient(rgba(248, 250, 252, 0.8), rgba(248, 250, 252, 0.9)), url('../images/dashboard_bg.png');
            background-size: cover;
            background-attachment: fixed;
        }

        header {
            background: #fff;
            padding: 1.25rem 2rem;
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
            color: var(--secondary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
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
            transition: all 0.3s;
        }

        .notification-bell:hover {
            color: var(--primary);
            background: #e2e8f0;
            transform: scale(1.05);
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

        .profile-btn {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 8px 18px;
            border-radius: 100px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            transition: all 0.3s;
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
        }

        .dropdown {
            display: none;
            position: absolute;
            right: 2rem;
            top: 5rem;
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
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .dropdown a:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .container {
            padding: 2.5rem;
            flex-grow: 1;
        }

        .welcome-section {
            margin-bottom: 2.5rem;
        }

        .welcome-section h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--secondary);
            margin-bottom: 0.5rem;
        }

        .welcome-section p {
            color: #64748b;
            font-weight: 500;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: #fff;
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-info h3 {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 4px;
        }

        .stat-info p {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--secondary);
        }

        .blue {
            background: rgba(0, 124, 145, 0.1);
            color: var(--primary);
        }

        .amber {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .green {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        /* Actions Grid */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
        }

        .action-card {
            background: #fff;
            border-radius: 24px;
            padding: 2.5rem;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #f1f5f9;
            text-decoration: none;
            color: var(--secondary);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: var(--primary);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .action-card:hover::before {
            opacity: 1;
        }

        .action-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }

        .action-card i {
            font-size: 40px;
            color: var(--primary);
            margin-bottom: 20px;
            display: block;
        }

        .action-card h3 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .action-card p {
            font-size: 0.95rem;
            color: #64748b;
            line-height: 1.5;
        }

        /* Footer */
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
            color: var(--secondary);
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
                width: 100%;
                position: relative;
                height: auto;
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
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='../index.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="userdash.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="userissues.php"><i class="fas fa-plus-circle"></i> Raise Issue</a></li>
                <li><a href="myissues.php"><i class="fas fa-list-check"></i> My Issues</a></li>

                <li class="logout">
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>User Dashboard</h2>
                <div class="header-right">
                    <a href="userNotifications.php" class="notification-bell">
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
                        <a href="../userprofile.php"><i class="fas fa-user-circle"></i> Profile</a>
                        <a href="../logout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </header>

            <div class="container">
                <div class="welcome-section">
                    <h1>Welcome back, <?= htmlspecialchars($username) ?>!</h1>
                    <p>Have a hardware issue? Raise it below and we'll get it fixed.</p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-info">
                            <h3>Total Raised</h3>
                            <p><?= $totalIssues ?></p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon amber">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3>Pending</h3>
                            <p><?= $pendingIssues ?></p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div class="stat-info">
                            <h3>Resolved</h3>
                            <p><?= $completedIssues ?></p>
                        </div>
                    </div>
                </div>

                <div class="actions-grid">
                    <a href="userissues.php" class="action-card">
                        <i class="fas fa-plus-circle"></i>
                        <h3>Raise New Issue</h3>
                        <p>Encountered a hardware failure or need maintenance? Submit a request here.</p>
                    </a>

                    <a href="myissues.php" class="action-card">
                        <i class="fas fa-tasks"></i>
                        <h3>Track My Issues</h3>
                        <p>View the progress, service history, and technician feedback on your requests.</p>
                    </a>

                </div>
            </div>

            <footer class="footer">
                <div class="container" style="padding: 0;">
                    <img src="../images/hardware_logo.png" alt="Logo"
                        style="width: 50px; border-radius: 10px; margin-bottom: 1rem;">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem;">&copy; <?php echo date('Y'); ?> All Rights Reserved.</p>
                    <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5;">Helping you manage hardware tools and
                        maintenance tasks.</p>
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