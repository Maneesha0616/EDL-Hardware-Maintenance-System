<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'unithead') {
    header("Location: ../login.php");
    exit();
}

date_default_timezone_set("Asia/Colombo");

$user_id = $_SESSION['user_id'];
$success = "";
$error = "";

// Fetch unit head details
$stmt = sqlsrv_prepare($conn, "SELECT id, username, name, profile_image FROM users WHERE id = ? AND role = 'unithead'", array(&$user_id));
if ($stmt === false) { die("Prepare failed: " . print_r(sqlsrv_errors(), true)); }
if (!sqlsrv_execute($stmt)) { die("Execution failed: " . print_r(sqlsrv_errors(), true)); }
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$user) {
    die("Unauthorized access.");
}

$username = $user['username'] ?? 'Unit Head';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$notifStmt = sqlsrv_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", array(&$user_id));
if ($notifStmt === false) { die("Prepare failed: " . print_r(sqlsrv_errors(), true)); }
if (!sqlsrv_execute($notifStmt)) { die("Execution failed: " . print_r(sqlsrv_errors(), true)); }
$unreadCount = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC)['total'] ?? 0;

// HANDLE APPROVE / REJECT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase_request_id'], $_POST['action'])) {
    $purchase_request_id = intval($_POST['purchase_request_id']);
    $action = trim($_POST['action']);
    $remarks = trim($_POST['remarks'] ?? '');

    if (!in_array($action, ['Approved', 'Rejected'], true)) {
        $error = "Invalid action.";
    } else {
        $prStmt = sqlsrv_prepare($conn, "SELECT purchase_request_id, admin_id, item_id, item_name, quantity, unit_price, request_type FROM purchase_requests WHERE purchase_request_id = ? AND status = 'Pending'", array(&$purchase_request_id));
        if ($prStmt === false) { die("Prepare failed: " . print_r(sqlsrv_errors(), true)); }
        if (!sqlsrv_execute($prStmt)) { die("Execution failed: " . print_r(sqlsrv_errors(), true)); }
        $purchase = sqlsrv_fetch_array($prStmt, SQLSRV_FETCH_ASSOC);

        if (!$purchase) {
            $error = "Purchase request not found or already processed.";
        } else {
            sqlsrv_begin_transaction($conn);
            try {
                // Update purchase request
                $upStmt = sqlsrv_prepare($conn, "UPDATE purchase_requests SET status = ?, unit_head_remarks = ?, reviewed_at = GETDATE() WHERE purchase_request_id = ?", array(&$action, &$remarks, &$purchase_request_id));
                if ($upStmt === false || !sqlsrv_execute($upStmt))
                    throw new Exception("Failed to update purchase request.");

                // If approved, store in inventory
                if ($action === 'Approved') {
                    $item_name = trim($purchase['item_name']);
                    $quantity = intval($purchase['quantity']);
                    $unit_price = floatval($purchase['unit_price']);
                    $item_id = $purchase['item_id'];

                    $existingItem = null;
                    if ($item_id) {
                        $checkItem = sqlsrv_prepare($conn, "SELECT TOP 1 item_id, quantity FROM items WHERE item_id = ?", array(&$item_id));
                        if ($checkItem !== false && sqlsrv_execute($checkItem)) {
                            $existingItem = sqlsrv_fetch_array($checkItem, SQLSRV_FETCH_ASSOC);
                        }
                    }

                    if (!$existingItem) {
                        $checkItem = sqlsrv_prepare($conn, "SELECT TOP 1 item_id, quantity FROM items WHERE item_name = ?", array(&$item_name));
                        if ($checkItem !== false && sqlsrv_execute($checkItem)) {
                            $existingItem = sqlsrv_fetch_array($checkItem, SQLSRV_FETCH_ASSOC);
                        }
                    }

                    if ($existingItem) {
                        $newQty = intval($existingItem['quantity']) + $quantity;
                        $updateItem = sqlsrv_prepare($conn, "UPDATE items SET quantity = ?, unit_price = ?, updated_at = GETDATE() WHERE item_id = ?", array(&$newQty, &$unit_price, &$existingItem['item_id']));
                        if ($updateItem === false || !sqlsrv_execute($updateItem)) {
                            throw new Exception("Failed to update inventory.");
                        }
                    } else {
                        $capacity_type = null;
                        $brand = null;
                        $reorder_level = 5;
                        $insertItem = sqlsrv_prepare($conn, "INSERT INTO items (item_name, capacity_type, brand, quantity, reorder_level, unit_price, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())", array(&$item_name, &$capacity_type, &$brand, &$quantity, &$reorder_level, &$unit_price));
                        if ($insertItem === false || !sqlsrv_execute($insertItem)) {
                            throw new Exception("Failed to insert new item into inventory.");
                        }
                    }
                }

                // NOTIFY ADMIN
                $title = "Purchase Request " . $action;
                $message = "Purchase request for item '{$purchase['item_name']}' has been {$action}.";
                if ($action === 'Approved') {
                    $message .= " The items have been successfully added to the inventory.";
                }
                
                $admin_id = $purchase['admin_id'];
                $notifyStmt = sqlsrv_prepare($conn, "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())", array(&$admin_id, &$title, &$message));
                if ($notifyStmt === false || !sqlsrv_execute($notifyStmt))
                    throw new Exception("Failed to send notification.");

                sqlsrv_commit($conn);
                $success = $action === 'Approved' ? "Purchase request approved and stored in inventory." : "Purchase request rejected successfully.";
            } catch (Exception $e) {
                sqlsrv_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }
}

// FETCH REQUESTS
$reqResult = sqlsrv_query($conn, "SELECT pr.*, u.name AS admin_name FROM purchase_requests pr INNER JOIN users u ON pr.admin_id = u.id ORDER BY pr.created_at DESC");
$requests = [];
if ($reqResult) {
    while ($row = sqlsrv_fetch_array($reqResult, SQLSRV_FETCH_ASSOC)) {
        $requests[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Approve Requests | Hardware Maintenance System</title>
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

        /* Table Section */
        .container {
            padding: 30px 40px;
        }

        .section-card {
            background: #fff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            border: 1px solid #f1f5f9;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #1e2a38;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            color: #007c91;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #dcfce7;
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #f1f5f9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #007c91;
            padding: 14px 16px;
            text-align: left;
            font-size: 0.82rem;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: none;
        }

        td {
            padding: 14px 16px;
            font-size: 0.88rem;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .status-pending {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fef3c7;
        }

        .status-approved {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #dcfce7;
        }

        .status-rejected {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }

        textarea {
            width: 100%;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 0.82rem;
            resize: vertical;
            min-height: 50px;
            min-width: 180px;
        }

        textarea:focus {
            outline: none;
            border-color: #007c91;
            box-shadow: 0 0 0 3px rgba(0, 124, 145, 0.1);
        }

        .btn {
            padding: 10px 18px;
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
            justify-content: center;
        }

        .btn-pdf {
            background: #f1f5f9;
            color: #1e2a38;
        }

        .btn-approve {
            background: #007c91;
            color: #fff;
            width: 100%;
        }

        .btn-reject {
            background: #ef4444;
            color: #fff;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(0.95);
        }

        .action-box {
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 120px;
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
        }
    </style>
</head>

<body>

    <div class="layout">
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='unitheaddash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS </h1>
            </div>
            <ul class="menu">
                <li><a href="unitheaddash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a class="active" href="approveRequests.php"><i class="fas fa-check-circle"></i> Approve
                        Requests</a></li>
                <li><a href="costReport.php"><i class="fas fa-file-invoice-dollar"></i> Cost Reports</a></li>
                <li class="logout"><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>Review Acquisition Requests</h2>
                <div class="header-right">
                    <a href="unitheadNotifications.php" class="notification-btn"><i
                            class="fas fa-bell"></i><?php if ($unreadCount > 0): ?><span
                                class="notif-count"><?= $unreadCount ?></span><?php endif; ?></a>
                    <button class="profile-btn" onclick="toggleDropdown()">
                        <?php if ($hasProfileImage): ?><img src="../uploads/<?= htmlspecialchars($profileImage); ?>"
                                class="profile-pic"><?php else: ?><i class="fa-solid fa-circle-user"
                                style="font-size:1.2rem;color:#64748b;"></i><?php endif; ?>
                        <span><?= htmlspecialchars($username); ?></span><i class="fas fa-chevron-down"
                            style="font-size:0.8rem;color:#64748b;"></i>
                    </button>
                    <div class="dropdown" id="profileDropdown">
                        <a href="../userprofile.php"><i class="fas fa-user"></i> Profile</a>
                        <a href="../logout.php" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </header>

            <div class="container">
                <div class="section-card">
                    <div class="section-title"><i class="fas fa-shopping-cart"></i> Requests History</div>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
                    <?php endif; ?>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Admin</th>
                                    <th>Item Details</th>
                                    <th>Estimated Cost</th>
                                    <th>Letter</th>
                                    <th>Status</th>
                                    <th>Remark</th>
                                    <th>Reviewed Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($requests)):
                                    foreach ($requests as $row): ?>
                                        <tr>
                                            <td style="font-weight:700;color:#64748b;">#<?= $row['purchase_request_id']; ?>
                                                <?php if ($row['status'] === 'Pending'): ?>
                                                    <form id="pr-form-<?= $row['purchase_request_id']; ?>" method="POST" style="display:none;"></form>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;"><?= htmlspecialchars($row['admin_name']); ?></div>
                                                <div style="font-size:0.75rem;color:#94a3b8;"><?= ($row['created_at'] instanceof DateTime) ? $row['created_at']->format('d/m/Y') : date("d/m/Y", strtotime($row['created_at'])); ?></div>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;"><?= htmlspecialchars($row['item_name']); ?></div>
                                                <div style="font-size:0.75rem;">
                                                    Qty: <?= htmlspecialchars($row['quantity']); ?> 
                                                    <span class="status-badge" style="padding:2px 6px; font-size:0.65rem; background: #f1f5f9;"><?= $row['request_type'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight:700; color:var(--primary);">LKR <?= number_format($row['unit_price'] * $row['quantity'], 2); ?></div>
                                                <div style="font-size:0.7rem;color:#94a3b8;">@ LKR <?= number_format($row['unit_price'], 2); ?></div>
                                            </td>
                                            <td>
                                                <a href="purchaseRequestPDF.php?id=<?= $row['purchase_request_id']; ?>"
                                                    target="_blank" class="btn btn-pdf" style="padding: 6px 12px; font-size: 0.8rem;"><i class="fas fa-file-pdf"></i> View</a>
                                            </td>
                                            <td>
                                                <?php
                                                $s = $row['status'];
                                                $sc = ($s === 'Approved') ? 'status-approved' : (($s === 'Rejected') ? 'status-rejected' : 'status-pending');
                                                ?>
                                                <span class="status-badge <?= $sc ?>"><?= $s ?></span>
                                            </td>
                                            <td>
                                                <?php if ($row['status'] === 'Pending'): ?>
                                                    <textarea name="remarks" form="pr-form-<?= $row['purchase_request_id']; ?>" placeholder="Enter review remarks..."></textarea>
                                                <?php else: ?>
                                                    <div style="font-size:0.85rem;color:#64748b;max-width:200px;">
                                                        <?= $row['unit_head_remarks'] ? htmlspecialchars($row['unit_head_remarks']) : '—' ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:0.85rem; color:#64748b;">
                                                <?= $row['reviewed_at'] ? (($row['reviewed_at'] instanceof DateTime) ? $row['reviewed_at']->format('Y-m-d h:i A') : htmlspecialchars($row['reviewed_at'])) : '<span style="color:#94a3b8; font-style:italic;">Pending Review</span>' ?>
                                            </td>
                                            <td>
                                                <?php if ($row['status'] === 'Pending'): ?>
                                                    <input type="hidden" name="purchase_request_id" form="pr-form-<?= $row['purchase_request_id']; ?>"
                                                        value="<?= $row['purchase_request_id']; ?>">
                                                    <div class="action-box">
                                                        <button type="submit" name="action" value="Approved" form="pr-form-<?= $row['purchase_request_id']; ?>"
                                                            class="btn btn-approve"><i class="fas fa-check"></i>
                                                            Approve</button>
                                                        <button type="submit" name="action" value="Rejected" form="pr-form-<?= $row['purchase_request_id']; ?>"
                                                            class="btn btn-reject"><i class="fas fa-times"></i> Reject</button>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color:#cbd5e1;font-style:italic;">Processed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">No acquisition
                                            requests found.</td>
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
        function toggleDropdown() { const d = document.getElementById("profileDropdown"); d.style.display = d.style.display === "block" ? "none" : "block"; }
        window.onclick = function (event) { if (!event.target.closest('.profile-btn')) { const dropdown = document.getElementById("profileDropdown"); if (dropdown && dropdown.style.display === "block") dropdown.style.display = "none"; } }
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>