<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";
require_once __DIR__ . '/../Admin/AdminNotifyHelper.php';

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'storekeeper') {
    header("Location: ../login.php");
    exit();
}

// Fetch storekeeper info
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
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

$success = "";
$error = "";

/**
 * Update parent request summary status based on child item statuses
 */
function updatePartsRequestStatus($conn, $request_id) {
    $sql = "
        SELECT item_status, COUNT(*) AS total
        FROM parts_request_items
        WHERE request_id = ?
        GROUP BY item_status
    ";
    $params = array($request_id);
    $q = sqlsrv_query($conn, $sql, $params);
    if ($q === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $counts = [
        'Pending' => 0,
        'Approved' => 0,
        'Rejected' => 0,
        'Supplied' => 0
    ];

    while ($row = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) {
        $status = $row['item_status'];
        if (isset($counts[$status])) {
            $counts[$status] = intval($row['total']);
        }
    }
    sqlsrv_free_stmt($q);

    $newStatus = 'Pending';

    if ($counts['Supplied'] > 0 && ($counts['Pending'] > 0 || $counts['Approved'] > 0 || $counts['Rejected'] > 0)) {
        $newStatus = 'Partially Supplied';
    } elseif ($counts['Supplied'] > 0 && $counts['Pending'] == 0 && $counts['Approved'] == 0) {
        $newStatus = 'Supplied';
    } elseif ($counts['Approved'] > 0 && ($counts['Pending'] > 0 || $counts['Rejected'] > 0)) {
        $newStatus = 'Partially Approved';
    } elseif ($counts['Approved'] > 0 && $counts['Pending'] == 0 && $counts['Rejected'] == 0) {
        $newStatus = 'Approved';
    } elseif ($counts['Rejected'] > 0 && $counts['Pending'] == 0 && $counts['Approved'] == 0 && $counts['Supplied'] == 0) {
        $newStatus = 'Rejected';
    }

    $upSql = "
        UPDATE parts_requests
        SET status = ?
        WHERE request_id = ?
    ";
    $upParams = array($newStatus, $request_id);
    $up = sqlsrv_query($conn, $upSql, $upParams);
    if ($up === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($up);
}

// ISSUE ONE ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_request'], $_POST['request_item_id'])) {
    $request_item_id = intval($_POST['request_item_id']);

    if ($request_item_id <= 0) {
        $error = "Invalid item request.";
    } else {
        sqlsrv_begin_transaction($conn);

        try {
            // Lock only selected approved item
            $sql = "
                SELECT 
                    pri.id,
                    pri.request_id,
                    pri.item_id,
                    pri.quantity_requested,
                    pri.quantity_approved,
                    pri.quantity_issued,
                    pri.item_status,
                    pr.issue_id,
                    pr.technician_id,
                    it.item_name,
                    it.brand,
                    it.capacity_type,
                    it.quantity AS stock_qty
                FROM parts_request_items pri
                INNER JOIN parts_requests pr ON pri.request_id = pr.request_id
                INNER JOIN items it ON pri.item_id = it.item_id
                WHERE pri.id = ?
            ";
            $params = array($request_item_id);
            $q = sqlsrv_query($conn, $sql, $params);
            if ($q === false) {
                throw new Exception("Query failed: " . print_r(sqlsrv_errors(), true));
            }
            $itemRow = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($q);

            if (!$itemRow) {
                throw new Exception("Approved item not found.");
            }

            if ($itemRow['item_status'] !== 'Approved') {
                throw new Exception("Only approved items can be issued.");
            }

            $request_id = intval($itemRow['request_id']);
            $issue_id = intval($itemRow['issue_id']);
            $technician_id = intval($itemRow['technician_id']);
            $item_id = intval($itemRow['item_id']);
            $qty = intval($itemRow['quantity_approved']);
            $item_name = $itemRow['item_name'] ?? 'Item';
            $brand = $itemRow['brand'] ?? '';
            $capacity_type = $itemRow['capacity_type'] ?? '';

            if ($qty <= 0) {
                throw new Exception("Approved quantity is invalid.");
            }

            // Lock inventory item
            $itSql = "
                SELECT item_id, item_name, quantity
                FROM items
                WHERE item_id = ?
            ";
            $itParams = array($item_id);
            $it = sqlsrv_query($conn, $itSql, $itParams);
            if ($it === false) {
                throw new Exception("Inventory query failed: " . print_r(sqlsrv_errors(), true));
            }
            $stockRow = sqlsrv_fetch_array($it, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($it);

            if (!$stockRow) {
                throw new Exception("Inventory item not found.");
            }

            if (intval($stockRow['quantity']) < $qty) {
                throw new Exception("Insufficient stock for {$item_name}. Available: " . intval($stockRow['quantity']));
            }

            $newQty = intval($stockRow['quantity']) - $qty;

            // Deduct stock
            $upItemSql = "
                UPDATE items
                SET quantity = ?
                WHERE item_id = ?
            ";
            $upItemParams = array($newQty, $item_id);
            $upItem = sqlsrv_query($conn, $upItemSql, $upItemParams);
            if ($upItem === false) {
                throw new Exception("Failed to update inventory.");
            }
            sqlsrv_free_stmt($upItem);

            // Mark this item as supplied
            $upReqItemSql = "
                UPDATE parts_request_items
                SET item_status = 'Supplied',
                    quantity_issued = ?
                WHERE id = ?
            ";
            $upReqItemParams = array($qty, $request_item_id);
            $upReqItem = sqlsrv_query($conn, $upReqItemSql, $upReqItemParams);
            if ($upReqItem === false) {
                throw new Exception("Failed to update parts request item status.");
            }
            sqlsrv_free_stmt($upReqItem);

            // Update parent request summary status
            updatePartsRequestStatus($conn, $request_id);

            // Keep issue active
            $upIssueSql = "
                UPDATE issues
                SET status = 'In Progress'
                WHERE issue_id = ?
            ";
            $upIssueParams = array($issue_id);
            $upIssue = sqlsrv_query($conn, $upIssueSql, $upIssueParams);
            if ($upIssue === false) {
                throw new Exception("Failed to update issue status.");
            }
            sqlsrv_free_stmt($upIssue);

            // Notify technician
            $title = "Spare Part Issued";
            $message = "An approved spare part has been issued for your request (Request ID: {$request_id}) related to Issue ID {$issue_id}. "
                 . "The issued item is {$item_name}"
                 . (!empty($brand) ? " ({$brand})" : "")
                 . (!empty($capacity_type) ? " with specification {$capacity_type}" : "")
                 . ". The issued quantity is {$qty}. Please proceed with the repair or replacement process.";


            $notifySql = "
                INSERT INTO notifications (user_id, title, message, created_at)
                VALUES (?, ?, ?, GETDATE())
            ";
            $notifyParams = array($technician_id, $title, $message);
            $notifyTech = sqlsrv_query($conn, $notifySql, $notifyParams);
            if ($notifyTech === false) {
                throw new Exception("Failed to send notification.");
            }
            sqlsrv_free_stmt($notifyTech);

            sqlsrv_commit($conn);
            $success = "Item issued successfully. Technician notified.";

        } catch (Exception $e) {
            sqlsrv_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// Unread notification count
$notifSql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$notifParams = array($user_id);
$notifQuery = sqlsrv_query($conn, $notifSql, $notifParams);
if ($notifQuery === false) {
    die(print_r(sqlsrv_errors(), true));
}
$notifRow = sqlsrv_fetch_array($notifQuery, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;
sqlsrv_free_stmt($notifQuery);

// Fetch only approved items
$requestsSql = "
    SELECT 
        pri.id,
        pri.request_id,
        pri.item_id,
        pri.quantity_requested,
        pri.quantity_approved,
        pri.quantity_issued,
        pri.item_status,
        pr.issue_id,
        pr.approval_date,
        u.username AS technician_name,
        it.item_name,
        it.brand,
        it.capacity_type,
        it.quantity AS stock_qty
    FROM parts_request_items pri
    INNER JOIN parts_requests pr ON pri.request_id = pr.request_id
    LEFT JOIN users u ON pr.technician_id = u.id
    LEFT JOIN items it ON pri.item_id = it.item_id
    WHERE pri.item_status = 'Approved'
    ORDER BY pr.approval_date DESC, pri.request_id DESC, pri.id ASC
";
$requests = sqlsrv_query($conn, $requestsSql);
if ($requests === false) {
    die(print_r(sqlsrv_errors(), true));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Issue Hardware | Hardware Maintenance System</title>
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

    .page-container {
        padding: 2.5rem;
        max-width: 1400px;
        margin: 0 auto;
        width: 100%;
    }

    .section-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        margin-bottom: 30px;
        border: 1px solid #f1f5f9;
    }

    .section-title {
        font-size: 1.25rem;
        font-weight: 800;
        margin-bottom: 25px;
        color: var(--secondary);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-title i { color: var(--primary); }

    .alert {
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

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
        padding: 18px 20px;
        text-align: left;
        font-size: 0.85rem;
        font-weight: 700;
        color: #ffffff;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 2px solid #005662;
    }

    td {
        padding: 18px 20px;
        font-size: 0.9rem;
        color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
    }

    .stock-ok { color: #059669; font-weight: 700; }
    .stock-low { color: #ef4444; font-weight: 800; }

    .btn-issue {
        background: var(--primary);
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 10px;
        font-weight: 700;
        cursor: pointer;
        transition: 0.2s;
        font-size: 0.85rem;
    }

    .btn-issue:hover { background: var(--primary-dark); transform: scale(1.05); }
    .btn-issue:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; transform: none; }

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
        .sidebar { transform: translateX(-100%); width: 0; padding: 0; }
        .main-content { margin-left: 0; width: 100%; }
    }
</style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo" style="cursor: pointer;" onclick="window.location.href='storekeeperdash.php';">
            <img src="../images/hardware_logo.png" alt="HMS Logo">
            <h1>HMS</h1>
        </div>

        <ul class="menu">
            <li><a href="storekeeperdash.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="issueHardware.php" class="active"><i class="fas fa-share-square"></i> Issue Hardware</a></li>
            <li><a href="manageInventory.php"><i class="fas fa-boxes"></i> Manage Inventory</a></li>
            <li><a href="stockReport.php"><i class="fas fa-file-invoice-dollar"></i> Stock Reports</a></li>

            <li class="logout">
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </aside>

    <main class="main-content">
        <header>
            <h2>Issue Hardware</h2>
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

        <div class="page-container">
            <div class="section-card">
                <div class="section-title">
                    <i class="fas fa-clipboard-check"></i>
                    Approved Parts Requests
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Ref ID</th>
                                <th>Issue ID</th>
                                <th>Technician</th>
                                <th>Item Description</th>
                                <th>Spec/Brand</th>
                                <th>Qty</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            if ($requests && sqlsrv_has_rows($requests)):
                                while ($row = sqlsrv_fetch_array($requests, SQLSRV_FETCH_ASSOC)):
                                    $stock = (int)$row['stock_qty'];
                                    $qty = (int)$row['quantity_approved'];
                                    $isLow = $stock < $qty;
                            ?>
                            <tr>
                                <td style="font-weight:700;color:#64748b;"><?= $i++ ?></td>
                                <td style="font-weight:600;">#REQ-<?= intval($row['request_id']) ?></td>
                                <td><?= intval($row['issue_id']) ?></td>
                                <td><?= htmlspecialchars($row['technician_name'] ?? 'Technician') ?></td>
                                <td style="font-weight:600;"><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
                                <td style="font-size:0.85rem;color:#64748b;">
                                    <?= htmlspecialchars(trim(($row['brand'] ?? '') . ' ' . ($row['capacity_type'] ?? ''))) ?>
                                </td>
                                <td style="font-weight:800;"><?= $qty ?></td>
                                <td class="<?= $isLow ? 'stock-low' : 'stock-ok' ?>"><?= $stock ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Confirm hardware issuance to technician?')">
                                        <input type="hidden" name="request_item_id" value="<?= intval($row['id']) ?>">
                                        <button class="btn-issue" type="submit" name="issue_request" <?= $isLow ? 'disabled title="Stock unavailable"' : '' ?>>
                                            Issue Parts
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">No pending approved requests found.</td>
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
        if (dropdown && dropdown.style.display === "block") dropdown.style.display = "none";
    }
}
</script>
<script src="/HMS/desktop_notify.php"></script>
</body>
</html>