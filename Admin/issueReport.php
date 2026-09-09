<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch admin username and profile image
$user_id = $_SESSION['user_id'];
$stmt = sqlsrv_prepare($conn, "SELECT username, profile_image FROM users WHERE id = ?", array($user_id));
if ($stmt === false) {
    error_log("Database error (admin fetch): " . print_r(sqlsrv_errors(), true));
    die("Failed to fetch admin data.");
}
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$username = $user['username'] ?? 'Admin';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifSql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$notifParams = array($user_id);
$notifStmt = sqlsrv_query($conn, $notifSql, $notifParams);
if ($notifStmt === false) {
    error_log("Database error (notification count): " . print_r(sqlsrv_errors(), true));
    $unreadCount = 0;
} else {
    $notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
    $unreadCount = $notifRow['total'] ?? 0;
    sqlsrv_free_stmt($notifStmt);
}

// Status counts
function getCount($conn, $status)
{
    try {
        $sql = "SELECT COUNT(*) AS total FROM issues WHERE status = ?";
        $params = array($status);
        $stmt = sqlsrv_prepare($conn, $sql, $params);
        if ($stmt === false) {
            error_log("Database error (getCount): " . print_r(sqlsrv_errors(), true));
            return 0;
        }
        sqlsrv_execute($stmt);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row['total'] ?? 0;
    } catch (Exception $e) {
        error_log("Database Error in issueReport.php getCount: " . $e->getMessage());
        return 0;
    }
}

$resolved = getCount($conn, "Resolved");
$inProgress = getCount($conn, "In Progress");
$waitingParts = getCount($conn, "Waiting Parts");

