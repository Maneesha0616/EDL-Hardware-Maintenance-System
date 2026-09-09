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

// Fetch technician info
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$params = array(&$user_id);
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'Technician';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$sql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$params = array(&$user_id);
$notifStmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($notifStmt);
$notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Submit Recommendation
$success = "";
if (isset($_POST['submit'])) {
    $issue_id = $_POST['issue_id'];
    $recommendation = $_POST['recommendation'];
    $status = $_POST['status'];

    $sql = "UPDATE issues SET recommendation = ?, status = ? WHERE issue_id = ? AND technician_id = ?";
    $params = array(&$recommendation, &$status, &$issue_id, &$user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if (sqlsrv_execute($stmt)) {
        $success = "Recommendation and Status Updated Successfully!";
        // Notify user about status change
        $sql = "SELECT user_id FROM issues WHERE issue_id = ?";
        $params = array(&$issue_id);
        $checkUser = sqlsrv_prepare($conn, $sql, $params);
        sqlsrv_execute($checkUser);
        $uRes = sqlsrv_fetch_array($checkUser, SQLSRV_FETCH_ASSOC);
        $issue_user_id = $uRes['user_id'] ?? null;
        if ($issue_user_id) {
            switch ($status) {
                case 'Resolved':
                    $notifTitle = "Issue Resolved";
                    $notifMsg = "Your reported issue (#{$issue_id}) has been marked as resolved by Technician {$username}.";
                    break;
                case 'In Progress':
                    $notifTitle = "Issue In Progress";
                    $notifMsg = "Your reported issue (#{$issue_id}) is now being worked on by Technician {$username}.";
                    break;
                case 'Waiting Parts':
                    $notifTitle = "Issue Waiting for Parts";
                    $notifMsg = "Your reported issue (#{$issue_id}) is awaiting spare parts. Technician {$username} has requested the necessary components.";
                    break;
                case 'Rejected':
                    $notifTitle = "Issue Rejected";
                    $notifMsg = "Your reported issue (#{$issue_id}) has been reviewed and rejected by Technician {$username}.";
                    break;
                default:
                    $notifTitle = "Issue Status Update";
                    $notifMsg = "Your reported issue (#{$issue_id}) status has been updated to '{$status}' by Technician {$username}.";
                    break;
            }
            $sql = "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())";
            $params = array(&$issue_user_id, &$notifTitle, &$notifMsg);
            $nStmt = sqlsrv_prepare($conn, $sql, $params);
            if ($nStmt) {
                sqlsrv_execute($nStmt);
            }
        }
    }
}

// Fetch assigned issues
$sql = "SELECT i.*, u.username AS user_name FROM issues i LEFT JOIN users u ON i.user_id = u.id WHERE i.technician_id = ? ORDER BY i.received_date DESC";
$params = array(&$user_id);
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);
$issues = $stmt; // use sqlsrv_fetch_array in loop
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Issue Recommendations | Hardware Maintenance System</title>
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
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
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
            margin-bottom: 25px;
            color: var(--secondary);
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
            background: #f8fafc;
            padding: 18px 15px;
            text-align: left;
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #f1f5f9;
        }

        td {
            padding: 18px 15px;
            font-size: 0.85rem;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        tr:hover {
            background-color: #fcfdfe;
        }

        .issue-thumb {
            width: 65px;
            height: 48px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            transition: 0.2s;
        }

        .issue-thumb:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .wrap-text {
            max-width: 180px;
            line-height: 1.5;
            color: #475569;
        }

        /* Form Elements in Table */
        textarea {
            width: 100%;
            min-height: 90px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.85rem;
            resize: none;
            transition: 0.3s;
            font-weight: 500;
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(0, 124, 145, 0.05);
        }

        select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        select:focus {
            border-color: var(--primary);
            outline: none;
        }

        .update-btn {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            width: 100%;
            transition: 0.3s;
            box-shadow: 0 4px 10px rgba(34, 197, 94, 0.2);
        }

        .update-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(34, 197, 94, 0.3);
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid #bbf7d0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(22, 101, 52, 0.05);
        }

        /* Footer Styling */
        .footer {
            margin-top: auto;
            padding: 2.5rem;
            text-align: center;
            background: white;
            border-top: 1px solid #f1f5f9;
        }

        .footer img {
            width: 50px;
            margin-bottom: 1rem;
            opacity: 0.8;
            filter: grayscale(1);
            transition: 0.3s;
        }

        .footer:hover img {
            opacity: 1;
            filter: grayscale(0);
        }

        .footer p {
            font-size: 0.9rem;
            color: #94a3b8;
            font-weight: 500;
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
                <li><a href="assigned_issues.php"><i class="fas fa-tools"></i> Assigned Issues</a></li>
                <li><a href="completedIssues.php" class="active"><i class="fas fa-check-circle"></i> Completed
                        Issues</a></li>

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
                <h2>Manage Recommendations</h2>
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
                <?php if (!empty($success)): ?>
                    <div class="alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <div class="page-card">
                    <h3>Update Issue Progress</h3>

                    <div class="table-wrapper">
                        <?php if ($issues->num_rows > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Issue Info</th>
                                        <th>Visuals</th>
                                        <th>Dates</th>
                                        <th>Recommendation</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = sqlsrv_fetch_array($issues, SQLSRV_FETCH_ASSOC)): ?>
                                        <tr>
                                            <form method="POST">
                                                <td style="font-weight:600;">
                                                    <?= htmlspecialchars($row['user_name'] ?? 'Deleted') ?></td>
                                                <td class="wrap-text">
                                                    <div style="font-weight:700; color:#007c91;">
                                                        <?= htmlspecialchars($row['issue_type']) ?></div>
                                                    <div style="font-size:0.75rem; color:#64748b;">
                                                        <?= htmlspecialchars($row['issue_description']) ?></div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($row['image'])): ?>
                                                        <img src="../uploads/issues/<?= htmlspecialchars($row['image']) ?>"
                                                            class="issue-thumb" onclick="openImage(this.src)"
                                                            style="cursor:pointer;">
                                                    <?php else: ?>
                                                        <span style="color:#cbd5e1;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="font-size:0.7rem; font-weight:600;">Reported:
                                                        <?= date("Y-m-d", strtotime($row['received_date'])) ?></div>
                                                    <?php if (!empty($row['assigned_date'])): ?>
                                                        <div style="font-size:0.7rem; color:#64748b;">Assigned:
                                                            <?= date("Y-m-d", strtotime($row['assigned_date'])) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <textarea name="recommendation"
                                                        placeholder="Maintenance steps..."><?= htmlspecialchars($row['recommendation']) ?></textarea>
                                                </td>
                                                <td>
                                                    <select name="status" required>
                                                        <option value="Pending" <?= ($row['status'] == "Pending") ? "selected" : "" ?>>
                                                            Pending</option>
                                                        <option value="In Progress" <?= ($row['status'] == "In Progress") ? "selected" : "" ?>>In Progress</option>
                                                        <option value="Waiting Parts" <?= ($row['status'] == "Waiting Parts") ? "selected" : "" ?>>Waiting Parts</option>
                                                        <option value="Resolved" <?= ($row['status'] == "Resolved" || $row['status'] == "Completed") ? "selected" : "" ?>>Resolved</option>
                                                        <option value="Rejected" <?= ($row['status'] == "Rejected") ? "selected" : "" ?>>Rejected</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="hidden" name="issue_id" value="<?= $row['issue_id'] ?>">
                                                    <button type="submit" name="submit" class="update-btn">Update</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding: 40px; color:#64748b;">No issues found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <img src="../images/hardware_logo.png" alt="Logo">
                <h3>Hardware Maintenance System</h3>
                <p>&copy; <?php echo date('Y'); ?> Ceylon Electricity Board (CP1)</p>
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