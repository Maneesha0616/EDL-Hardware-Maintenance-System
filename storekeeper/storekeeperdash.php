<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection 
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'storekeeper') {
    header("Location: ../login.php");
    exit();
}

// Fetch user
$user_id = $_SESSION['user_id'];
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$params = array($user_id);
$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

$username = $user['username'] ?? 'Storekeeper';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifSql = "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = ? AND is_read = 0
";
$notifParams = array($user_id);
$notifQuery = sqlsrv_query($conn, $notifSql, $notifParams);
if ($notifQuery === false) {
    die(print_r(sqlsrv_errors(), true));
}
$notifRow = sqlsrv_fetch_array($notifQuery, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;
sqlsrv_free_stmt($notifQuery);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storekeeper Dashboard | Hardware Maintenance System</title>
    
    <!-- Fonts and Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800" rel="stylesheet">

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

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 10px; }

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

        .menu { list-style: none; }
        .menu li { margin-bottom: 0.5rem; }

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

        .menu i { font-size: 1.1rem; width: 24px; text-align: center; }

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
            background: linear-gradient(rgba(248, 250, 252, 0.9), rgba(248, 250, 252, 0.95)), url('../images/dashboard_bg.png');
            background-size: cover;
            background-attachment: fixed;
        }

        header {
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(12px);
            padding: 1.25rem 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        header h2 {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--secondary);
            letter-spacing: -0.5px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .notification-bell {
            position: relative;
            color: #64748b;
            font-size: 1.3rem;
            transition: 0.2s;
            background: #f1f5f9;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            text-decoration: none;
        }

        .notification-bell:hover {
            color: var(--primary);
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            font-size: 0.65rem;
            font-weight: 800;
            min-width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        .profile-btn {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            transition: 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .profile-btn:hover {
            background: #f8fafc;
            border-color: var(--primary);
        }

        .profile-pic {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            object-fit: cover;
        }

        .dropdown {
            display: none;
            position: absolute;
            right: 2.5rem;
            top: 4.5rem;
            background: white;
            width: 220px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            z-index: 100;
        }

        .dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.875rem 1.25rem;
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: 0.2s;
        }

        .dropdown a:hover {
            background: #f1f5f9;
            color: var(--primary);
        }

        /* Container & Cards */
        .container {
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .welcome-hero {
            background: linear-gradient(135deg, #007c91 0%, #00b4d8 100%);
            padding: 3rem;
            border-radius: 24px;
            color: white;
            margin-bottom: 2.5rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(30, 42, 56, 0.15);
        }

        .welcome-hero::after {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: rgba(0, 124, 145, 0.2);
            border-radius: 50%;
            filter: blur(80px);
        }

        .welcome-hero h1 {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            letter-spacing: -1px;
        }

        .welcome-hero p {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 400;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .action-card {
            background: white;
            border-radius: 24px;
            padding: 2.5rem;
            text-decoration: none;
            color: var(--secondary);
            border: 1px solid #f1f5f9;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }

        .action-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: 0 20px 40px rgba(0, 124, 145, 0.12);
        }

        .icon-circle {
            width: 70px;
            height: 70px;
            background: #f1f5f9;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: var(--primary);
            transition: 0.3s;
        }

        .action-card:hover .icon-circle {
            background: var(--primary);
            color: white;
            transform: rotate(-5deg);
        }

        .action-card h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .action-card p {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .action-card i.arrow {
            position: absolute;
            bottom: 2rem;
            right: 2rem;
            font-size: 1.1rem;
            color: #cbd5e1;
            transition: 0.3s;
        }

        .action-card:hover i.arrow {
            color: var(--primary);
            transform: translateX(5px);
        }

        /* Footer Styling */
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

        /* Mobile Adjustments */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); width: 0; padding: 0; }
            .main-content { margin-left: 0; width: 100%; }
            .container { padding: 1.5rem; }
        }
    </style>
</head>

<body>

    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='../index.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="storekeeperdash.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="issueHardware.php"><i class="fas fa-share-square"></i> Issue Hardware</a></li>
                <li><a href="manageInventory.php"><i class="fas fa-boxes"></i> Manage Inventory</a></li>
                <li><a href="stockReport.php"><i class="fas fa-file-invoice-dollar"></i> Stock Reports</a></li>

                <li class="logout">
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header>
                <h2>Storekeeper Dashboard</h2>
                <div class="header-right">
                    <a href="storekeeperNotifications.php" class="notification-bell">
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
                <div class="welcome-hero">
                    <h1>Active Store Management</h1>
                    <p>Track inventory movements and facilitate hardware fulfillment effectively.</p>
                </div>

                <div class="dashboard-grid">
                    <a href="issueHardware.php" class="action-card">
                        <div class="icon-circle">
                            <i class="fas fa-share-square"></i>
                        </div>
                        <h3>Issue Hardware</h3>
                        <p>Process approved part requests and record hardware issuance to technicians.</p>
                        <i class="fas fa-arrow-right arrow"></i>
                    </a>

                    <a href="manageInventory.php" class="action-card">
                        <div class="icon-circle">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <h3>Inventory Control</h3>
                        <p>Monitor stock availability, add new parts, and update current hardware levels.</p>
                        <i class="fas fa-arrow-right arrow"></i>
                    </a>

                    <a href="stockReport.php" class="action-card">
                        <div class="icon-circle">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <h3>Stock Reports</h3>
                        <p>Generate detailed inventory status reports and track consumption over time.</p>
                        <i class="fas fa-arrow-right arrow"></i>
                    </a>
                </div>
            </div>

            <footer class="footer">
                <div class="container" style="padding: 0;">
                    <img src="../images/hardware_logo.png" alt="Logo" style="width: 50px; border-radius: 10px; margin-bottom: 1rem;">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem;">&copy; <?php echo date('Y'); ?> All Rights Reserved.</p>
                    <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5;">Helping you manage hardware tools and maintenance tasks.</p>
                </div>
            </footer>
        </main>
    </div>

    <script>
        function toggleDropdown() {
            const dropdown = document.getElementById("profileDropdown");
            dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
        }

        // Close dropdown when clicking outside
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
