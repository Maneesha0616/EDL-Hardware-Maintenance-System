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

// Fetch username and profile image
$stmt = sqlsrv_prepare($conn, "SELECT username, profile_image FROM users WHERE id = ?", array(&$user_id));
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

$username = $user['username'];
$profileImage = $user['profile_image'];
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

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

$sql = "SELECT 
            i.issue_id,
            i.issue_type,
            i.issue_description,
            i.service_tag,
            i.image,
            i.received_date,
            i.additional_information,
            i.completed_date,
            i.recommendation,
            i.status,
            u.username AS technician
        FROM issues i
        LEFT JOIN users u ON i.technician_id = u.id
        WHERE i.user_id = ?
        ORDER BY i.received_date DESC";
$params = array(&$user_id);
$stmt = sqlsrv_prepare($conn, $sql, $params);
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$issuesList = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $issuesList[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Issues | Hardware Maintenance System</title>

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
            background: linear-gradient(rgba(248, 250, 252, 0.8), rgba(248, 250, 252, 0.9)), url('../images/dashboard_bg.png');
            background-size: cover;
            background-attachment: fixed;
        }

        /* Header */
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
            align-items: center;
            gap: 20px;
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

        /* Notifications Bell */
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

        /* Page Card */
        .page-container {
            padding: 30px 40px;
            flex-grow: 1;
        }

        .page-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid #f1f5f9;
        }

        .page-card h3 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #1e2a38;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            color: #334155;
            margin-bottom: 10px;
        }

        thead th {
            background: #007c91;
            color: #fff;
            font-weight: 700;
            padding: 14px 16px;
            text-align: left;
        }

        tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        tbody tr:hover {
            background-color: rgba(0, 124, 145, 0.02);
        }

        .wrap-text {
            max-width: 200px;
            white-space: normal;
            overflow-wrap: break-word;
            word-break: break-all;
            line-height: 1.5;
        }

        .issue-img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: 0.2s;
        }

        .issue-img:hover {
            transform: scale(2);
            z-index: 10;
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 800;
            text-align: center;
        }

        .status-Pending {
            background: rgba(245, 158, 11, 0.15);
            color: #92400e;
        }

        .status-InProgress {
            background: rgba(59, 130, 246, 0.15);
            color: #1d4ed8;
        }

        .status-Assigned {
            background: rgba(139, 92, 246, 0.15);
            color: #5b21b6;
        }

        .status-Completed {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
        }

        .status-Rejected {
            background: rgba(239, 68, 68, 0.15);
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            color: #e2e8f0;
            margin-bottom: 15px;
            display: block;
        }

        /* Alerts */
        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 700;
            border: 1px solid rgba(34, 197, 94, 0.1);
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
            width: 60px;
            height: 60px;
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .footer h3 {
            color: #1e2a38;
            font-size: 1.3rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .footer p {
            color: #64748b;
            font-size: 0.95rem;
        }

        /* Responsiveness */
        @media (max-width: 992px) {
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

            .page-container {
                padding: 20px;
            }
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-content {
            position: relative;
            max-width: 90%;
            max-height: 90vh;
            background: #fff;
            padding: 10px;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal-content img {
            max-width: 100%;
            max-height: calc(90vh - 20px);
            border-radius: 8px;
            display: block;
        }

        .close-modal {
            position: absolute;
            top: -15px;
            right: -15px;
            background: #ef4444;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.5rem;
            cursor: pointer;
            border: 4px solid #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s, background 0.2s;
        }

        .close-modal:hover {
            transform: scale(1.1);
            background: #dc2626;
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
                <li><a href="myissues.php" class="active"><i class="fas fa-list-check"></i> My Issues</a></li>


                <li class="logout">
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>My Reported Issues</h2>
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

            <div class="page-container">
                <div class="page-card">
                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert-success">
                            <i class="fas fa-check-circle"></i> Issue submitted successfully!
                        </div>
                    <?php endif; ?>

                    <h3>Maintenance Request History</h3>

                    <?php if (!empty($issuesList)): ?>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Service Tag</th>
                                        <th>Image</th>
                                        <th>Received Date</th>
                                        <th>Additional Info</th>
                                        <th>Technician</th>
                                        <th>Recommendation</th>
                                        <th>Completed Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1;
                                    foreach ($issuesList as $row): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td style="font-weight: 700; color: #1e2a38;">
                                                <?= htmlspecialchars($row['issue_type']) ?>
                                            </td>
                                            <td class="wrap-text"><?= htmlspecialchars($row['issue_description']) ?></td>
                                            <td><code><?= htmlspecialchars($row['service_tag'] ?: '—') ?></code></td>
                                            <td>
                                                <?php if (!empty($row['image'])): ?>
                                                    <img src="../uploads/issues/<?= htmlspecialchars($row['image']) ?>"
                                                        class="issue-img" onclick="openImageModal(this.src)">
                                                <?php else: ?>
                                                    <span style="color: #cbd5e1;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600;">
                                                    <?= !empty($row['received_date']) ? ( ($row['received_date'] instanceof DateTime) ? $row['received_date']->format('Y-m-d') : date('Y-m-d', strtotime($row['received_date'])) ) : '—' ?>
                                                </div>
                                                <div style="font-size: 0.75rem; color: #64748b;">
                                                    <?= !empty($row['received_date']) ? ( ($row['received_date'] instanceof DateTime) ? $row['received_date']->format('h:i A') : date('h:i A', strtotime($row['received_date'])) ) : '' ?>
                                                </div>
                                            </td>
                                            <td class="wrap-text" style="font-size: 0.8rem; color: #64748b;">
                                                <?= htmlspecialchars($row['additional_information'] ?: '—') ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600;">
                                                    <?= htmlspecialchars($row['technician'] ?? 'Pending Assignment') ?>
                                                </div>
                                            </td>
                                            <td class="wrap-text">
                                                <?php if (!empty($row['recommendation'])): ?>
                                                    <div><?= nl2br(htmlspecialchars($row['recommendation'])) ?></div>
                                                <?php else: ?>
                                                    <span style="color: #cbd5e1; font-style: italic;">Awaiting resolution...</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['completed_date'])): ?>
                                                    <div style="font-weight: 600;">
                                                        <?= !empty($row['completed_date']) ? ( ($row['completed_date'] instanceof DateTime) ? $row['completed_date']->format('Y-m-d') : date('Y-m-d', strtotime($row['completed_date'])) ) : '—' ?>
                                                    </div>
                                                    <div style="font-size: 0.75rem; color: #64748b;">
                                                        <?= !empty($row['completed_date']) ? ( ($row['completed_date'] instanceof DateTime) ? $row['completed_date']->format('h:i A') : date('h:i A', strtotime($row['completed_date'])) ) : '' ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: #cbd5e1;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="status-badge status-<?= str_replace(' ', '', htmlspecialchars($row['status'])) ?>">
                                                    <?= htmlspecialchars($row['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-inbox"></i>
                            <p>No maintenance requests found.</p>
                            <a href="userissues.php"
                                style="color: #007c91; font-weight: 600; text-decoration: none; margin-top: 10px; display: inline-block;">Raise
                                your first issue &rarr;</a>
                        </div>
                    <?php endif; ?>
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

    <!-- Image Preview Modal -->
    <div class="modal-overlay" id="imageModal" onclick="closeImageModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="close-modal" onclick="closeImageModal()">
                <i class="fas fa-times"></i>
            </div>
            <img src="" id="modalImage" alt="Issue Image">
        </div>
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

        // Image Modal Logic
        function openImageModal(src) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modalImg.src = src;
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.style.opacity = '1';
                modal.querySelector('.modal-content').style.transform = 'scale(1)';
            }, 10);
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.style.opacity = '0';
            modal.querySelector('.modal-content').style.transform = 'scale(0.9)';
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === "Escape") {
                closeImageModal();
            }
        });
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>