<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch admin username and profile image
$user_id = $_SESSION['user_id'];

$sql = "SELECT username, profile_image FROM users WHERE id = ? AND role = 'admin'";
$params = [$user_id];
$adminStmt = sqlsrv_query($conn, $sql, $params);
if($adminStmt === false){
    error_log("Database error (admin fetch): " . print_r(sqlsrv_errors(), true));
    die("Failed to fetch admin data.");
}
$user = sqlsrv_fetch_array($adminStmt, SQLSRV_FETCH_ASSOC);

$username = $user['username'] ?? 'Admin';
$profileImage = $user['profile_image'] ?? null;

$hasProfileImage = !empty($profileImage)
    && file_exists(__DIR__ . "/../uploads/" . $profileImage);

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


// ASSIGN TECHNICIAN
if (isset($_POST['assign'])) {

    $issue_id = intval($_POST['issue_id']);
    $technician_id = intval($_POST['technician_id']);

    // Update issue
    $updateSql = "UPDATE issues SET technician_id = ?, status = 'Assigned', assigned_date = GETDATE() WHERE issue_id = ?";
$updateParams = [$technician_id, $issue_id];
$updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);
if($updateStmt === false){
    error_log("Database error (update issue): " . print_r(sqlsrv_errors(), true));
    die("Failed to assign technician.");
}

    // Get issue details
    $issueSql = "SELECT issue_description, service_tag FROM issues WHERE issue_id = ?";
$issueParams = [$issue_id];
$issueStmt = sqlsrv_query($conn, $issueSql, $issueParams);
if($issueStmt === false){
    error_log("Database error (fetch issue): " . print_r(sqlsrv_errors(), true));
    die("Failed to fetch issue details.");
}
$issueData = sqlsrv_fetch_array($issueStmt, SQLSRV_FETCH_ASSOC);

    $description = $issueData['issue_description'] ?? '';
    $service_tag = $issueData['service_tag'] ?? '';

    // Notification
    $title = "New Issue Assigned";

    $message = "A new issue (ID: {$issue_id}) with Service Tag {$service_tag} has been assigned to you. "
         . "Description: {$description}. The current status is Assigned.";

    $notifySql = "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())";
$notifyParams = [$technician_id, $title, $message];
$notifyStmt = sqlsrv_query($conn, $notifySql, $notifyParams);
if($notifyStmt === false){
    error_log("Database error (insert notification): " . print_r(sqlsrv_errors(), true));
}

    // Log the assignment
    log_system_activity($conn, $_SESSION['user_id'], 'Assign Issue', 'Assigned issue ID ' . $issue_id . ' to technician ID ' . $technician_id);

    $success = "Technician assigned and notified successfully!";
}

$issuesSql = "SELECT i.issue_id, i.user_id, u.username, u.role AS user_role, i.issue_type, i.issue_description, i.service_tag, i.image, i.received_date, i.additional_information FROM issues i LEFT JOIN users u ON i.user_id = u.id WHERE i.status = 'Pending' ORDER BY i.received_date DESC";
$issuesStmt = sqlsrv_query($conn, $issuesSql);
if($issuesStmt === false){
    error_log("Database error (fetch issues): " . print_r(sqlsrv_errors(), true));
    die("An error occurred while fetching issues. Please try again later.");
}
$issues = [];
while($row = sqlsrv_fetch_array($issuesStmt, SQLSRV_FETCH_ASSOC)){
    $issues[] = $row;
}

