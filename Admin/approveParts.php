<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";
require_once __DIR__ . '/AdminNotifyHelper.php';

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch admin username + profile image
$user_id = $_SESSION['user_id'];
$stmt = sqlsrv_prepare($conn, "SELECT username,   FROM users WHERE id = ?", array($user_id));
if($stmt === false){
    error_log("Database error (admin fetch): " . print_r(sqlsrv_errors(), true));
    die("Failed to fetch admin data.");
}
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

$username = $user['username'] ?? "Admin";
$profileImage = $user['profile_image'] ?? "";
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifSql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$notifParams = [$user_id];
$notifStmt = sqlsrv_query($conn, $notifSql, $notifParams);
if($notifStmt === false){
    error_log("Database error (notification count): " . print_r(sqlsrv_errors(), true));
    die("Failed to count notifications.");
}
$notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

$success = "";
$error = "";

/**
 * Update parent request summary status based on child item statuses
 */
function updatePartsRequestStatus($conn, $request_id)
{
    $qSql = "SELECT item_status, COUNT(*) AS total FROM parts_request_items WHERE request_id = ? GROUP BY item_status";
    $qStmt = sqlsrv_query($conn, $qSql, [$request_id]);
    if($qStmt === false){
        error_log("Database error (status counts): " . print_r(sqlsrv_errors(), true));
        die("Failed to fetch status counts.");
    }
    $rows = [];
    while($row = sqlsrv_fetch_array($qStmt, SQLSRV_FETCH_ASSOC)){
        $rows[] = $row;
    }

    $counts = [
        'Pending'  => 0,
        'Approved' => 0,
        'Rejected' => 0,
        'Supplied' => 0
    ];

    foreach ($rows as $row) {
        $status = $row['item_status'];
        if (isset($counts[$status])) {
            $counts[$status] = intval($row['total']);
        }
    }

    $newStatus = 'Pending';

    if ($counts['Supplied'] > 0 && ($counts['Pending'] > 0 || $counts['Approved'] > 0 || $counts['Rejected'] > 0)) {
        $newStatus = 'Partially Supplied';
    } elseif ($counts['Supplied'] > 0 && ($counts['Pending'] == 0 && $counts['Approved'] == 0)) {
        $newStatus = 'Supplied';
    } elseif ($counts['Approved'] > 0 && ($counts['Pending'] > 0 || $counts['Rejected'] > 0)) {
        $newStatus = 'Partially Approved';
    } elseif ($counts['Approved'] > 0 && ($counts['Pending'] == 0 && $counts['Rejected'] == 0)) {
        $newStatus = 'Approved';
    } elseif ($counts['Rejected'] > 0 && ($counts['Pending'] == 0 && $counts['Approved'] == 0 && $counts['Supplied'] == 0)) {
        $newStatus = 'Rejected';
    }

    $upSql = "UPDATE parts_requests SET status = ? WHERE request_id = ?";
    sqlsrv_query($conn, $upSql, [$newStatus, $request_id]);
}

