<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch technician info using SQLSRV
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$params = array(&$user_id);
$stmt = sqlsrv_prepare($conn, $sql, $params);
if(!$stmt) { die(print_r(sqlsrv_errors(), true)); }
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'Technician';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$sql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$params = array(&$user_id);
$stmt = sqlsrv_query($conn, $sql, $params);
if($stmt === false){ die(print_r(sqlsrv_errors(), true)); }
$notifRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Fetch only active assigned issues
$sql = "SELECT i.*, u.username AS username FROM issues i LEFT JOIN users u ON i.user_id = u.id WHERE i.technician_id = ? AND i.status IN ('Assigned', 'In Progress', 'Waiting Parts') ORDER BY i.received_date DESC";
$params = array(&$user_id);
$issues = sqlsrv_query($conn, $sql, $params);
if($issues === false){ die(print_r(sqlsrv_errors(), true)); }
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Assigned Issues | Hardware Maintenance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700" rel="stylesheet">

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
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
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

        /* Content Area */
        .page-container {
            padding: 2rem 1.5rem;
            max-width: 100%;
            margin: 0 auto;
            width: 100%;
        }

        .page-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid #f1f5f9;
        }

        .page-card h3 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 25px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Table Styling */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #f1f5f9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th {
            background: #007c91;
            padding: 12px 10px;
            text-align: left;
            font-size: 0.82rem;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #005662;
        }

        td {
            padding: 12px 10px;
            font-size: 0.85rem;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .issue-img {
            width: 50px;
            height: 38px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.3s;
            border: 2px solid white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .issue-img:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
        }

        .wrap-text {
            max-width: 180px;
            font-size: 0.82rem;
            line-height: 1.5;
            color: #475569;
            word-break: break-word;
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-Assigned {
            background: #f5f3ff;
            color: #7c3aed;
            border: 1px solid #ddd6fe;
        }

        .status-InProgress {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #dbeafe;
        }

        .status-WaitingParts {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fef3c7;
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
        }
    </style>
</head>
</head>

<body>

    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='techniciandash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="techniciandash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="assigned_issues.php" class="active"><i class="fas fa-tools"></i> Assigned Issues</a></li>
                <li><a href="completedIssues.php"><i class="fas fa-check-circle"></i> Completed Issues</a></li>

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
                <h2>Assigned Issues</h2>
                <div class="header-right">
                    <a href="technicianNotifications.php" class="notification-bell">
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

            <div class="page-container">
                <div class="page-card">
                    <h3>My Current Assignments</h3>

                    <div class="table-wrapper">
                        <?php if ($issues && sqlsrv_has_rows($issues)): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>User</th>
                                        <th>Issue Type</th>
                                        <th>Description</th>
                                        <th>Service Tag</th>
                                        <th>Image</th>
                                        <th>Received Date</th>
                                        <th>Additional Info</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1;
                                    while ($row = sqlsrv_fetch_array($issues, SQLSRV_FETCH_ASSOC)): ?>
                                        <tr style="cursor:pointer;"
                                            onclick="window.location.href='submitRecommend.php?issue_id=<?= $row['issue_id'] ?>'">
                                            <td style="font-weight:700;">#<?= $row['issue_id'] ?></td>
                                            <td style="font-weight:600;">
                                                <?= htmlspecialchars($row['username'] ?? 'Deleted User') ?>
                                            </td>
                                            <td><?= htmlspecialchars($row['issue_type']) ?></td>
                                            <td class="wrap-text"><?= htmlspecialchars($row['issue_description']) ?></td>
                                            <td><code><?= htmlspecialchars($row['service_tag'] ?: '—') ?></code></td>
                                            <td>
                                                <?php if (!empty($row['image'])): ?>
                                                    <img src="../uploads/issues/<?= htmlspecialchars($row['image']) ?>"
                                                        onclick="event.stopPropagation(); openImage(this.src)" class="issue-img">
                                                <?php else: ?>
                                                    <span style="color: #cbd5e1;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600;">
                                                    <?= ($row['received_date'] instanceof DateTime) ? $row['received_date']->format('Y-m-d') : date('Y-m-d', strtotime($row['received_date'])) ?>
                                                </div>
                                                <div style="font-size: 0.75rem; color: #64748b;">
                                                    <?= ($row['received_date'] instanceof DateTime) ? $row['received_date']->format('h:i A') : date('h:i A', strtotime($row['received_date'])) ?>
                                                </div>
                                            </td>
                                            <td class="wrap-text">
                                                <?= !empty($row['additional_information']) ? nl2br(htmlspecialchars($row['additional_information'])) : '<span style="color: #cbd5e1;">—</span>' ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="status-badge status-<?= str_replace(' ', '', htmlspecialchars($row['status'])) ?>">
                                                    <?= htmlspecialchars($row['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding: 50px 20px; color: #64748b;">
                                <i class="fa-solid fa-clipboard-check"
                                    style="font-size: 3rem; color: #e2e8f0; margin-bottom: 15px; display: block;"></i>
                                <p>No active assignments at the moment.</p>
                            </div>
                        <?php endif; ?>
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

    <!-- Image Modal -->
    <div id="imgModal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); justify-content:center; align-items:center; z-index:9999;">
        <span onclick="closeImage()"
            style="position:absolute; top:20px; right:30px; font-size:40px; color:white; cursor:pointer;">&times;</span>
        <img id="modalImg"
            style="max-width:90vw; max-height:90vh; border-radius:12px; box-shadow:0 0 30px rgba(0,0,0,0.5);"
            alt="Preview">
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

        function openImage(src) {
            document.getElementById("modalImg").src = src;
            document.getElementById("imgModal").style.display = "flex";
        }

        function closeImage() {
            document.getElementById("imgModal").style.display = "none";
        }
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>