try {
    // Total issues count
    $totalRes = sqlsrv_query($conn, "SELECT COUNT(*) as total FROM issues");
    if ($totalRes === false) {
        error_log("Database error (total issues): " . print_r(sqlsrv_errors(), true));
        $totalIssues = 0;
    } else {
        $row = sqlsrv_fetch_array($totalRes, SQLSRV_FETCH_ASSOC);
        $totalIssues = $row['total'] ?? 0;
        sqlsrv_free_stmt($totalRes);
    }

    /* FETCH ALL ISSUE DETAILS */
    $sql = "SELECT 
    i.issue_id,
    i.issue_type,
    i.issue_category,
    i.software_name,
    i.issue_description,
    i.additional_information,
    i.service_tag,
    i.image,
    i.status,
    i.recommendation,
    i.received_date,
    i.assigned_date,
    i.completed_date,
    u.username AS username,
    t.username AS technician
FROM issues i
LEFT JOIN users u ON i.user_id = u.id
LEFT JOIN users t ON i.technician_id = t.id
ORDER BY i.received_date DESC";
    $stmt = sqlsrv_prepare($conn, $sql);
    if ($stmt === false) {
        error_log("Database error (fetch issues): " . print_r(sqlsrv_errors(), true));
        $issues = [];
    } else {
        if (!sqlsrv_execute($stmt)) {
            die(print_r(sqlsrv_errors(), true));
        }
        $issues = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $issues[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} catch (Exception $e) {
    error_log("Database Error in issueReport.php: " . $e->getMessage());
    $totalIssues = 0;
    $issues = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue Reports | HMS Admin</title>
    <meta name="description" content="Admin Issue Reports — view and track all hardware maintenance issues.">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary: #007c91;
            --primary-dark: #005662;
            --secondary: #1e2a38;
            --text-muted: #94a3b8;
            --sidebar-width: 260px;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --glass: rgba(255, 255, 255, 0.85);
            --radius: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: var(--bg-body);
            color: var(--text-main);
            overflow-x: hidden;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* ─── SIDEBAR ─── */
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

        /* ─── MAIN CONTENT ─── */
        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ─── HEADER ─── */
        header {
            background: var(--glass);
            backdrop-filter: blur(10px);
            padding: 1rem 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 900;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        header h2 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--secondary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .notification-bell {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 1.1rem;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .notification-bell:hover {
            background: var(--bg-body);
            color: var(--primary);
            transform: scale(1.05);
        }

        .notification-count {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--danger);
            color: white;
            font-size: 0.68rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            padding: 0 3px;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.4rem 1rem;
            background: #fff;
            border-radius: 100px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }

        .profile-btn:hover {
            border-color: var(--primary);
            background: #fafafa;
        }

        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
        }

        .profile-btn span {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .dropdown {
            display: none;
            position: absolute;
            right: 2rem;
            top: 4.8rem;
            background: #fff;
            width: 200px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            z-index: 1000;
            animation: slideDown 0.22s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            transition: 0.2s;
        }

        .dropdown a:hover {
            background: #f8fafc;
            color: var(--primary);
            padding-left: 25px;
        }

        /* ─── PAGE CONTENT ─── */
        .content {
            padding: 2rem 2.5rem;
            flex-grow: 1;
        }

        /* ─── STAT CARDS ─── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem 1.75rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: rgba(0, 124, 145, 0.15);
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .icon-total {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .icon-resolved {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .icon-progress {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .icon-waiting {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .stat-info .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--secondary);
            line-height: 1.1;
        }

        .stat-info .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 2px;
        }

        /* ─── TABLE CARD ─── */
        .table-card {
            background: white;
            border-radius: 20px;
            padding: 1.75rem 2rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .table-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .table-card-header h3 {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--secondary);
        }

        /* ─── FILTERS ─── */
        .filters-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filters-row input[type="text"],
        .filters-row select {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.875rem;
            color: var(--secondary);
            background: #f8fafc;
            outline: none;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .filters-row input[type="text"] {
            min-width: 240px;
        }

        .filters-row select {
            min-width: 170px;
        }

        .filters-row input[type="text"]:focus,
        .filters-row select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 124, 145, 0.12);
            background: #fff;
        }

        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s;
            font-family: inherit;
        }

        .btn-export:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 124, 145, 0.25);
        }

        /* ─── TABLE ─── */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.855rem;
            color: #334155;
        }

        thead {
            background: #007c91;
        }

        thead th {
            background: transparent;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 14px 16px;
            text-align: left;
            white-space: nowrap;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            position: relative;
        }

        thead th:first-child {
            border-radius: 12px 0 0 0;
        }

        thead th:last-child {
            border-radius: 0 12px 0 0;
        }

        tbody td {
            padding: 13px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.18s;
        }

        tbody tr:hover {
            background: rgba(0, 124, 145, 0.04);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .issue-id {
            font-weight: 800;
            color: var(--primary);
        }

        .wrap-text {
            max-width: 160px;
            word-break: break-word;
            white-space: normal;
            line-height: 1.4;
        }

        /* Thumbnail */
        .thumb {
            width: 52px;
            height: 52px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid #e2e8f0;
            transition: transform 0.2s, border-color 0.2s;
        }

        .thumb:hover {
            transform: scale(1.1);
            border-color: var(--primary);
        }

        /* ─── STATUS BADGES ─── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .status-Pending {
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
        }

        .status-Assigned {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
        }

        .status-InProgress {
            background: rgba(139, 92, 246, 0.12);
            color: #6d28d9;
        }

        .status-WaitingParts {
            background: rgba(239, 68, 68, 0.12);
            color: #b91c1c;
        }

        .status-Resolved {
            background: rgba(16, 185, 129, 0.12);
            color: #065f46;
        }

        .status-Completed {
            background: rgba(16, 185, 129, 0.15);
            color: #065f46;
        }

        .status-Cancelled {
            background: rgba(100, 116, 139, 0.12);
            color: #475569;
        }

        /* ─── EMPTY STATE ─── */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
            opacity: 0.4;
        }

        .empty-state p {
            font-weight: 600;
            font-size: 0.95rem;
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

        /* ─── FOOTER ─── */
        .footer {
            background: #fff;
            padding: 2.5rem;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            margin-top: auto;
        }

        .footer img {
            width: 44px;
            border-radius: 10px;
            margin-bottom: 0.75rem;
        }

        .footer h3 {
            color: var(--secondary);
            font-size: 1.1rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .footer p {
            color: #64748b;
            font-size: 0.85rem;
        }

        /* ─── PRINT ─── */
        @media print {

            .sidebar,
            header,
            .top-controls,
            .footer {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 0;
            }

            body {
                background: #fff;
            }

            .table-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }

        /* ─── DETAILS MODAL ─── */
        #detailsModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(10px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .modal-card {
            background: white;
            width: 100%;
            max-width: 850px;
            border-radius: 28px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            animation: modalSlideUp 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 1.75rem 2.5rem;
            background: var(--primary);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 4px solid rgba(255, 255, 255, 0.1);
        }

        .modal-header h3 {
            font-size: 1.3rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-body {
            padding: 2.5rem;
            overflow-y: auto;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            background: #fff;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f1f5f9;
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--secondary);
            font-weight: 500;
            line-height: 1.5;
        }

        .detail-full {
            grid-column: span 2;
        }

        .modal-footer {
            padding: 1.5rem 2.5rem;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
        }

        .btn-view-details {
            padding: 8px 16px;
            background: rgba(0, 124, 145, 0.1);
            color: var(--primary);
            border-radius: 100px;
            border: 1px solid rgba(0, 124, 145, 0.2);
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-view-details:hover {
            background: var(--primary);
            color: white;
            box-shadow: 0 5px 15px rgba(0, 124, 145, 0.2);
            transform: translateY(-2px);
        }

        .modal-image-container {
            width: 100%;
            height: 200px;
            background: #f1f5f9;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 10px;
            border: 2px dashed #e2e8f0;
        }

        .modal-image-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .modal-image-container img:hover {
            transform: scale(1.05);
        }

        @media (max-width: 768px) {
            .modal-body {
                grid-template-columns: 1fr;
                padding: 1.5rem;
            }

            .detail-full {
                grid-column: span 1;
            }
        }

        /* ─── RESPONSIVE ─── */
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

        @media (max-width: 576px) {
            header {
                padding: 1rem;
            }

            .content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
            }

            .table-card {
                padding: 1rem;
            }

            .filters-row input[type="text"] {
                min-width: 100%;
            }

            .filters-row select {
                min-width: 100%;
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
                <li><a class="active" href="issueReport.php"><i class="fas fa-file-alt"></i> Issue Reports</a></li>
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
                <h2><i class="fas fa-file-alt" style="color:var(--primary); margin-right:8px;"></i>Issue Reports</h2>
                <div class="header-right">

                    <a href="adminNotifications.php" class="notification-bell" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-count"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>

                    <button class="profile-btn" onclick="toggleDropdown()" id="profileToggleBtn">
                        <?php if ($hasProfileImage): ?>
                            <img src="../uploads/<?= htmlspecialchars($profileImage); ?>" class="profile-pic" alt="Profile">
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size:1.3rem; color:#64748b;"></i>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($username); ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.75rem; color:#64748b;"></i>
                    </button>

                    <div class="dropdown" id="profileDropdown">
                        <a href="../userprofile.php"><i class="fas fa-user"></i> View Profile</a>
                        <a href="../logout.php" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </header>

            <div class="content">


                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon icon-total"><i class="fas fa-layer-group"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $totalIssues ?></div>
                            <div class="stat-label">Total Issues</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon icon-resolved"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $resolved ?></div>
                            <div class="stat-label">Resolved</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon icon-progress"><i class="fas fa-tools"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $inProgress ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon icon-waiting"><i class="fas fa-clock"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $waitingParts ?></div>
                            <div class="stat-label">Waiting Parts</div>
                        </div>
                    </div>
                </div>


                <div class="table-card">
                    <div class="table-card-header">
                        <h3>All Issues</h3>

                        <div class="top-controls" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">

                            <div class="filters-row">
                                <input type="text" id="searchInput" placeholder="Search issues…"
                                    oninput="filterTable()">
                                <select id="statusFilter" onchange="filterTable()">
                                    <option value="">All Statuses</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Assigned">Assigned</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Waiting Parts">Waiting Parts</option>
                                    <option value="Resolved">Resolved</option>

                                </select>
                            </div>


                            <div class="action-buttons" style="display:flex; gap:10px;">
                                <button class="btn-export pdf" onclick="exportData('pdf')"
                                    style="background:var(--primary); color:white; border:none; padding:8px 18px; border-radius:10px; cursor:pointer; font-weight:700; font-size:0.85rem; display:flex; align-items:center; gap:8px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0, 124, 145, 0.2);">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </button>
                                <button class="btn-export excel" onclick="exportData('excel')"
                                    style="background:var(--primary); color:white; border:none; padding:8px 18px; border-radius:10px; cursor:pointer; font-weight:700; font-size:0.85rem; display:flex; align-items:center; gap:8px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(30, 42, 56, 0.2);">
                                    <i class="fas fa-file-excel"></i> Excel
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table id="issueTable">
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Username</th>
                                    <th>Type</th>
                                    <th>Category</th>
                                    <th>Software</th>
                                    <th>Description</th>
                                    <th>Add. Info</th>
                                    <th>Service Tag</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="issueTableBody">
                                <?php
                                $rowCount = 0;
                                foreach ($issues as $row):
                                    $rowCount++;
                                    $rawStatus = $row['status'] ?? 'Pending';
                                    $statusClass = 'status-' . str_replace(' ', '', $rawStatus);
                                    ?>
                                    <tr>
                                        <td><span class="issue-id">#<?= $row['issue_id'] ?></span></td>
                                        <td><?= htmlspecialchars($row['username'] ?? 'Deleted User') ?></td>
                                        <td><?= htmlspecialchars($row['issue_type']) ?></td>
                                        <td><?= htmlspecialchars($row['issue_category']) ?></td>
                                        <td><?= !empty($row['software_name']) ? htmlspecialchars($row['software_name']) : '<span style="color:#cbd5e1;">—</span>' ?>
                                        </td>
                                        <td class="wrap-text"><?= htmlspecialchars($row['issue_description']) ?></td>
                                        <td class="wrap-text">
                                            <?= !empty($row['additional_information']) ? nl2br(htmlspecialchars($row['additional_information'])) : '<span style="color:#cbd5e1;">—</span>' ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['service_tag']) ?></td>
                                        <td style="text-align:center;">
                                            <button class="btn-view-details" onclick="openDetails(this)"
                                                data-id="<?= $row['issue_id'] ?>"
                                                data-user="<?= htmlspecialchars($row['username'] ?? 'Deleted User') ?>"
                                                data-type="<?= htmlspecialchars($row['issue_type']) ?>"
                                                data-category="<?= htmlspecialchars($row['issue_category']) ?>"
                                                data-software="<?= htmlspecialchars($row['software_name'] ?? '—') ?>"
                                                data-desc="<?= htmlspecialchars($row['issue_description']) ?>"
                                                data-info="<?= htmlspecialchars($row['additional_information'] ?? '—') ?>"
                                                data-tag="<?= htmlspecialchars($row['service_tag'] ?? '—') ?>"
                                                data-received="<?= !empty($row['received_date']) ? (($row['received_date'] instanceof DateTime) ? $row['received_date']->format('M d, Y g:i A') : date('M d, Y g:i A', strtotime($row['received_date']))) : '—' ?>"
                                                data-technician="<?= htmlspecialchars($row['technician'] ?? 'Unassigned') ?>"
                                                data-assigned="<?= !empty($row['assigned_date']) ? (($row['assigned_date'] instanceof DateTime) ? $row['assigned_date']->format('M d, Y g:i A') : date('M d, Y g:i A', strtotime($row['assigned_date']))) : '—' ?>"
                                                data-completed="<?= !empty($row['completed_date']) ? (($row['completed_date'] instanceof DateTime) ? $row['completed_date']->format('M d, Y g:i A') : date('M d, Y g:i A', strtotime($row['completed_date']))) : '—' ?>"
                                                data-recommendation="<?= htmlspecialchars($row['recommendation'] ?? '—') ?>"
                                                data-status="<?= htmlspecialchars($rawStatus) ?>"
                                                data-image="<?= !empty($row['image']) ? '../uploads/issues/' . $row['image'] : '' ?>">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if ($rowCount === 0): ?>
                                    <tr>
                                        <td colspan="15">
                                            <div class="empty-state">
                                                <i class="fas fa-inbox"></i>
                                                <p>No issues found.</p>
                                            </div>
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


    <div id="imgModal" onclick="closeImage()">
        <span class="close-modal" onclick="closeImage()" title="Close">&times;</span>
        <img id="modalImg" alt="Issue Image Preview" onclick="event.stopPropagation()">
    </div>


    <div id="detailsModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice"></i> Issue Details <span id="modalIssueId"
                        style="opacity:0.7; font-size:1rem; margin-left:10px;"></span></h3>
                <span style="cursor:pointer; font-size:1.5rem;" onclick="closeDetails()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-user"></i> Reported By</span>
                    <span class="detail-value" id="modalUser"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-info-circle"></i> Status</span>
                    <span id="modalStatusBadge"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-microchip"></i> Issue Type</span>
                    <span class="detail-value" id="modalType"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-tags"></i> Category</span>
                    <span class="detail-value" id="modalCategory"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-laptop-code"></i> Software</span>
                    <span class="detail-value" id="modalSoftware"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-hashtag"></i> Service Tag</span>
                    <span class="detail-value" id="modalTag"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-calendar-alt"></i> Received Date</span>
                    <span class="detail-value" id="modalReceived"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-user-gear"></i> Assigned Technician</span>
                    <span class="detail-value" id="modalTechnician"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-calendar-check"></i> Assigned Date</span>
                    <span class="detail-value" id="modalAssigned"></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label"><i class="fas fa-flag-checkered"></i> Completed Date</span>
                    <span class="detail-value" id="modalCompleted"></span>
                </div>
                <div class="detail-item detail-full">
                    <span class="detail-label"><i class="fas fa-align-left"></i> Issue Description</span>
                    <span class="detail-value" id="modalDesc"></span>
                </div>
                <div class="detail-item detail-full">
                    <span class="detail-label"><i class="fas fa-plus-circle"></i> Additional Info</span>
                    <span class="detail-value" id="modalInfo"></span>
                </div>
                <div class="detail-item detail-full">
                    <span class="detail-label"><i class="fas fa-comment-dots"></i> Final Recommendation</span>
                    <span class="detail-value" id="modalRecommendation"
                        style="color:var(--primary); font-weight:700;"></span>
                </div>
                <div class="detail-item detail-full" id="modalImageSection">
                    <span class="detail-label"><i class="fas fa-image"></i> Attachment</span>
                    <div class="modal-image-container">
                        <img id="modalDetailImg" src="" alt="Issue Attachment" onclick="openImage(this.src)">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-export" onclick="closeDetails()" style="background:#64748b;">Close</button>
            </div>
        </div>
    </div>

    <script>

        function toggleDropdown() {
            const d = document.getElementById('profileDropdown');
            d.style.display = d.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function (e) {
            const btn = document.getElementById('profileToggleBtn');
            const dd = document.getElementById('profileDropdown');
            if (!btn.contains(e.target) && !dd.contains(e.target)) {
                dd.style.display = 'none';
            }
        });


        function openImage(src) {
            document.getElementById('modalImg').src = src;
            document.getElementById('imgModal').style.display = 'flex';
        }
        function closeImage() {
            document.getElementById('imgModal').style.display = 'none';
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImage(); });


        function filterTable() {
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            const status = document.getElementById('statusFilter').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#issueTableBody tr');

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const viewBtn = row.querySelector('.btn-view-details');
                const rowStatus = viewBtn && viewBtn.dataset.status ? viewBtn.dataset.status.toLowerCase() : '';

                const matchSearch = !search || text.includes(search);
                const matchStatus = !status || rowStatus === status;

                row.style.display = (matchSearch && matchStatus) ? '' : 'none';
            });
        }


        function exportData(type) {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;

            let url = (type === 'pdf') ? 'exportIssuesPDF.php' : 'exportIssuesExcel.php';
            url += `?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`;

            window.open(url, '_blank');
        }


        function openDetails(btn) {
            const data = btn.dataset;

            document.getElementById('modalIssueId').innerText = '#' + data.id;
            document.getElementById('modalUser').innerText = data.user;
            document.getElementById('modalType').innerText = data.type;
            document.getElementById('modalCategory').innerText = data.category;
            document.getElementById('modalSoftware').innerText = data.software;
            document.getElementById('modalDesc').innerText = data.desc;
            document.getElementById('modalInfo').innerText = data.info;
            document.getElementById('modalTag').innerText = data.tag;
            document.getElementById('modalReceived').innerText = data.received;
            document.getElementById('modalTechnician').innerText = data.technician;
            document.getElementById('modalAssigned').innerText = data.assigned;
            document.getElementById('modalCompleted').innerText = data.completed;
            document.getElementById('modalRecommendation').innerText = data.recommendation;


            const statusBadge = document.getElementById('modalStatusBadge');
            statusBadge.className = 'status-badge status-' + data.status.replace(/\s+/g, '');
            statusBadge.innerText = data.status;


            const imgSection = document.getElementById('modalImageSection');
            const modalImg = document.getElementById('modalDetailImg');
            if (data.image) {
                modalImg.src = data.image;
                imgSection.style.display = 'block';
            } else {
                imgSection.style.display = 'none';
            }

            document.getElementById('detailsModal').style.display = 'flex';
            document.body.style.overflow = 'hidden'; // Prevent background scroll
        }

        function closeDetails() {
            document.getElementById('detailsModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }


        window.addEventListener('click', function (e) {
            const modal = document.getElementById('detailsModal');
            if (e.target === modal) {
                closeDetails();
            }
        });
    </script>

    <script src="/HMS/desktop_notify.php"></script>
</body>

</html>