// APPROVE / REJECT ONE ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_item_id'])) {

    $action = trim($_POST['action']);
    $request_item_id = intval($_POST['request_item_id']);

    if ($request_item_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $error = "Invalid item action.";
    } else {
        sqlsrv_begin_transaction($conn);

        try {
            // Fetch selected item row + parent request + stock info
            $qSql = "SELECT pri.id, pri.request_id, pri.item_id, pri.quantity_requested, pri.quantity_approved, pri.quantity_issued, pri.item_status, pr.issue_id, pr.technician_id, pr.status AS request_status, it.item_name, it.brand, it.capacity_type, it.quantity AS stock_qty FROM parts_request_items pri INNER JOIN parts_requests pr ON pri.request_id = pr.request_id INNER JOIN items it ON pri.item_id = it.item_id WHERE pri.id = ?";
            $qStmt = sqlsrv_query($conn, $qSql, [$request_item_id]);
            if($qStmt === false){
                error_log("Database error (fetch item row): " . print_r(sqlsrv_errors(), true));
                die("Failed to fetch item row.");
            }
            $itemRow = sqlsrv_fetch_array($qStmt, SQLSRV_FETCH_ASSOC);

            if (!$itemRow) {
                throw new Exception("Requested item not found.");
            }

            if ($itemRow['item_status'] !== 'Pending') {
                throw new Exception("This item has already been processed.");
            }

            $request_id    = intval($itemRow['request_id']);
            $issue_id      = intval($itemRow['issue_id']);
            $technician_id = intval($itemRow['technician_id']);
            $item_id       = intval($itemRow['item_id']);
            $qty           = intval($itemRow['quantity_requested']);
            $item_name     = $itemRow['item_name'] ?? 'Item';
            $brand         = $itemRow['brand'] ?? '';
            $capacity_type = $itemRow['capacity_type'] ?? '';

            if ($action === 'approve') {
                // Check item stock
                $itSql = "SELECT quantity FROM items WHERE item_id = ?";
                $itStmt = sqlsrv_query($conn, $itSql, [$item_id]);
                $stockRow = sqlsrv_fetch_array($itStmt, SQLSRV_FETCH_ASSOC);

                if (!$stockRow) {
                    throw new Exception("Inventory item not found.");
                }

                if (intval($stockRow['quantity']) < $qty) {
                    throw new Exception("Not enough stock for {$item_name}. Available: " . intval($stockRow['quantity']));
                }

                $upSql = "UPDATE parts_request_items SET item_status = 'Approved', quantity_approved = ?, quantity_issued = 0 WHERE id = ?";
                sqlsrv_query($conn, $upSql, [$qty, $request_item_id]);

                $title = "Approved Spare Part Item";
                $message = "An admin has approved a spare part request item for Request ID {$request_id} related to Issue ID {$issue_id}. "
                         . "The approved item is {$item_name}"
                         . (!empty($brand) ? " ({$brand})" : "")
                         . (!empty($capacity_type) ? " with specification {$capacity_type}" : "")
                         . ". The approved quantity is {$qty}. "
                         . "Please proceed with issuing this item from the inventory.";

                $skSql = "SELECT id FROM users WHERE role = 'storekeeper'";
                $skStmt = sqlsrv_query($conn, $skSql);
                $storekeepers = [];
                while($sk = sqlsrv_fetch_array($skStmt, SQLSRV_FETCH_ASSOC)) {
                    $storekeepers[] = $sk;
                }

                if ($storekeepers) {
                    foreach ($storekeepers as $sk) {
                        $storekeeper_id = intval($sk['id']);
                        $notifySql = "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())";
                        sqlsrv_query($conn, $notifySql, [$storekeeper_id, $title, $message]);
                    }
                }

                $success = "Item approved successfully.";
                log_system_activity($conn, $_SESSION['user_id'], 'Approve Spare Part', "Approved {$qty} x {$item_name} for request ID {$request_id}");
            } else {
                $upSql = "UPDATE parts_request_items SET item_status = 'Rejected', quantity_approved = 0, quantity_issued = 0 WHERE id = ?";
                sqlsrv_query($conn, $upSql, [$request_item_id]);

                $success = "Item rejected successfully.";
                log_system_activity($conn, $_SESSION['user_id'], 'Reject Spare Part', "Rejected {$item_name} for request ID {$request_id}");
            }

            // Update parent request summary status
            updatePartsRequestStatus($conn, $request_id);

            // Keep issue active if any item got approved
            $chkApprovedStmt = sqlsrv_query($conn, "SELECT COUNT(*) AS total FROM parts_request_items WHERE request_id = ? AND item_status = 'Approved'", [$request_id]);
            if($chkApprovedStmt === false){
                error_log("Database error (check approved): " . print_r(sqlsrv_errors(), true));
                die("Failed to check approved items.");
            }
            $approvedRow = sqlsrv_fetch_array($chkApprovedStmt, SQLSRV_FETCH_ASSOC);

            if (($approvedRow['total'] ?? 0) > 0) {
                $updIssueSql = "UPDATE issues SET status = 'In Progress' WHERE issue_id = ?";
                $updIssueStmt = sqlsrv_query($conn, $updIssueSql, [$issue_id]);
                if($updIssueStmt === false){
                    error_log("Database error (update issue status): " . print_r(sqlsrv_errors(), true));
                    die("Failed to update issue status.");
                }
            }

            sqlsrv_commit($conn);

        } catch (Exception $e) {
            sqlsrv_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// Fetch pending item rows only
$pendingSql = "SELECT pri.id, pri.request_id, pri.item_id, pri.quantity_requested, pri.quantity_approved, pri.quantity_issued, pri.item_status, pr.issue_id, pr.created_at, u.username AS technician_name, it.item_name, it.brand, it.capacity_type, it.quantity AS stock_qty FROM parts_request_items pri INNER JOIN parts_requests pr ON pri.request_id = pr.request_id LEFT JOIN users u ON pr.technician_id = u.id LEFT JOIN items it ON pri.item_id = it.item_id WHERE pri.item_status = 'Pending' ORDER BY pri.request_id DESC, pri.id ASC";
$pendingStmt = sqlsrv_query($conn, $pendingSql);
if($pendingStmt === false){
    error_log("Database error (fetch pending): " . print_r(sqlsrv_errors(), true));
    die("Failed to fetch pending items.");
}
$pending = [];
while($row = sqlsrv_fetch_array($pendingStmt, SQLSRV_FETCH_ASSOC)){
    $pending[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Approve Parts</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        }

        body {
            background-color: #f8fafc;
            color: #1e293b;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --secondary: #1e2a38;
            --text-muted: #94a3b8;
            --sidebar-width: 260px;
        }

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

        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        header {
            background: #fff;
            padding: 1rem 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 900;
        }

        header h2 {
            font-size: 1.25rem;
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
            padding: 6px 15px;
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
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .dropdown {
            display: none;
            position: absolute;
            right: 20px;
            top: 60px;
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
            width: 40px;
            height: 40px;
            background: #f1f5f9;
            border-radius: 10px;
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
            font-size: 9px;
            font-weight: 800;
            min-width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
        }

        .container {
            padding: 15px;
            flex-grow: 1;
        }

        .section-card {
            background: #ffffff;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            border: 1px solid #f1f5f9;
        }

        .section-title {
            font-size: 1.15rem;
            font-weight: 800;
            margin-bottom: 15px;
            color: #1e2a38;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.85rem;
            color: #334155;
            margin-bottom: 10px;
            border-radius: 12px 12px 0 0;
            overflow: hidden;
        }

        thead th {
            background: #007c91;
            color: #fff;
            font-weight: 700;
            font-size: 0.82rem;
            padding: 10px 8px;
            text-align: left;
            border-bottom: none;
            white-space: nowrap;
        }

        tbody td {
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        tbody tr:nth-of-type(even) {
            background-color: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 800;
            text-align: center;
            background-color: rgba(245, 158, 11, 0.15);
            color: #92400e;
        }

        .stock-low {
            color: #b91c1c;
            font-weight: 800;
        }

        .stock-ok {
            color: #166534;
            font-weight: 800;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            color: #991b1b;
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .btn {
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-approve {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
        }

        .btn-reject {
            background: rgba(239, 68, 68, 0.15);
            color: #991b1b;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .footer {
            background: #ffffff;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            margin-top: auto;
        }

        .footer img {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .footer h3 {
            color: #1e2a38;
            font-size: 1.1rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .footer p {
            color: #64748b;
            font-size: 0.85rem;
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
                <li><a class="active" href="approveParts.php"><i class="fas fa-check-circle"></i> Approve Parts</a></li>
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
                <h2>Approve Parts</h2>
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
                        <a href="../userprofile.php"><i class="fas fa-user"></i> View Profile</a>
                        <a href="../logout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </header>

            <div class="container">
                <div class="section-card">
                    <div class="section-title">Pending Spare Part Items</div>

                    <?php if (!empty($success)): ?>
                        <div class="alert-success">
                            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="alert-error">
                            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Request ID</th>
                                    <th>Issue ID</th>
                                    <th>Technician</th>
                                    <th>Item Name</th>
                                    <th>Brand</th>
                                    <th>Capacity/Type</th>
                                    <th>Requested Qty</th>
                                    <th>Stock</th>
                                    <th>Item Status</th>
                                    <th>Created At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $i = 1;
                                if (!empty($pending)):
                                    foreach ($pending as $row):
                                        $stock = intval($row['stock_qty']);
                                        $reqQty = intval($row['quantity_requested']);
                                        $low = ($stock < $reqQty);
                                        ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td><?= intval($row['request_id']) ?></td>
                                            <td><?= intval($row['issue_id']) ?></td>
                                            <td><?= htmlspecialchars($row['technician_name'] ?? 'Technician') ?></td>
                                            <td><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['brand'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['capacity_type'] ?? '-') ?></td>
                                            <td><b><?= $reqQty ?></b></td>
                                            <td class="<?= $low ? 'stock-low' : 'stock-ok' ?>"><?= $stock ?></td>
                                            <td><span class="badge"><?= htmlspecialchars($row['item_status']) ?></span></td>
                                            <td><?= !empty($row['created_at']) ? ( ($row['created_at'] instanceof DateTime) ? $row['created_at']->format('Y-m-d h:i A') : date('Y-m-d h:i A', strtotime($row['created_at'])) ) : '—' ?>
                                            </td>
                                            <td>
                                                <form method="post" style="display:flex; gap:10px;"
                                                    onsubmit="return confirmAction(event)">
                                                    <input type="hidden" name="request_item_id"
                                                        value="<?= intval($row['id']) ?>">

                                                    <button class="btn btn-approve" name="action" value="approve" type="submit"
                                                        <?= $low ? 'disabled title="Not enough stock"' : '' ?>>
                                                        <i class="fa-solid fa-check"></i> Approve
                                                    </button>

                                                    <button class="btn btn-reject" name="action" value="reject" type="submit">
                                                        <i class="fa-solid fa-xmark"></i> Reject
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php
                                    endforeach;
                                else:
                                    ?>
                                    <tr>
                                        <td colspan="12"
                                            style="padding:16px;color:#64748b;font-weight:700;text-align:center;">
                                            No pending spare part items.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <div class="container" style="padding: 0; text-align: center;">
                    <img src="../images/hardware_logo.png" alt="Logo"
                        style="width: 40px; border-radius: 8px; margin-bottom: 1rem;">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem; color: #64748b;">&copy; <?php echo date('Y'); ?> Electricity
                        Distribution Lanka (Private) Limited</p>
                    <p style="margin-top: 15px; font-size: 0.85rem; opacity: 0.5; color: #64748b;">Helping you manage
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

        function confirmAction(e) {
            const btn = document.activeElement;
            if (!btn) return true;

            if (btn.value === "approve") {
                return confirm("Approve this item? Stock will be deducted later by the storekeeper.");
            } else {
                return confirm("Reject this item?");
            }
        }
    </script>
    <script src="/HMS/desktop_notify.php"></script>
</body>

</html>