$techSql = "SELECT id, username FROM users WHERE role = 'technician'";
$techStmt = sqlsrv_query($conn, $techSql);
if($techStmt === false){
    error_log("Database error (fetch technicians): " . print_r(sqlsrv_errors(), true));
    $technicians = [];
} else {
    $technicians = [];
    while($row = sqlsrv_fetch_array($techStmt, SQLSRV_FETCH_ASSOC)){
        $technicians[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Assign Issues</title>

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

        /* Sidebar Styling */
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

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Header */
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

        /* Containers & Cards */
        .container,
        .page-container {
            padding: 30px 40px;
            flex-grow: 1;
        }

        .dashboard,
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .card {
            background: #ffffff;
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #f1f5f9;

            text-decoration: none;
            color: #1e2a38;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #007c91;
        }

        .card i {
            font-size: 40px;
            color: #007c91;
            margin-bottom: 20px;
            display: block;
            transition: transform 0.3s;
        }

        .card:hover i {
            transform: scale(1.1);
        }

        .card h3 {
            font-size: 1.1rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .card .value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e2a38;
        }

        .card span {
            font-size: 0.9rem;
            color: #64748b;
        }

        .section-card,
        .page-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 25px 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid #f1f5f9;
        }

        .section-title,
        .page-card h3 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #1e2a38;
            padding-bottom: 10px;
        }

        /* Forms & Inputs */
        .top-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
        }

        .search-box input,
        input[type="text"],
        input[type="number"],
        input[type="email"],
        input[type="password"],
        textarea,
        select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid #cfd8dc;
            font-size: 0.95rem;
            color: #334155;
            transition: border-color 0.2s, box-shadow 0.2s;
            background-color: #fff;
            outline: none;
        }

        .search-box input:focus,
        input:focus,
        textarea:focus,
        select:focus {
            border-color: #007c91;
            box-shadow: 0 0 0 3px rgba(0, 124, 145, 0.15);
        }

        label {
            display: block;
            margin-top: 16px;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e2a38;
            font-size: 0.95rem;
        }

        .assign-form {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: stretch;
            min-width: 150px;
            padding: 5px 0;
        }

        .assign-form select {
            width: 100%;
            margin-top: 0;
            padding: 6px 10px;
            font-size: 0.8rem;
            height: 34px;
            border-radius: 8px;
        }

        .assign-form button {
            width: 100%;
            padding: 0 12px;
            height: 34px;
            white-space: nowrap;
            font-size: 0.8rem;
            justify-content: center;
            border-radius: 8px;
        }

        /* Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button,
        .btn,
        .action-btn,
        .pdf-btn {
            background: #007c91;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease-in-out;
        }

        button:hover,
        .btn:hover,
        .action-btn:hover,
        .pdf-btn:hover {
            background: #009db5;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 124, 145, 0.2);
        }

        .btn-approve {
            background: rgba(34, 197, 94, 0.15) !important;
            color: #166534 !important;
        }

        .btn-approve:hover {
            background: rgba(34, 197, 94, 0.25) !important;
        }

        .btn-reject {
            background: rgba(239, 68, 68, 0.15) !important;
            color: #991b1b !important;
        }

        .btn-reject:hover {
            background: rgba(239, 68, 68, 0.25) !important;
        }

        .btn-secondary {
            background: #64748b;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            font-size: 0.85rem;
            color: #334155;
            margin-bottom: 10px;
        }

        th,
        td {
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
        }

        thead th {
            background: #007c91;
            color: #fff;
            font-weight: 700;
            font-size: 0.8rem;
            white-space: nowrap;
            padding: 12px 10px;
            text-align: left;
            border-bottom: none;
            
        }

        tbody td {
            word-wrap: break-word;
            overflow-wrap: break-word;
            padding: 12px 10px;
            word-break: break-word;
        }

        thead th {
            white-space: nowrap;
        }

        tbody tr {
            transition: background-color 0.2s, transform 0.2s;
            background: rgba(255, 255, 255, 0.8);
        }

        tbody tr:nth-of-type(even) {
            background-color: #f8fafc;
        }

        tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.05);
        }

        /* Badges */
        .badge,
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 800;
            text-align: center;
        }

        .badge-supplied,
        .status-approved {
            background-color: rgba(34, 197, 94, 0.15);
            color: #166534;
        }

        .badge-pending,
        .status-pending {
            background-color: rgba(245, 158, 11, 0.15);
            color: #92400e;
        }

        .badge-rejected,
        .status-rejected {
            background-color: rgba(239, 68, 68, 0.15);
            color: #991b1b;
        }

        .badge-approved {
            background-color: rgba(59, 130, 246, 0.15);
            color: #1d4ed8;
        }

        .low-stock,
        .stock-low {
            color: #b91c1c;
            font-weight: 800;
        }

        .good-stock,
        .stock-ok {
            color: #166534;
            font-weight: 800;
        }

        /* Messages */
        .success,
        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .error,
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            color: #991b1b;
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            font-weight: 700;
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

        /* Print Styles */
        @media print {

            .sidebar,
            header,
            .top-actions,
            .footer {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .page-container,
            .container {
                padding: 0;
            }

            body {
                background: #fff;
            }

            .section-card,
            .card,
            .page-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }

        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
                padding: 20px;
                z-index: auto;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .layout {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 15px 20px;
            }

            .container,
            .page-container {
                padding: 15px;
            }

            .page-card,
            .section-card,
            .card {
                padding: 20px 15px;
            }

            .dropdown {
                right: 20px;
            }
        }



        /* Manage Users Specific */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .field label {
            margin-top: 0;
        }

        .btn-primary {
            background: #007c91;
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            transition: 0.2s;
        }

        .btn-primary:hover {
            background: #009db5;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 124, 145, 0.2);
        }

        td.actions {
            white-space: nowrap;
        }

        .edit-btn {
            background: rgba(34, 197, 94, 0.15);
            color: #166534;
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.8rem;
            margin-right: 5px;
            transition: 0.2s;
        }

        .edit-btn:hover {
            background: rgba(34, 197, 94, 0.25);
        }

        .delete-btn {
            background: rgba(239, 68, 68, 0.15);
            color: #991b1b;
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.8rem;
            transition: 0.2s;
        }

        .delete-btn:hover {
            background: rgba(239, 68, 68, 0.25);
        }

        .role-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .role-badge.admin {
            background: rgba(0, 124, 145, 0.15);
            color: #007c91;
        }

        .role-badge.storekeeper {
            background: rgba(59, 130, 246, 0.15);
            color: #1d4ed8;
        }

        .role-badge.technician {
            background: rgba(245, 158, 11, 0.15);
            color: #92400e;
        }

        .role-badge.user {
            background: rgba(100, 116, 139, 0.15);
            color: #334155;
        }

        .role-badge.unithead {
            background: rgba(139, 92, 246, 0.15);
            color: #5b21b6;
        }

        /* Technician Efficiency Specific */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: #fff;
            padding: 24px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            text-align: center;
            border: 1px solid #f1f5f9;
            border-bottom: 5px solid #007c91;
            transition: transform 0.2s;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.08);
        }

        .summary-card .icon {
            font-size: 32px;
            color: #007c91;
            margin-bottom: 15px;
        }

        .summary-card h3 {
            font-size: 1.1rem;
            color: #1e2a38;
            margin-bottom: 10px;
            font-weight: 800;
        }

        .summary-card .value {
            font-size: 2rem;
            font-weight: 800;
            color: #007c91;
            margin-bottom: 5px;
        }

        .summary-card .subtext {
            font-size: 0.85rem;
            color: #64748b;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .chart-card {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            border: 1px solid #f1f5f9;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 15px;
        }

        .tech-info h4 {
            font-size: 1.1rem;
            color: #1e2a38;
            margin-bottom: 5px;
            font-weight: 800;
        }

        .tech-info p {
            font-size: 0.85rem;
            color: #64748b;
        }

        .chart-wrap {
            height: 250px;
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .area-list {
            margin-top: 15px;
            border-top: 1px solid #f1f5f9;
            padding-top: 15px;
        }

        .area-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 0.9rem;
            color: #334155;
            border-bottom: 1px dashed #e2e8f0;
        }

        .area-item:last-child {
            border-bottom: none;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        /* Fix table images */
        .table-wrapper img,
        table img {
            max-width: 65px;
            max-height: 65px;
            object-fit: cover;
            border-radius: 6px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            cursor: pointer;
        }

        .table-wrapper img:hover,
        table img:hover {
            transform: scale(1.1);
        }

        /* ─── IMAGE MODAL ─── */
        #imgModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(6px);
            z-index: 20000;
            align-items: center;
            justify-content: center;
        }

        #imgModal img {
            max-width: 90vw;
            max-height: 85vh;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
            animation: zoomIn 0.25s ease;
        }

        @keyframes zoomIn {
            from {
                opacity: 0;
                transform: scale(0.85);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .close-modal {
            position: fixed;
            top: 1.5rem;
            right: 2rem;
            font-size: 2rem;
            color: white;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.2s;
            z-index: 30000;
        }

        .close-modal:hover {
            opacity: 1;
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
                <li><a class="active" href="assignIssues.php"><i class="fas fa-user-cog"></i> Assign Issues</a></li>
                <li><a href="approveParts.php"><i class="fas fa-check-circle"></i> Approve Parts</a></li>
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
                <h2>Assign Issues</h2>
                <div class="header-right">

                    <a href="adminNotifications.php" class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <?php if (isset($unreadCount) && $unreadCount > 0): ?>
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


            <div class="page-container">
                <div class="section-card">
                    <div class="section-title">Pending Issues</div>

                    <?php if (!empty($success)) { ?>
                        <div class="success"><?= $success ?></div>
                    <?php } ?>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Issue ID</th>
                                    <th>Username</th>
                                    <th>Issue Type</th>
                                    <th>Description</th>
                                    <th>Service Tag</th>
                                    <th>Image</th>
                                    <th>Received Date</th>
                                    <th>Additional Information</th>
                                    <th>Assign</th>
                                </tr>
                            </thead>
                            <tbody>

                                <?php if (!empty($issues)): ?>

                                    <?php foreach ($issues as $row) { ?>
                                        <tr>

                                            <td><?= $row['issue_id'] ?></td>

                                            <td>
                                                <?= !empty($row['username'])
                                                    ? htmlspecialchars($row['username'])
                                                    : '<em style="color:red;">Deleted User</em>' ?>
                                            </td>

                                            <td><?= htmlspecialchars($row['issue_type']) ?></td>
                                            <td>
                                                <?= htmlspecialchars(substr($row['issue_description'], 0, 50)) ?>
                                                <?= strlen($row['issue_description']) > 50 ? '...' : '' ?>
                                            </td>
                                            <td><?= htmlspecialchars($row['service_tag']) ?></td>

                                            <td>
                                                <?php if (!empty($row['image'])): ?>
                                                    <img src="../uploads/issues/<?= htmlspecialchars($row['image']) ?>"
                                                        onclick="openImage(this.src)" style="cursor:pointer; width:70px;">
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>

                                            <td><?= !empty($row['received_date']) ? ( ($row['received_date'] instanceof DateTime) ? $row['received_date']->format('Y-m-d h:i A') : date('Y-m-d h:i A', strtotime($row['received_date'])) ) : '—' ?></td>

                                            <td>
                                                <?= htmlspecialchars(substr($row['additional_information'], 0, 50)) ?>
                                                <?= strlen($row['additional_information']) > 50 ? '...' : '' ?>
                                            </td>

                                            <td>
                                                <form method="POST" class="assign-form">
                                                    <input type="hidden" name="issue_id" value="<?= $row['issue_id'] ?>">
                                                    <select name="technician_id" required>
                                                        <option value="">Select Tech</option>
                                                        <?php foreach ($technicians as $t) { ?>
                                                            <option value="<?= $t['id'] ?>">
                                                                <?= htmlspecialchars($t['username']) ?>
                                                            </option>
                                                        <?php } ?>
                                                    </select>
                                                    <button name="assign"> Assign</button>
                                                </form>
                                            </td>

                                        </tr>
                                    <?php } ?>

                                <?php else: ?>
                                    <tr>
                                        <td colspan="12"
                                            style="padding:16px;color:#64748b;font-weight:700;text-align:center;">
                                            No pending issues found
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
            const d = document.getElementById("profileDropdown");
            d.style.display = d.style.display === "block" ? "none" : "block";
        }

        // ── Image modal ──
        function openImage(src) {
            document.getElementById('modalImg').src = src;
            document.getElementById('imgModal').style.display = 'flex';
        }
        function closeImage() {
            document.getElementById('imgModal').style.display = 'none';
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImage(); });
    </script>

    <!-- Image Preview Modal -->
    <div id="imgModal" onclick="closeImage()">
        <span class="close-modal" onclick="closeImage()" title="Close">&times;</span>
        <img id="modalImg" alt="Issue Image Preview" onclick="event.stopPropagation()">
    </div>

<script src="/HMS/desktop_notify.php"></script>
</body>

</html>