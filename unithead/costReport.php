<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'unithead') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch unit head details
$stmt = sqlsrv_prepare($conn, "SELECT id, username, name, profile_image FROM users WHERE id = ? AND role = 'unithead'", array(&$user_id));
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$user) {
    die("Unauthorized access.");
}

$username = $user['username'] ?? 'Unit Head';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$notifStmt = sqlsrv_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", array(&$user_id));
if ($notifStmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($notifStmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$unreadCount = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC)['total'] ?? 0;

// Filters
$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($status_filter !== '' && in_array($status_filter, ['Pending', 'Approved', 'Rejected'], true)) {
    $where .= " AND pr.status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

if ($search !== '') {
    $where .= " AND (pr.item_name LIKE ? OR u.name LIKE ? OR pr.justification LIKE ?) ";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

// Summary queries
$summarySql = "
    SELECT 
        COUNT(*) AS total_requests,
        SUM(CASE WHEN pr.status = 'Approved' THEN 1 ELSE 0 END) AS approved_requests,
        SUM(CASE WHEN pr.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_requests,
        SUM(CASE WHEN pr.status = 'Pending' THEN 1 ELSE 0 END) AS pending_requests,
        COALESCE(SUM(
            CASE 
                WHEN pr.status = 'Approved' 
                THEN pr.quantity * COALESCE(i.unit_price, 0)
                ELSE 0
            END
        ), 0) AS total_approved_cost
    FROM purchase_requests pr
    LEFT JOIN items i ON i.item_name = pr.item_name
    LEFT JOIN users u ON pr.admin_id = u.id
    $where
";

$summaryStmt = sqlsrv_prepare($conn, $summarySql, $params);
if ($summaryStmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($summaryStmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$summary = sqlsrv_fetch_array($summaryStmt, SQLSRV_FETCH_ASSOC);

// Main report query
$sql = "
    SELECT 
        pr.*,
        u.name AS admin_name,
        CASE 
            WHEN pr.status = 'Approved' THEN COALESCE(i.unit_price, 0.00)
            ELSE NULL
        END AS unit_price,
        CASE 
            WHEN pr.status = 'Approved' THEN (pr.quantity * COALESCE(i.unit_price, 0.00))
            ELSE NULL
        END AS total_cost
    FROM purchase_requests pr
    INNER JOIN users u ON pr.admin_id = u.id
    LEFT JOIN items i ON i.item_name = pr.item_name
    $where
    ORDER BY pr.created_at DESC
";

$reportStmt = sqlsrv_prepare($conn, $sql, $params);
if ($reportStmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($reportStmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$reports = [];
while ($row = sqlsrv_fetch_array($reportStmt, SQLSRV_FETCH_ASSOC)) {
    $reports[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Cost Reports | Hardware Maintenance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700" rel="stylesheet">

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
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

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

        .notification-btn {
            position: relative;
            padding: 10px;
            color: #64748b;
            text-decoration: none;
            font-size: 1.2rem;
        }

        .notif-count {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #ef4444;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 100px;
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
            z-index: 100;
        }

        .dropdown a {
            display: block;
            padding: 12px 20px;
            color: #1e2a38;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .dropdown a:hover {
            background: #f8fafc;
            color: #007c91;
        }

        .container {
            padding: 30px 40px;
        }

        .section-card {
            background: #fff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            border: 1px solid #f1f5f9;
            margin-bottom: 25px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
            gap: 15px;
            align-items: flex-end;
        }

        .field label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        .btn {
            padding: 11px 20px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.85rem;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #007c91;
            color: #fff;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #64748b;
        }

        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(0.95);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: #fff;
            padding: 25px;
            border-radius: 20px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
        }

        .stat-box h4 {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 10px;
        }

        .stat-box h2 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e2a38;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #f1f5f9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th {
            background: #f8fafc;
            padding: 18px 20px;
            text-align: left;
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #f1f5f9;
        }

        td {
            padding: 18px 20px;
            font-size: 0.85rem;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 100px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .status-pending {
            background: #fffbeb;
            color: #92400e;
        }

        .status-approved {
            background: #f0fdf4;
            color: #166534;
        }

        .status-rejected {
            background: #fef2f2;
            color: #dc2626;
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

            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="layout">
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='unitheaddash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>
            <ul class="menu">
                <li><a href="unitheaddash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="approveRequests.php"><i class="fas fa-check-circle"></i> Approve Requests</a></li>
                <li><a class="active" href="costReport.php"><i class="fas fa-file-invoice-dollar"></i> Cost Reports</a></li>
                <li class="logout"><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>Department Cost Analytics</h2>
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
                            <i class="fa-solid fa-circle-user" style="font-size:1.2rem;color:#64748b;"></i>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($username); ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.8rem;color:#64748b;"></i>
                    </button>

                    <div class="dropdown" id="profileDropdown">
                        <a href="../userprofile.php"><i class="fas fa-user"></i> Profile</a>
                        <a href="../logout.php" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </header>

            <div class="container">
                <div class="section-card">
                    <form method="GET" class="filter-grid">
                        <div class="field">
                            <label>Search Queries</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search); ?>"
                                placeholder="Search item, admin or justification...">
                        </div>

                        <div class="field">
                            <label>Filter Status</label>
                            <select name="status">
                                <option value="">All Requests</option>
                                <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending Approval</option>
                                <option value="Approved" <?= $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved Only</option>
                                <option value="Rejected" <?= $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected Only</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="costReport.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</a>
                    </form>
                </div>

                <div class="stats-grid">
                    <div class="stat-box">
                        <h4>All Requests</h4>
                        <h2><?= (int)($summary['total_requests'] ?? 0); ?></h2>
                    </div>

                    <div class="stat-box" style="border-left: 4px solid #166534;">
                        <h4>Approved</h4>
                        <h2><?= (int)($summary['approved_requests'] ?? 0); ?></h2>
                    </div>

                    <div class="stat-box" style="border-left: 4px solid #dc2626;">
                        <h4>Rejected</h4>
                        <h2><?= (int)($summary['rejected_requests'] ?? 0); ?></h2>
                    </div>

                    <div class="stat-box" style="border-left: 4px solid #007c91;">
                        <h4>Total Value</h4>
                        <h2>Rs. <?= number_format((float)($summary['total_approved_cost'] ?? 0), 2); ?></h2>
                    </div>
                </div>

                <div class="section-card" style="padding: 0;">
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Item Name</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total Cost</th>
                                    <th>Status</th>
                                    <th>Admin</th>
                                    <th>Justification</th>
                                    <th>Remarks</th>
                                    <th>Created At</th>
                                    <th>Reviewed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($reports)): ?>
                                    <?php foreach ($reports as $row): ?>
                                        <tr>
                                            <td style="font-weight:700;color:#64748b;">#<?= $row['purchase_request_id']; ?></td>
                                            <td style="font-weight:600;"><?= htmlspecialchars($row['item_name']); ?></td>
                                            <td><?= (int)$row['quantity']; ?></td>

                                            <td>
                                                <?php if ($row['status'] === 'Approved' && $row['unit_price'] !== null): ?>
                                                    Rs. <?= number_format((float)$row['unit_price'], 2); ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>

                                            <td style="font-weight:700;">
                                                <?php if ($row['status'] === 'Approved' && $row['total_cost'] !== null): ?>
                                                    Rs. <?= number_format((float)$row['total_cost'], 2); ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php
                                                $s = $row['status'];
                                                $sc = ($s === 'Approved') ? 'status-approved' : (($s === 'Rejected') ? 'status-rejected' : 'status-pending');
                                                ?>
                                                <span class="status-badge <?= $sc ?>"><?= htmlspecialchars($s); ?></span>
                                            </td>

                                            <td><?= htmlspecialchars($row['admin_name']); ?></td>

                                            <td style="font-size:0.8rem;color:#64748b;max-width:200px;">
                                                <?= htmlspecialchars($row['justification']); ?>
                                            </td>

                                            <td style="font-size:0.8rem;color:#64748b;max-width:200px;">
                                                <?= !empty($row['unit_head_remarks']) ? htmlspecialchars($row['unit_head_remarks']) : '—' ?>
                                            </td>

                                            <td style="font-size:0.75rem;"><?= ($row['created_at'] instanceof DateTime) ? $row['created_at']->format('Y-m-d h:i A') : htmlspecialchars($row['created_at']); ?></td>

                                            <td style="font-size:0.75rem;">
                                                <?= !empty($row['reviewed_at']) ? (($row['reviewed_at'] instanceof DateTime) ? $row['reviewed_at']->format('Y-m-d h:i A') : htmlspecialchars($row['reviewed_at'])) : '—' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="11" style="text-align:center;padding:40px;color:#94a3b8;">
                                            No cost reporting data found for the current filters.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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