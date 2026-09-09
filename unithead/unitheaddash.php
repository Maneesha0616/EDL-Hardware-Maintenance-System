<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'unithead') {
    header("Location: ../login.php");
    exit();
}

// Fetch unit head info
$user_id = $_SESSION['user_id'];
$query = "SELECT username, profile_image FROM users WHERE id = ?";
$params = array($user_id);
$stmt = sqlsrv_prepare($conn, $query, $params);
if (!$stmt) { die(print_r(sqlsrv_errors(), true)); }
if (!sqlsrv_execute($stmt)) { die(print_r(sqlsrv_errors(), true)); }
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

$username = $user['username'] ?? 'Unit Head';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$notifQuery = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$params = array($user_id);
$notifStmt = sqlsrv_prepare($conn, $notifQuery, $params);
if(!$notifStmt){ die(print_r(sqlsrv_errors(), true)); }
if(!sqlsrv_execute($notifStmt)){ die(print_r(sqlsrv_errors(), true)); }
$unreadRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $unreadRow['total'] ?? 0;

// Fetch stats for the dashboard
$pendingQuery = "SELECT COUNT(*) as count FROM purchase_requests WHERE status = 'Pending'";
$pendingStmt = sqlsrv_query($conn, $pendingQuery);
if($pendingStmt === false){ die(print_r(sqlsrv_errors(), true)); }
$pendingRow = sqlsrv_fetch_array($pendingStmt, SQLSRV_FETCH_ASSOC);
$pendingCount = $pendingRow['count'] ?? 0;

$approvedQuery = "SELECT COUNT(*) as count FROM purchase_requests WHERE status = 'Approved'";
$approvedStmt = sqlsrv_query($conn, $approvedQuery);
if($approvedStmt === false){ die(print_r(sqlsrv_errors(), true)); }
$approvedRow = sqlsrv_fetch_array($approvedStmt, SQLSRV_FETCH_ASSOC);
$approvedCount = $approvedRow['count'] ?? 0;

