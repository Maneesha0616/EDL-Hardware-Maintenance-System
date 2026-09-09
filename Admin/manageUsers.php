<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

//page potection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

//fetch admin username and profile image
$user_id = $_SESSION['user_id'];
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$stmt = sqlsrv_prepare($conn, $sql, array($user_id));
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'Admin';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage)
    && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifSql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt = sqlsrv_prepare($conn, $notifSql, array($user_id));
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
sqlsrv_execute($stmt);
$notifRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

$success = $error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST['name']);
    $uname = trim($_POST['username']);
    $phone = trim($_POST['phone']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = trim($_POST['role']);

    // Check duplicate username or phone using sqlsrv
    $checkStmt = sqlsrv_prepare($conn, "SELECT id FROM users WHERE username = ? OR phone = ?", array($uname, $phone));
    if ($checkStmt === false) {
        error_log("Duplicate check prepare error: " . print_r(sqlsrv_errors(), true));
        $error = "Database error.";
    } else {
        sqlsrv_execute($checkStmt);
        $dupRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
        if ($dupRow) {
            $error = "Username or Phone Number already exists!";
        } else {
            try {
                // Insert new user using sqlsrv
                $insertStmt = sqlsrv_prepare($conn, "INSERT INTO users (name, username, phone, password, role) VALUES (?, ?, ?, ?, ?)", array($name, $uname, $phone, $password, $role));
                if ($insertStmt === false) {
                    error_log("Insert prepare error: " . print_r(sqlsrv_errors(), true));
                    $error = "Something went wrong!";
                } else {
                    sqlsrv_execute($insertStmt);
                    $success = "User created successfully!";
                    log_system_activity($conn, $_SESSION['user_id'], 'Create User', "Created user '{$uname}' with role '{$role}'");
                }
            } catch (Exception $e) {
                error_log("Database Error in manageUsers.php (insert): " . $e->getMessage());
                $error = "Something went wrong!";
            }
        }
    }
}


