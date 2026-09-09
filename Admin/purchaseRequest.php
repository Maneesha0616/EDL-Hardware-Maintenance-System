<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

date_default_timezone_set("Asia/Colombo");

$admin_id = $_SESSION['user_id'];
$success = $_SESSION['success'] ?? "";
$error = $_SESSION['error'] ?? "";
unset($_SESSION['success'], $_SESSION['error']);

// Fetch admin details
try {
    $stmt = sqlsrv_prepare($conn, "
    SELECT id, username, name, profile_image
    FROM users
    WHERE id = ? AND role = 'admin'
", [$admin_id]);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
    }
    if (!sqlsrv_execute($stmt)) {
        throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
    }
    $admin = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Database Error in purchaseRequest.php (admin fetch): " . $e->getMessage());
    $admin = null;
}

if (!$admin) {
    die("Admin not found.");
}

$username = $admin['username'];
$adminName = $admin['name'];
$profileImage = $admin['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$notifQuery = sqlsrv_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", [$admin_id]);
if ($notifQuery === false) {
    throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($notifQuery)) {
    throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
}
$notifRow = sqlsrv_fetch_array($notifQuery, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Fetch inventory items for selection
$inventoryItems = [];
try {
    $inventoryItemsStmt = sqlsrv_query($conn, "SELECT item_id, item_name, unit_price FROM items ORDER BY item_name ASC");
    $inventoryItems = [];
    while ($row = sqlsrv_fetch_array($inventoryItemsStmt, SQLSRV_FETCH_ASSOC)) {
        $inventoryItems[] = $row;
    }
} catch (Exception $e) {
    error_log("Database Error in purchaseRequest.php (inventory items): " . $e->getMessage());
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_request'])) {
    $request_type = $_POST['request_type'] ?? 'Manual';
    $item_id = ($request_type === 'Inventory') ? intval($_POST['inventory_item_id'] ?? 0) : null;
    $item_name = trim($_POST['item_name'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $justification = trim($_POST['justification'] ?? '');

    if ($item_name === '' || $quantity <= 0 || $justification === '') {
        $_SESSION['error'] = "Please fill all fields correctly.";
        header("Location: purchaseRequest.php");
        exit();
    } else {
        $date = date("d/m/Y");
        $time = date("h:i A");

        $safeItemName = htmlspecialchars($item_name, ENT_QUOTES, 'UTF-8');
        $safeJustification = nl2br(htmlspecialchars($justification, ENT_QUOTES, 'UTF-8'));
        $safeAdminName = htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8');
        $totalCost = $quantity * $unit_price;

        $letter_content = "
            <div style='font-family:\"Times New Roman\", serif; font-size:15px; line-height:1.5; color:#000;'>
                <p style='text-align:right; margin:0;'><strong>Date:</strong> {$date}</p>
                <p style='text-align:right; margin:0 0 15px 0;'><strong>Time:</strong> {$time}</p>

                <p style='margin-bottom:15px;'>
                    <strong>To:</strong><br>
                    The Unit Head<br>
                    Electricity Distribution Lanka (Pvt) Ltd
                </p>

                <p style='margin-bottom:15px;'><strong>Subject: Request for Approval to Purchase Required Item</strong></p>

                <p style='margin-bottom:15px;'>Dear Sir/Madam,</p>

                <p style='margin-bottom:15px; text-align:justify;'>
                    I respectfully submit this request seeking approval to purchase the item mentioned below for official operational purposes.
                    The requested item is required to support ongoing work activities efficiently and to avoid delays in service.
                </p>

                <p style='margin-bottom:10px;'><strong>Purchase Request Details:</strong></p>

                <table style='border-collapse:collapse; width:100%; margin-bottom:15px; font-size:14px;' border='1' cellpadding='8'>
                    <tr>
                        <td style='width:30%;'><strong>Item Name</strong></td>
                        <td>{$safeItemName}</td>
                    </tr>
                    <tr>
                        <td><strong>Unit Price (LKR)</strong></td>
                        <td>" . number_format($unit_price, 2) . "</td>
                    </tr>
                    <tr>
                        <td><strong>Quantity</strong></td>
                        <td>{$quantity}</td>
                    </tr>
                    <tr>
                        <td><strong>Total Estimated Cost</strong></td>
                        <td><strong>LKR " . number_format($totalCost, 2) . "</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Justification</strong></td>
                        <td>{$safeJustification}</td>
                    </tr>
                </table>

                <p style='margin-bottom:15px; text-align:justify;'>
                    In view of the above, I kindly request your approval to proceed with this purchase.
                    Your favorable consideration and approval would be greatly appreciated.
                </p>

                <p style='margin-bottom:15px;'>Thank you.</p>

                <div style='page-break-inside:avoid;'>
                    <p style='margin-bottom:5px;'>Yours faithfully,</p>
                    <p>
                        <strong>{$safeAdminName}</strong><br>
                        Administrator<br>
                        Electricity Distribution Lanka (Pvt) Ltd
                    </p>
                </div>
            </div>
        ";

        try {
            $insert = sqlsrv_prepare($conn, "
    INSERT INTO purchase_requests
    (admin_id, item_id, item_name, quantity, unit_price, request_type, justification, letter_content, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', GETDATE())
", [$admin_id, $item_id, $item_name, $quantity, $unit_price, $request_type, $justification, $letter_content]);
            if ($insert === false) {
                throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
            }
            if (!sqlsrv_execute($insert)) {
                throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
            }

            // Notify active unit heads
            $unitHeads = sqlsrv_query($conn, "SELECT id FROM users WHERE role = 'unithead'");
            $unitHeadRows = [];
            while ($row = sqlsrv_fetch_array($unitHeads, SQLSRV_FETCH_ASSOC)) {
                $unitHeadRows[] = $row;
            }

            foreach ($unitHeadRows as $uh) {
                $title = "New Purchase Request";
                $message = "A new purchase request has been submitted for item: {$item_name}.";
                $notify = sqlsrv_prepare($conn, "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())", [$uh['id'], $title, $message]);
                if ($notify === false) {
                    throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
                }
                if (!sqlsrv_execute($notify)) {
                    throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
                }
            }

            $_SESSION['success'] = "Purchase request letter sent to Unit Head successfully.";
            header("Location: purchaseRequest.php");
            exit();
        } catch (Exception $e) {
            error_log("Database Error in purchaseRequest.php (insert request): " . $e->getMessage());
            $_SESSION['error'] = "Failed to create purchase request.";
            header("Location: purchaseRequest.php");
            exit();
        }
    }
}

// Note: manual mark_received inventory update has been replaced with automatic updates upon Unit Head approval.

// Fetch all purchase requests created by this admin
try {
    $requestQuery = sqlsrv_prepare($conn, "
    SELECT 
        purchase_request_id,
        item_name,
        quantity,
        unit_price,
        request_type,
        justification,
        status,
        unit_head_remarks,
        created_at,
        reviewed_at
    FROM purchase_requests
    WHERE admin_id = ?
    ORDER BY created_at DESC
", [$admin_id]);
    if ($requestQuery === false) {
        throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
    }
    if (!sqlsrv_execute($requestQuery)) {
        throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
    }
    $requests = [];
    while ($row = sqlsrv_fetch_array($requestQuery, SQLSRV_FETCH_ASSOC)) {
        $requests[] = $row;
    }
} catch (Exception $e) {
    error_log("Database Error in purchaseRequest.php (fetch requests): " . $e->getMessage());
    $requests = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Purchase Request</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --secondary: #1e2a38;
            --text-muted: #94a3b8;
            --sidebar-width: 260px;
        }

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

        .content {
            padding: 30px 40px;
            flex-grow: 1;
        }

        .page-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 25px 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid #f1f5f9;
        }

        .page-card h3 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #1e2a38;
            padding-bottom: 10px;
        }

        /* Purchase Request Specific Styling */
        form label {
            display: block;
            margin-top: 1.25rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
            color: #1e2a38;
            font-size: 0.9rem;
        }

        form input,
        form select,
        form textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.95rem;
            color: #1e293b;
            background: #fff;
            transition: all 0.2s;
        }

        form input:focus,
        form select:focus,
        form textarea:focus {
            border-color: #007c91;
            box-shadow: 0 0 0 4px rgba(0, 124, 145, 0.1);
            outline: none;
        }

        form textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 1.5rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 124, 145, 0.2);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 124, 145, 0.3);
        }

        .success {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .error {
            background: rgba(239, 68, 68, 0.15);
            color: #991b1b;
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            color: #334155;
            margin-bottom: 10px;
        }

        thead th {
            background: #007c91;
            color: #fff;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 14px 16px;
            text-align: left;
            border-bottom: none;
        }

        tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        tbody tr:nth-of-type(even) {
            background-color: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 800;
            text-align: center;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.15);
            color: #92400e;
        }

        .status-approved {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
        }

        .status-rejected {
            background: rgba(239, 68, 68, 0.15);
            color: #991b1b;
        }

        .status-received {
            background: rgba(59, 130, 246, 0.15);
            color: #1d4ed8;
        }

        .pdf-btn {
            color: #007c91;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
            transition: color 0.2s;
        }

        .pdf-btn:hover {
            color: #005662;
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
                <li><a href="approveParts.php"><i class="fas fa-check-circle"></i> Approve Parts</a></li>
                <li><a href="issueReport.php"><i class="fas fa-file-alt"></i> Issue Reports</a></li>
                <li><a href="manageUsers.php"><i class="fas fa-users"></i> Manage Users</a></li>
                <li><a href="adminManageInventory.php"><i class="fas fa-boxes"></i> Manage Inventory</a></li>
                <li><a href="storeReport.php"><i class="fas fa-warehouse"></i> Store Reports</a></li>
                <li><a href="technicianEfficiency.php"><i class="fas fa-chart-line"></i> Technician Efficiency</a></li>
                <li><a href="costReport.php"><i class="fas fa-coins"></i> Cost Report</a></li>
                <li><a class="active" href="purchaseRequest.php"><i class="fas fa-shopping-cart"></i> Purchase
                        Requests</a></li>

                <li class="logout">
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <main class="main-content">

            <header>
                <h2>Purchase Request</h2>
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

            <div class="content">

                <div class="page-card">
                    <h3>Send Purchase Request to Unit Head</h3>

                    <?php if (!empty($success)): ?>
                        <div class="success"><?= htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="error"><?= htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <label for="request_type">Request Type</label>
                        <select id="request_type" name="request_type" onchange="toggleRequestType()" required>
                            <option value="Manual" <?= (isset($_POST['request_type']) && $_POST['request_type'] === 'Manual') ? 'selected' : ''; ?>>New Item (Manual Entry)
                            </option>
                            <option value="Inventory" <?= (isset($_POST['request_type']) && $_POST['request_type'] === 'Inventory') ? 'selected' : ''; ?>>Existing Inventory Item
                            </option>
                        </select>

                        <div id="inventory_selection"
                            style="display: none; background: #f1f5f9; padding: 15px; border-radius: 10px; margin-top: 10px;">
                            <label for="inventory_item_id">Select Inventory Item</label>
                            <select id="inventory_item_id" name="inventory_item_id" onchange="updateItemDetails()">
                                <option value="">-- Select Item from Store --</option>
                                <?php foreach ($inventoryItems as $item): ?>
                                    <option value="<?= $item['item_id'] ?>" data-price="<?= $item['unit_price'] ?>"
                                        data-name="<?= htmlspecialchars($item['item_name']) ?>">
                                        <?= htmlspecialchars($item['item_name']) ?> (LKR
                                        <?= number_format($item['unit_price'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p style="font-size: 0.8rem; color: #64748b; margin-top: 5px;"><i
                                    class="fas fa-info-circle"></i> Selecting an inventory item will auto-fill the name
                                and current price.</p>
                        </div>

                        <label for="item_name">Item Name</label>
                        <input type="text" id="item_name" name="item_name"
                            value="<?= isset($_POST['item_name']) ? htmlspecialchars($_POST['item_name']) : ''; ?>"
                            placeholder="Enter item name" required>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <label for="unit_price">Unit Price (LKR)</label>
                                <input type="number" id="unit_price" name="unit_price" step="0.01" min="0"
                                    value="<?= isset($_POST['unit_price']) ? htmlspecialchars($_POST['unit_price']) : '0.00'; ?>"
                                    placeholder="0.00" required>
                            </div>
                            <div>
                                <label for="quantity">Quantity</label>
                                <input type="number" id="quantity" name="quantity" min="1"
                                    value="<?= isset($_POST['quantity']) ? htmlspecialchars($_POST['quantity']) : ''; ?>"
                                    placeholder="Enter quantity" required>
                            </div>
                        </div>

                        <label for="justification">Justification</label>
                        <textarea id="justification" name="justification"
                            placeholder="Enter reason for the purchase request"
                            required><?= isset($_POST['justification']) ? htmlspecialchars($_POST['justification']) : ''; ?></textarea>

                        <button type="submit" name="send_request" class="btn">
                            <i class="fas fa-paper-plane"></i> Send Purchase Request Letter
                        </button>
                    </form>


                </div>

                <div class="page-card">
                    <h3>My Purchase Requests</h3>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Item Name</th>
                                    <th>Type</th>
                                    <th>Unit Price</th>
                                    <th>Qty</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Unit Head Remarks</th>
                                    <th>Date</th>
                                    <th>PDF</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($requests) > 0): ?>
                                    <?php foreach ($requests as $row): ?>
                                        <tr>
                                            <td><?= $row['purchase_request_id']; ?></td>
                                            <td>
                                                <div style="font-weight: 600;"><?= htmlspecialchars($row['item_name']); ?></div>
                                                <div style="font-size: 0.75rem; color: #64748b;">
                                                    <?= htmlspecialchars(substr($row['justification'], 0, 30)) . (strlen($row['justification']) > 30 ? '...' : ''); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge <?= $row['request_type'] === 'Inventory' ? 'badge-approved' : 'badge-pending' ?>"
                                                    style="font-size: 0.7rem;">
                                                    <?= $row['request_type'] ?>
                                                </span>
                                            </td>
                                            <td><?= number_format($row['unit_price'], 2); ?></td>
                                            <td><?= htmlspecialchars($row['quantity']); ?></td>
                                            <td style="font-weight: 700; color: var(--primary);">
                                                <?= number_format($row['unit_price'] * $row['quantity'], 2); ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status = $row['status'];
                                                $statusClass = 'status-pending';
                                                if ($status === 'Approved') {
                                                    $statusClass = 'status-approved';
                                                } elseif ($status === 'Rejected') {
                                                    $statusClass = 'status-rejected';
                                                } elseif ($status === 'Received') {
                                                    $statusClass = 'status-received';
                                                }
                                                ?>
                                                <span class="status-badge <?= $statusClass; ?>">
                                                    <?= htmlspecialchars($status); ?>
                                                </span>
                                            </td>
                                            <td class="remark-box">
                                                <?= !empty($row['unit_head_remarks']) ? htmlspecialchars($row['unit_head_remarks']) : '-'; ?>
                                            </td>
                                            <td><?= $row['created_at']->format('d/m/Y'); ?></td>
                                            <td>
                                                <a href="purchaseRequestPDF.php?id=<?= $row['purchase_request_id']; ?>"
                                                    target="_blank" class="pdf-btn"
                                                    style="padding: 6px 12px; font-size: 0.8rem;">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </a>
                                            </td>
                                            <td>
                                                —
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="empty-row">No purchase requests found.</td>
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
                        style="width: 50px; border-radius: 10px; margin-bottom: 1rem;">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem; color: #64748b;">&copy; <?php echo date('Y'); ?> Electricity
                        Distribution Lanka (Private) Limited</p>
                    <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5; color: #64748b;">Helping you manage
                        hardware tools and maintenance tasks.</p>
                </div>
            </footer>

        </main>
    </div>

    <script>
        function toggleDropdown() {
            const dropdown = document.getElementById("profileDropdown");
            dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
        }

        function toggleRequestType() {
            const type = document.getElementById('request_type').value;
            const inventoryDiv = document.getElementById('inventory_selection');
            const itemNameInput = document.getElementById('item_name');
            const unitPriceInput = document.getElementById('unit_price');

            if (type === 'Inventory') {
                inventoryDiv.style.display = 'block';
                itemNameInput.readOnly = true;
                itemNameInput.style.backgroundColor = '#f1f5f9';
            } else {
                inventoryDiv.style.display = 'none';
                itemNameInput.readOnly = false;
                itemNameInput.style.backgroundColor = '#fff';
                itemNameInput.value = '';
                unitPriceInput.value = '0.00';
            }
        }

        function updateItemDetails() {
            const select = document.getElementById('inventory_item_id');
            const selectedOption = select.options[select.selectedIndex];

            if (selectedOption.value !== "") {
                document.getElementById('item_name').value = selectedOption.getAttribute('data-name');
                document.getElementById('unit_price').value = selectedOption.getAttribute('data-price');
            }
        }

        function toggleDropdown() {
            const d = document.getElementById("profileDropdown");
            d.style.display = d.style.display === "block" ? "none" : "block";
        }

        window.onclick = function (event) {
            if (!event.target.closest('.header-right')) {
                const dropdown = document.getElementById("profileDropdown");
                if (dropdown) {
                    dropdown.style.display = "none";
                }
            }
        }

        // Initialize state on page load if needed (e.g. on validation error)
        window.onload = function () {
            if (document.getElementById('request_type').value === 'Inventory') {
                toggleRequestType();
            }
        };
    </script>


    <script src="/HMS/desktop_notify.php"></script>
</body>

</html>