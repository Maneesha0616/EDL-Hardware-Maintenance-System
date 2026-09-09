<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";
require_once __DIR__ . '/../Admin/AdminNotifyHelper.php';

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
$username = $user['username'] ?? "Technician";
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$sql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$params = array(&$user_id);
$notifStmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($notifStmt);
$notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Get issue_id
if (!isset($_GET['issue_id'])) {
    echo "Invalid Issue!";
    exit();
}

$issue_id = intval($_GET['issue_id']);

// Fetch issue
$sql = "SELECT i.*, u.username FROM issues i LEFT JOIN users u ON i.user_id = u.id WHERE i.issue_id = ? AND i.technician_id = ?";
$params = array(&$issue_id, &$user_id);
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);
$issue = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$issue) {
    echo "Issue not found or not assigned to you!";
    exit();
}

// Fetch existing parts request if any
$sql = "SELECT pr.request_id, pr.status, pri.item_id, pri.quantity_requested, it.item_name, it.brand, it.capacity_type FROM parts_requests pr JOIN parts_request_items pri ON pr.request_id = pri.request_id JOIN items it ON pri.item_id = it.item_id WHERE pr.issue_id = ? AND pr.technician_id = ? AND pr.request_id = (SELECT MAX(request_id) FROM parts_requests WHERE issue_id = ? AND technician_id = ?)";
$params = array(&$issue_id, &$user_id, &$issue_id, &$user_id);
$stmtParts = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmtParts);

$existingParts = [];
$existingRequest = null;

while ($row = sqlsrv_fetch_array($stmtParts, SQLSRV_FETCH_ASSOC)) {
    if ($existingRequest === null) {
        $existingRequest = [
            'request_id' => $row['request_id'],
            'status' => $row['status']
        ];
    }
    $existingParts[] = $row;
}

$success = "";
$error = "";