//delete user 
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);

    // Prevent deleting Admin
    if ($del_id != $_SESSION['user_id']) {
        // Fetch username first for descriptive logging
        // Delete user using sqlsrv
        $usrStmt = sqlsrv_prepare($conn, "SELECT username FROM users WHERE id = ?", array($del_id));
        if ($usrStmt === false) {
            error_log("Delete fetch username prepare error: " . print_r(sqlsrv_errors(), true));
            $del_uname = "ID $del_id";
        } else {
            sqlsrv_execute($usrStmt);
            $usrRow = sqlsrv_fetch_array($usrStmt, SQLSRV_FETCH_ASSOC);
            $del_uname = $usrRow['username'] ?? "ID $del_id";
        }
        $delStmt = sqlsrv_prepare($conn, "DELETE FROM users WHERE id = ?", array($del_id));
        if ($delStmt === false) {
            error_log("Delete prepare error: " . print_r(sqlsrv_errors(), true));
        } else {
            sqlsrv_execute($delStmt);
            log_system_activity($conn, $_SESSION['user_id'], 'Delete User', "Deleted user '{$del_uname}' (ID {$del_id})");
        }
        header("Location: manageUsers.php");
        exit();
    }
}
//fetch all users
try {
    // Fetch all users using sqlsrv
    $users = [];
    $usersStmt = sqlsrv_query($conn, "SELECT id, name, username, phone, role FROM users ORDER BY id DESC");
    if ($usersStmt === false) {
        error_log("Fetch users error: " . print_r(sqlsrv_errors(), true));
    } else {
        while ($row = sqlsrv_fetch_array($usersStmt, SQLSRV_FETCH_ASSOC)) {
            $users[] = $row;
        }
    }
} catch (PDOException $e) {
    error_log("Database Error in manageUsers.php (fetch): " . $e->getMessage());
    $users = [];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 10px; }

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

        .menu { list-style: none; }
        .menu li { margin-bottom: 0.25rem; }

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

        .menu i { font-size: 1rem; width: 22px; text-align: center; }

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
            margin-left: 260px;
            width: calc(100% - 260px);
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
            border-radius: 22px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid #f1f5f9;
        }

        .section-title,
        .page-card h3 {
            font-size: 1.35rem;
            font-weight: 800;
            margin-bottom: 24px;
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
        input[type="tel"],
        textarea,
        select {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            font-size: 0.95rem;
            color: #334155;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
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

        .search-box input:hover,
        input:hover,
        textarea:hover,
        select:hover {
            border-color: #94a3b8;
        }

        label {
            display: block;
            margin-top: 16px;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e2a38;
            font-size: 0.95rem;
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
            grid-template-columns: 1fr 1fr;
            gap: 22px 25px;
            margin-bottom: 28px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .field {
            display: flex;
            flex-direction: column;
        }

        .field label {
            margin-top: 0;
            margin-bottom: 8px;
            white-space: nowrap;
        }

        .form-grid .field:last-child {
            grid-column: span 2;
        }

        @media (max-width: 768px) {
            .form-grid .field:last-child {
                grid-column: span 1;
            }
        }

        .btn-primary {
            background: linear-gradient(135deg, #007c91, #009db5);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.25s ease;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #009db5, #00b4cc);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 124, 145, 0.25);
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
            transform: scale(2);
            z-index: 10;
            position: relative;
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
                <li><a class="active" href="manageUsers.php"><i class="fas fa-users"></i> Manage Users</a></li>
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
                <h2>Manage Users</h2>
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

                <!--add users-->
                <div class="section-card">
                    <div class="section-title">Add New User</div>

                    <?php if ($success): ?>
                        <div class="alert-success">
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert-error">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="form-grid">

                            <div class="field">
                                <label>Full Name</label>
                                <input type="text" name="name" required>
                            </div>

                            <div class="field">
                                <label>Username</label>
                                <input type="text" name="username" required>
                            </div>

                            <div class="field">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" required>
                            </div>

                            <div class="field">
                                <label>Password</label>
                                <input type="password" name="password" required>
                            </div>

                            <div class="field">
                                <label>Role</label>
                                <select name="role" required>
                                    <option value="">Select role</option>
                                    <option value="admin">Admin</option>
                                    <option value="user">User</option>
                                    <option value="technician">Technician</option>
                                    <option value="storekeeper">Storekeeper</option>
                                    <option value="unithead">Unit Head</option>
                                </select>
                            </div>

                        </div>

                        <button class="btn-primary" type="submit">Create User</button>
                    </form>
                </div>

                <!--user list-->

                <div class="section-card">
                    <div class="section-title">User List</div>

                    <div style="margin-bottom: 15px;">
                        <input type="text" id="userSearch" placeholder="Search users..."
                            style="width: 100%; padding: 10px 14px; border-radius: 10px; border:1px solid #ccc; font-size:14px;">
                    </div>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Phone Number</th>
                                    <th>Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $i = 1;
                                foreach ($users as $row):
                                    $roleClass = strtolower(str_replace(' ', '', $row['role']));
                                    ?>
                                    <tr>
                                        <td>
                                            <?= $i++; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($row['name']); ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($row['username']); ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($row['phone']); ?>
                                        </td>
                                        <td>
                                            <span class="role-badge <?= $roleClass ?>">
                                                <?php
                                                $roleLabels = [
                                                    'admin' => 'Admin',
                                                    'user' => 'User',
                                                    'technician' => 'Technician',
                                                    'storekeeper' => 'Storekeeper',
                                                    'unithead' => 'Unit Head'
                                                ];
                                                echo $roleLabels[$row['role']] ?? ucfirst($row['role']);
                                                ?>
                                            </span>
                                        </td>

                                        <td class="actions">
                                            <a href="edit_user.php?id=<?= $row['id']; ?>" class="edit-btn">Edit</a>
                                            <?php if ($row['id'] != $_SESSION['user_id']): ?>
                                                <a href="manageUsers.php?delete=<?= $row['id']; ?>" class="delete-btn"
                                                    onclick="return confirm('Delete this user?')">
                                                    Delete
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                </div>

            </div>


            <footer class="footer">
                <div class="container" style="padding: 0; text-align: center;">
                    <img src="../images/hardware_logo.png" alt="Logo" style="width: 50px; border-radius: 10px; margin-bottom: 1rem;">
                    <h3>Hardware Maintenance System</h3>
                    <p style="margin-top: 1rem; color: #64748b;">&copy; <?php echo date('Y'); ?> Electricity Distribution Lanka (Private) Limited</p>
                    <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5; color: #64748b;">Helping you manage hardware tools and maintenance tasks.</p>
                </div>
            </footer>

        </main>
    </div>

    <script>
        function toggleDropdown() {
            const dropdown = document.getElementById("profileDropdown");
            dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
        }

        window.onclick = function (event) {
            if (!event.target.closest('.profile-btn')) {
                const dropdown = document.getElementById("profileDropdown");
                if (dropdown && dropdown.style.display === "block") {
                    dropdown.style.display = "none";
                }
            }
        }

        //search users
        document.getElementById('userSearch').addEventListener('keyup', function () {
            const filter = this.value.toLowerCase();
            const table = document.querySelector('.table-wrapper table tbody');
            const rows = table.querySelectorAll('tr');

            rows.forEach(row => {
                const cellsText = row.innerText.toLowerCase();
                if (cellsText.indexOf(filter) > -1) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>

<script src="/HMS/desktop_notify.php"></script>
</body>

</html>