$totalCostQuery = "SELECT SUM(pr.quantity * COALESCE(i.unit_price, 0)) as total FROM purchase_requests pr LEFT JOIN items i ON i.item_name = pr.item_name WHERE pr.status = 'Approved'";
$totalCostStmt = sqlsrv_query($conn, $totalCostQuery);
if($totalCostStmt === false){ die(print_r(sqlsrv_errors(), true)); }
$totalCostRow = sqlsrv_fetch_array($totalCostStmt, SQLSRV_FETCH_ASSOC);
$totalCost = $totalCostRow['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Head Dashboard | Hardware Maintenance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --secondary: #1e2a38;
            --accent: #FF9F1C;
            --text-muted: #94a3b8;
            --sidebar-width: 260px;
            --bg-body: #f8fafc;
            --glass: rgba(255, 255, 255, 0.85);
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
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
            background: var(--glass);
            backdrop-filter: blur(15px);
            padding: 1.25rem 3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        header h2 {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--secondary);
            letter-spacing: -0.5px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .notification-btn {
            position: relative;
            color: #64748b;
            font-size: 1.4rem;
            background: #f1f5f9;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            text-decoration: none;
            transition: 0.3s;
        }

        .notification-btn:hover {
            color: var(--primary);
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .notif-count {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 800;
            min-width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        .profile-btn {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 0.6rem 1.25rem;
            border-radius: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .profile-btn:hover {
            border-color: var(--primary);
            background: #f8fafc;
        }

        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            object-fit: cover;
        }

        .dropdown {
            display: none;
            position: absolute;
            right: 3rem;
            top: 5rem;
            background: white;
            width: 240px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            z-index: 100;
        }

        .dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 1rem 1.5rem;
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: 0.2s;
        }

        .dropdown a:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        /* Dashboard Content */
        .container {
            padding: 3rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .welcome-section {
            background: linear-gradient(135deg, #007c91 0%, #00b4d8 100%);
            border-radius: 32px;
            padding: 3.5rem;
            color: white;
            margin-bottom: 3rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 50%;
        }

        .welcome-section h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            letter-spacing: -1px;
        }

        .welcome-section p {
            font-size: 1.1rem;
            opacity: 0.8;
            max-width: 600px;
            line-height: 1.6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            padding: 2.5rem;
            border-radius: 28px;
            border: 1px solid rgba(226, 232, 240, 0.6);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-decoration: none;
            color: inherit;
        }

        .stat-card:hover {
            transform: translateY(-10px);
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
        }

        .ic-pending {
            background: rgba(255, 159, 28, 0.1);
            color: var(--accent);
        }

        .ic-approved {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .ic-cost {
            background: rgba(0, 124, 145, 0.1);
            color: var(--primary);
        }

        .stat-info h4 {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .stat-info h2 {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--secondary);
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
        }

        .action-card {
            background: white;
            border-radius: 32px;
            padding: 3rem;
            border: 1px solid rgba(226, 232, 240, 0.6);
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            transition: 0.4s;
            position: relative;
            overflow: hidden;
        }

        .action-card:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .action-card h3 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .action-card p {
            color: var(--text-muted);
            line-height: 1.7;
            margin-bottom: 1rem;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 1rem 2rem;
            border-radius: 16px;
            font-weight: 700;
            text-decoration: none;
            transition: 0.3s;
            width: max-content;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 10px 20px rgba(0, 124, 145, 0.15);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
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

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 0;
                padding: 0;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .container {
                padding: 2rem;
            }
        }
    </style>
</head>

<body>

    <div class="layout">
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='../index.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS </h1>
            </div>
            <ul class="menu">
                <li><a class="active" href="unitheaddash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="approveRequests.php"><i class="fas fa-check-circle"></i> Approve Requests</a></li>
                <li><a href="costReport.php"><i class="fas fa-file-invoice-dollar"></i> Cost Reports</a></li>
                <li class="logout"><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>Unit Head Dashboard</h2>
                <div class="header-right">
                    <a href="unitheadNotifications.php" class="notification-btn">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-count"><?= $unreadCount ?></span>
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
                    <h1>Welcome, <?= htmlspecialchars($username); ?>!</h1>
                    <p>Welcome to your control center. Monitor departmental hardware requests, approve acquisitions, and
                        track maintenance expenditures in real-time.</p>
                </div>

                <div class="stats-grid">
                    <a href="approveRequests.php" class="stat-card">
                        <div class="stat-icon ic-pending"><i class="fas fa-clock"></i></div>
                        <div class="stat-info">
                            <h4>Pending Approval</h4>
                            <h2><?= $pendingCount ?></h2>
                        </div>
                    </a>
                    <div class="stat-card">
                        <div class="stat-icon ic-approved"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info">
                            <h4>Total Approved</h4>
                            <h2><?= $approvedCount ?></h2>
                        </div>
                    </div>
                    <a href="costReport.php" class="stat-card">
                        <div class="stat-icon ic-cost"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="stat-info">
                            <h4>Total Value</h4>
                            <h2>Rs. <?= number_format($totalCost, 2) ?></h2>
                        </div>
                    </a>
                </div>

                <div class="action-grid">
                    <div class="action-card">
                        <h3><i class="fas fa-clipboard-check" style="color: var(--primary);"></i> Request Review</h3>
                        <p>Analyze and authorize pending hardware purchase requests from various departments. Ensure all
                            acquisitions align with current budgetary guidelines.</p>
                        <a href="approveRequests.php" class="btn-action btn-primary">Go to Approvals <i
                                class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="action-card">
                        <h3><i class="fas fa-chart-line" style="color: var(--primary)"></i> Financial Analytics</h3>
                        <p>Generate detailed cost reports and visualize maintenance spending. Track item-wise
                            expenditures and administrative review history.</p>
                        <a href="costReport.php" class="btn-action btn-primary">View Reports <i
                                class="fas fa-arrow-right"></i></a>
                    </div>
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
                if (dropdown && dropdown.style.display === "block") dropdown.style.display = "none";
            }
        }
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>