if (isset($_POST['save'])) {
    $recommendation = trim($_POST['recommendation'] ?? "");
    $status = trim($_POST['status'] ?? "");
    $parts_required = $_POST['parts_required'] ?? "No";

    $allowed_statuses = ['In Progress', 'Waiting Parts', 'Resolved'];

    if (!in_array($status, $allowed_statuses, true)) {
        $error = "Invalid status selected.";
    } else {
        sqlsrv_begin_transaction($conn);
        try {
            if ($parts_required === "Yes") {
                $status = "Waiting Parts";
            }

            if ($status === 'Resolved') {
                $sql = "UPDATE issues SET recommendation = ?, status = ?, completed_date = GETDATE() WHERE issue_id = ? AND technician_id = ?";
                $params = array(&$recommendation, &$status, &$issue_id, &$user_id);
            } else {
                $sql = "UPDATE issues SET recommendation = ?, status = ?, completed_date = NULL WHERE issue_id = ? AND technician_id = ?";
                $params = array(&$recommendation, &$status, &$issue_id, &$user_id);
            }
            $stmt = sqlsrv_prepare($conn, $sql, $params);

            if (!sqlsrv_execute($stmt)) {
                throw new Exception("Failed to update issue.");
            }

            $sql = "INSERT INTO issue_updates (issue_id, technician_id, status, solution_description, recommendation) VALUES (?, ?, ?, NULL, ?)";
            $params = array(&$issue_id, &$user_id, &$status, &$recommendation);
            $updateLog = sqlsrv_prepare($conn, $sql, $params);
            if (!sqlsrv_execute($updateLog)) {
                throw new Exception("Failed to save issue update history.");
            }

            // Notify user about status change
            $user_id_to_notify = $issue['user_id'] ?? null;
            if ($user_id_to_notify) {
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
                    default:
                        $notifTitle = "Issue Status Update";
                        $notifMsg = "Your reported issue (#{$issue_id}) status has been updated to '{$status}' by Technician {$username}.";
                        break;
                }
                $sql = "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())";
                $params = array(&$user_id_to_notify, &$notifTitle, &$notifMsg);
                $nStmt = sqlsrv_prepare($conn, $sql, $params);
                if ($nStmt) {
                    sqlsrv_execute($nStmt);
                }
            }

            if ($parts_required === "Yes") {
                $item_ids = $_POST['item_id'] ?? [];
                $request_quantities = $_POST['request_quantity'] ?? [];

                if (!is_array($item_ids) || !is_array($request_quantities) || count($item_ids) === 0) {
                    throw new Exception("Please add at least one spare part item.");
                }

                if (count($item_ids) !== count($request_quantities)) {
                    throw new Exception("Invalid spare parts data submitted.");
                }

                $validItems = [];
                $summaryParts = [];

                for ($i = 0; $i < count($item_ids); $i++) {
                    $item_id = intval($item_ids[$i]);
                    $qty = intval($request_quantities[$i]);

                    if ($item_id <= 0 || $qty <= 0) {
                        continue;
                    }

                    $validItems[] = [
                        'item_id' => $item_id,
                        'quantity' => $qty
                    ];
                }

                if (count($validItems) === 0) {
                    throw new Exception("Please select at least one valid spare part with quantity.");
                }

                $sql = "SELECT request_id FROM parts_requests WHERE issue_id = ? AND status = 'Pending'";
                $params = array(&$issue_id);
                $check = sqlsrv_prepare($conn, $sql, $params);
                sqlsrv_execute($check);
                if (sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC)) {
                    throw new Exception("A pending parts request already exists for this issue.");
                }

                $sql = "INSERT INTO parts_requests (issue_id, technician_id, status) OUTPUT INSERTED.request_id VALUES (?, ?, 'Pending')";
                $params = array(&$issue_id, &$user_id);
                $insertRequest = sqlsrv_prepare($conn, $sql, $params);
                if (!$insertRequest || !sqlsrv_execute($insertRequest)) {
                    throw new Exception("Failed to create parts request.");
                }

                $row = sqlsrv_fetch_array($insertRequest, SQLSRV_FETCH_ASSOC);
                $request_id = $row['request_id'] ?? null;
                if (!$request_id) {
                    throw new Exception("Failed to retrieve request ID.");
                }

                $sql = "INSERT INTO parts_request_items (request_id, item_id, quantity_requested, quantity_approved, quantity_issued, item_status) VALUES (?, ?, ?, 0, 0, 'Pending')";
                $insertItem = sqlsrv_prepare($conn, $sql, $params = array(null, null, null));

                $sqlItemName = "SELECT item_name FROM items WHERE item_id = ?";
                $stmtItem = sqlsrv_prepare($conn, $sqlItemName, $paramsItem = array(null));

                foreach ($validItems as $part) {
                    $item_id = $part['item_id'];
                    $qty = $part['quantity'];
                    $params = array(&$request_id, &$item_id, &$qty);
                    $insertItem = sqlsrv_prepare($conn, $sql, $params);
                    sqlsrv_execute($insertItem);

                    $paramsItem = array(&$item_id);
                    $stmtItem = sqlsrv_prepare($conn, $sqlItemName, $paramsItem);
                    sqlsrv_execute($stmtItem);
                    $itemData = sqlsrv_fetch_array($stmtItem, SQLSRV_FETCH_ASSOC);
                    $item_name = $itemData['item_name'] ?? 'Item';

                    $summaryParts[] = $item_name . " (Qty: " . $qty . ")";
                }

                $partsList = implode(", ", $summaryParts);
                $title = "New Parts Request Submitted";
                $message = "Technician {$username} has requested the following spare parts for Issue ID {$issue_id}: {$partsList}. Please review the request.";
                notifyAllAdmins($conn, $title, $message);

                sqlsrv_commit($conn);
                $success = "Recommendation saved and spare parts request sent for approval!";
            } else {
                sqlsrv_commit($conn);
                $success = "Recommendation saved successfully!";
            }
        } catch (Exception $e) {
            sqlsrv_rollback($conn);
            $error = $e->getMessage();
        }
    }

    $sql = "
        SELECT i.*, u.username
        FROM issues i
        LEFT JOIN users u ON i.user_id = u.id
        WHERE i.issue_id = ? AND i.technician_id = ?
    ";
    $params = array(&$issue_id, &$user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    sqlsrv_execute($stmt);
    $issue = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Submit Recommendation | Hardware Maintenance System</title>
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
            max-width: 1000px;
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
            margin-bottom: 20px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-card h3 i {
            color: var(--primary);
        }

        /* Details Table */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .details-table th,
        .details-table td {
            padding: 14px 15px;
            border-bottom: 1px solid #f1f5f9;
            text-align: left;
            font-size: 0.9rem;
        }

        .details-table th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 700;
            width: 220px;
        }

        .issue-display-img {
            max-width: 100%;
            border-radius: 12px;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-top: 10px;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--secondary);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 124, 145, 0.1);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        /* Parts Section */
        .parts-section {
            background: #f0f9ff;
            border: 2px dashed var(--primary);
            border-radius: 16px;
            padding: 25px;
            margin-top: 20px;
        }

        .submit-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #fff;
            border: none;
            padding: 14px 30px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: 0 4px 15px rgba(0, 124, 145, 0.2);
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 124, 145, 0.3);
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
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
                <h2>Task Resolution</h2>
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
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <div class="page-card">
                    <h3><i class="fas fa-info-circle"></i> Issue Information</h3>
                    <table class="details-table">
                        <tr>
                            <th>Issue ID</th>
                            <td>#<?= $issue_id ?></td>
                        </tr>
                        <tr>
                            <th>Reported By</th>
                            <td style="font-weight:600;"><?= htmlspecialchars($issue['username'] ?? 'Deleted User') ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Issue Type</th>
                            <td><?= htmlspecialchars($issue['issue_type']) ?></td>
                        </tr>
                        <tr>
                            <th>Category</th>
                            <td><?= htmlspecialchars($issue['issue_category'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th>Service Tag</th>
                            <td><code><?= htmlspecialchars($issue['service_tag'] ?: '—') ?></code></td>
                        </tr>
                        <tr>
                            <th>Description</th>
                            <td style="line-height:1.6; color:#475569;">
                                <?= nl2br(htmlspecialchars($issue['issue_description'])) ?></td>
                        </tr>
                        <tr>
                            <th>Software</th>
                            <td><?= htmlspecialchars($issue['software_name'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Additional Info</th>
                            <td style="line-height:1.6; color:#475569;">
                                <?= !empty($issue['additional_information']) ? nl2br(htmlspecialchars($issue['additional_information'])) : '—' ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Visual Evidence</th>
                            <td>
                                <?php if (!empty($issue['image'])): ?>
                                    <img class="issue-display-img"
                                        src="../uploads/issues/<?= htmlspecialchars($issue['image']) ?>" alt="Evidence">
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">No image evidence provided</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="page-card">
                    <h3><i class="fas fa-edit"></i> Submit Your Recommendation</h3>
                    <form method="POST">
                        <div class="form-group">
                            <label>Action Taken / Recommendation</label>
                            <textarea name="recommendation" class="form-control"
                                placeholder="Describe the steps taken or maintenance needed..."><?= htmlspecialchars($issue['recommendation'] ?? "") ?></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label>Current Status</label>
                                <select name="status" class="form-control" required>
                                    <option value="In Progress" <?= ($issue['status'] ?? '') == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="Waiting Parts" <?= ($issue['status'] ?? '') == 'Waiting Parts' ? 'selected' : '' ?>>Waiting Parts</option>
                                    <option value="Resolved" <?= ($issue['status'] ?? '') == 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Spare Parts Needed?</label>
                                <select name="parts_required" id="partsRequired" class="form-control"
                                    onchange="toggleParts()" required>
                                    <option value="No" <?= (!$existingRequest) ? "selected" : "" ?>>No</option>
                                    <option value="Yes" <?= ($existingRequest) ? "selected" : "" ?>>Yes</option>
                                </select>
                            </div>
                        </div>

                        <div id="partsSection" class="parts-section" style="display:none;">
                            <?php if ($existingRequest): ?>
                                <h4 style="font-size:0.9rem; margin-bottom:15px; color:#007c91;"><i
                                        class="fas fa-shopping-cart"></i> Requested Spare Parts Details</h4>
                                <div
                                    style="background: #f1f5f9; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #007c91;">
                                    <div style="margin-bottom: 12px; font-size: 0.9rem;">
                                        <strong>Status:</strong>
                                        <span
                                            style="padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; background: <?= ($existingRequest['status'] == 'Pending' ? '#fef3c7; color: #92400e;' : ($existingRequest['status'] == 'Approved' ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;')) ?>">
                                            <?= htmlspecialchars($existingRequest['status']) ?>
                                        </span>
                                    </div>

                                    <?php foreach ($existingParts as $part): ?>
                                        <div style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem;">
                                            <div><strong>Item Name:</strong> <?= htmlspecialchars($part['item_name']) ?></div>
                                            <div><strong>Brand:</strong> <?= htmlspecialchars($part['brand'] ?: '—') ?></div>
                                            <div><strong>Capacity/Specs:</strong>
                                                <?= htmlspecialchars($part['capacity_type'] ?: '—') ?></div>
                                            <div><strong>Quantity Requested:</strong> <?= intval($part['quantity_requested']) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($existingRequest['status'] !== 'Pending'): ?>
                                    <p style="font-size: 0.8rem; color: #64748b; margin-top: 10px;"><i
                                            class="fas fa-info-circle"></i> This request has been processed. You can submit
                                        another request if needed by choosing from the list below.</p>
                                    <hr style="margin: 15px 0; border: 0; border-top: 1px solid #e2e8f0;">
                                <?php else: ?>
                                    <p style="font-size: 0.8rem; color: #64748b; margin-top: 5px;"><i class="fas fa-clock"></i>
                                        A parts request is currently <strong>Pending</strong>. You cannot submit another request
                                        until this one is processed.</p>
                                    <style>
                                        #itemFields {
                                            display: none;
                                        }
                                    </style>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div id="itemFields">
                                <h4 style="font-size:0.9rem; margin-bottom:15px; color:#007c91;">
                                    <i class="fas fa-plus-circle"></i> Request New Spare Parts
                                </h4>

                                <div id="itemsContainer"></div>

                                <button type="button" onclick="addItemRow()" style="
        background: #0d5f85;
        color:white;
        border:none;
        padding:10px 16px;
        border-radius:10px;
        font-weight:600;
        cursor:pointer;
        margin-top:10px;
    ">
                                    <i class="fas fa-plus"></i> Add Item
                                </button>
                            </div>
                        </div>

                        <div style="margin-top: 30px;">
                            <button type="submit" name="save" class="submit-btn"><i class="fas fa-save"></i> Save
                                Changes</button>
                        </div>
                    </form>
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

        function toggleParts() {
            const parts = document.getElementById("partsRequired").value;
            const section = document.getElementById("partsSection");
            section.style.display = (parts === "Yes") ? "block" : "none";
        }

        function addItemRow() {
            const container = document.getElementById("itemsContainer");

            const row = document.createElement("div");
            row.className = "item-row";
            row.style.background = "#ffffff";
            row.style.border = "1px solid #dbeafe";
            row.style.borderRadius = "14px";
            row.style.padding = "15px";
            row.style.marginBottom = "15px";

            row.innerHTML = `
        <div class="form-group">
            <label>Select Item from Inventory</label>
            <select name="item_id[]" class="form-control item-select" onchange="fillItemDetails(this)">
                <option value="">— Search Item —</option>
                <?php
                $sqlItems = "SELECT item_id, item_name, brand, capacity_type, quantity FROM items";
                $stmtItems = sqlsrv_query($conn, $sqlItems);
                while ($item = sqlsrv_fetch_array($stmtItems, SQLSRV_FETCH_ASSOC)) {
                    echo "<option 
                        value='" . intval($item['item_id']) . "'
                        data-brand='" . htmlspecialchars($item['brand'], ENT_QUOTES) . "'
                        data-capacity='" . htmlspecialchars($item['capacity_type'], ENT_QUOTES) . "'
                    >"
                        . htmlspecialchars($item['item_name']) . " (Stock: " . intval($item['quantity']) . ")</option>";
                }
                ?>
            </select>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr auto; gap:15px; align-items:end;">
            <div class="form-group">
                <label>Brand</label>
                <input type="text" class="form-control item-brand" readonly placeholder="Auto-filled">
            </div>

            <div class="form-group">
                <label>Capacity / Specs</label>
                <input type="text" class="form-control item-capacity" readonly placeholder="Auto-filled">
            </div>

            <div class="form-group">
                <label>Requested Quantity</label>
                <input type="number" name="request_quantity[]" class="form-control" min="1" value="1">
            </div>

            <div class="form-group">
                <button type="button" onclick="removeItemRow(this)" style="
                    background:#ef4444;
                    color:white;
                    border:none;
                    padding:12px 14px;
                    border-radius:10px;
                    cursor:pointer;
                    font-weight:600;
                ">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;

            container.appendChild(row);
        }

        function removeItemRow(button) {
            const row = button.closest(".item-row");
            row.remove();
        }

        function fillItemDetails(selectElement) {
            const selected = selectElement.options[selectElement.selectedIndex];
            const row = selectElement.closest(".item-row");

            row.querySelector(".item-brand").value = selected.getAttribute("data-brand") || "";
            row.querySelector(".item-capacity").value = selected.getAttribute("data-capacity") || "";
        }

        document.addEventListener("DOMContentLoaded", function () {
            toggleParts();

            const partsRequired = document.getElementById("partsRequired").value;
            const itemFields = document.getElementById("itemFields");

            if (partsRequired === "Yes" && itemFields && itemFields.style.display !== "none") {
                addItemRow();
            }
        });
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>