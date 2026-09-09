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

// Fetch technician info using SQLSRV
$sql = "SELECT username, profile_image FROM users WHERE id = ?";
$params = array(&$user_id);
$stmt = sqlsrv_prepare($conn, $sql, $params);
if(!$stmt) { die(print_r(sqlsrv_errors(), true)); }
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

$username = $user['username'] ?? 'Technician';
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count using SQLSRV
$sql = "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0";
$params = array(&$user_id);
$notifStmt = sqlsrv_query($conn, $sql, $params);
if($notifStmt === false){ die(print_r(sqlsrv_errors(), true)); }
$notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

// Fetch resolved issues using SQLSRV
$sql = "SELECT i.*, u.username AS user_name FROM issues i LEFT JOIN users u ON i.user_id = u.id WHERE i.technician_id = ? AND i.status = 'Resolved' ORDER BY i.completed_date DESC, i.updated_at DESC";
$params = array(&$user_id);
$completedIssues = sqlsrv_query($conn, $sql, $params);
if($completedIssues === false){ die(print_r(sqlsrv_errors(), true)); }

// Count resolved issues using SQLSRV
$sql = "SELECT COUNT(*) as total_completed FROM issues WHERE technician_id = ? AND status = 'Resolved'";
$params = array(&$user_id);
$countStmt = sqlsrv_query($conn, $sql, $params);
if($countStmt === false){ die(print_r(sqlsrv_errors(), true)); }
$rowCount = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
$totalCount = $rowCount['total_completed'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Completed Issues | Hardware Maintenance System</title>
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

        .page-container {
            padding: 2.5rem;
            max-width: 1500px;
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

        .summary-banner {
            background: rgba(0, 124, 145, 0.05);
            color: var(--primary);
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 700;
            border: 1px solid rgba(0, 124, 145, 0.1);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

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
            padding: 18px 15px;
            text-align: left;
            font-size: 0.8rem;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #005662;
        }

        td {
            padding: 18px 15px;
            font-size: 0.85rem;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .wrap-text {
            max-width: 200px;
            line-height: 1.6;
            color: #475569;
        }

        .status-Resolved {
            background: #f0fdf4;
            color: #166534;
            padding: 6px 14px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid #dcfce7;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .spare-parts-box {
            min-width: 260px;
        }

        .spare-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.78rem;
            background: #f8fafc;
            border-radius: 10px;
            overflow: hidden;
        }

        .spare-table th,
        .spare-table td {
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }

        .spare-table th {
            background: #eaf6f8;
            color: #0f4c5c;
            font-size: 0.72rem;
            text-transform: uppercase;
        }

        .no-parts {
            color: #94a3b8;
            font-style: italic;
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
                transform: translateX(-100%);
                width: 0;
                padding: 0;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }

        /* ─── DRAWER OVERLAY ─── */
        #drawerOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(10, 18, 35, 0.55);
            backdrop-filter: blur(4px);
            z-index: 9000;
            cursor: pointer;
            animation: overlayIn 0.3s ease forwards;
        }
        @keyframes overlayIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        /* ─── DRAWER PANEL ─── */
        #detailsDrawer {
            position: fixed;
            top: 0;
            right: 0;
            height: 100vh;
            width: 520px;
            max-width: 100vw;
            background: #f1f5f9;
            z-index: 9100;
            display: flex;
            flex-direction: column;
            box-shadow: -8px 0 40px rgba(0,0,0,0.22);
            transform: translateX(100%);
            transition: transform 0.38s cubic-bezier(0.32, 0, 0.67, 0);
        }
        #detailsDrawer.drawer-open {
            transform: translateX(0);
            transition: transform 0.38s cubic-bezier(0.33, 1, 0.68, 1);
        }

        /* ── Drawer Header ── */
        .drawer-header {
            flex-shrink: 0;
            background: linear-gradient(135deg, #007c91 0%, #004f61 100%);
            color: #fff;
            padding: 1.6rem 1.75rem 1.4rem;
            display: flex;
            flex-direction: column;
            gap: 4px;
            position: relative;
        }
        .drawer-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .drawer-header h3 {
            font-size: 1.15rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .drawer-header-meta {
            font-size: 0.82rem;
            opacity: 0.72;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .drawer-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(16,185,129,0.18);
            border: 1px solid rgba(16,185,129,0.45);
            color: #6ee7b7;
            padding: 4px 14px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-top: 10px;
            width: fit-content;
            letter-spacing: 0.4px;
        }
        .drawer-close-btn {
            background: rgba(255,255,255,0.12);
            border: none;
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 9px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .drawer-close-btn:hover { background: rgba(255,255,255,0.25); }

        /* ── Drawer Body (scrollable) ── */
        .drawer-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.25rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .drawer-body::-webkit-scrollbar { width: 4px; }
        .drawer-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        /* ── Drawer Cards ── */
        .drawer-card {
            background: #fff;
            border-radius: 14px;
            padding: 1.2rem 1.3rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 6px rgba(0,0,0,0.05);
        }
        .drawer-card-title {
            font-size: 0.65rem;
            font-weight: 800;
            color: #007c91;
            text-transform: uppercase;
            letter-spacing: 1.4px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .drawer-card-title i { font-size: 0.8rem; }

        /* ── Field grid ── */
        .drawer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .drawer-field { display: flex; flex-direction: column; gap: 3px; }
        .drawer-field-full { grid-column: span 2; }
        .drawer-field-label {
            font-size: 0.65rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .drawer-field-value {
            font-size: 0.92rem;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.5;
        }

        /* ── Vertical Timeline ── */
        .drawer-timeline {
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .dtl-step {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            position: relative;
        }
        .dtl-step:not(:last-child) .dtl-icon-wrap::after {
            content: '';
            display: block;
            width: 2px;
            background: #e2e8f0;
            flex: 1;
            min-height: 30px;
            margin: 4px auto 0;
        }
        .dtl-icon-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 36px;
            flex-shrink: 0;
        }
        .dtl-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            border: 2px solid;
            flex-shrink: 0;
        }
        .dtl-icon.received { background: rgba(59,130,246,0.1); color: #3b82f6; border-color: #3b82f6; }
        .dtl-icon.assigned  { background: rgba(245,158,11,0.1);  color: #f59e0b; border-color: #f59e0b; }
        .dtl-icon.resolved  { background: rgba(16,185,129,0.1);  color: #10b981; border-color: #10b981; }
        .dtl-content { padding-bottom: 22px; }
        .dtl-label { font-size: 0.68rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; }
        .dtl-date  { font-size: 0.88rem; font-weight: 700; color: #1e293b; margin-top: 2px; }
        .dtl-time  { font-size: 0.75rem; color: #94a3b8; }

        /* ── Recommendation ── */
        .drawer-reco {
            background: linear-gradient(135deg, rgba(0,124,145,0.05), rgba(0,79,97,0.03));
            border-left: 3px solid #007c91;
            border-radius: 0 10px 10px 0;
            padding: 0.85rem 1rem;
            font-size: 0.9rem;
            color: #1e293b;
            font-weight: 500;
            line-height: 1.65;
        }

        /* ── Spare table ── */
        .spare-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            border-radius: 10px;
            overflow: hidden;
        }
        .spare-table thead th {
            background: #007c91;
            color: #fff;
            font-weight: 700;
            padding: 9px 10px;
            text-align: left;
        }
        .spare-table tbody td {
            padding: 9px 10px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .spare-table tbody tr:last-child td { border-bottom: none; }
        .spare-table tbody tr:nth-child(even) td { background: #f8fafc; }
        .no-parts { color: #94a3b8; font-size: 0.88rem; font-style: italic; }

        /* ── Attachment ── */
        .drawer-image-box {
            width: 100%;
            max-height: 200px;
            background: #f1f5f9;
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #e2e8f0;
        }
        .drawer-image-box img {
            max-width: 100%;
            max-height: 200px;
            object-fit: contain;
            cursor: pointer;
            transition: transform 0.3s;
        }
        .drawer-image-box img:hover { transform: scale(1.04); }

        /* ── View button ── */
        .btn-view-details {
            padding: 8px 18px;
            background: rgba(0,124,145,0.1);
            color: #007c91;
            border-radius: 100px;
            border: 1px solid rgba(0,124,145,0.2);
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            gap: 8px;
            opacity: 1 !important;
        }
        .btn-view-details:hover {
            background: #007c91;
            color: #fff;
            box-shadow: 0 5px 15px rgba(0,124,145,0.25);
            transform: translateY(-2px);
        }

        /* ── Drawer footer ── */
        .drawer-footer {
            flex-shrink: 0;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: flex-end;
        }
        .drawer-footer button {
            background: #64748b;
            color: #fff;
            padding: 9px 22px;
            border-radius: 10px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.88rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .drawer-footer button:hover { background: #475569; }

        /* ─── IMAGE MODAL ─── */
        #imgModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.85);
            backdrop-filter: blur(6px);
            z-index: 20000;
            align-items: center;
            justify-content: center;
        }
        #imgModal img {
            max-width: 90vw;
            max-height: 85vh;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
            animation: zoomIn 0.25s ease;
        }
        @keyframes zoomIn {
            from { opacity: 0; transform: scale(0.85); }
            to   { opacity: 1; transform: scale(1); }
        }
        .close-modal {
            position: fixed;
            top: 1.5rem;
            right: 2rem;
            font-size: 2rem;
            color: white;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
            z-index: 30000;
        }
        .close-modal:hover { opacity: 1; }

        /* ─── DETAILS MODAL ─── (kept as empty stub so old selectors don't break) */
        #detailsModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(10, 20, 38, 0.8);
            backdrop-filter: blur(12px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .modal-card {
            background: #f8fafc;
            width: 100%;
            max-width: 920px;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 92vh;
            animation: modalSlideUp 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(50px) scale(0.95); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Modal Header Banner ── */
        .modal-header {
            padding: 2rem 2.5rem;
            background: linear-gradient(135deg, #007c91 0%, #005662 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-shrink: 0;
        }

        .modal-header-left h3 {
            font-size: 1.4rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 6px;
        }

        .modal-header-left .modal-subtitle {
            font-size: 0.85rem;
            opacity: 0.75;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            color: #fff;
            padding: 6px 16px;
            border-radius: 100px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-top: 10px;
        }

        .modal-close-btn {
            background: rgba(255,255,255,0.12);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .modal-close-btn:hover { background: rgba(255,255,255,0.25); }

        /* ── Modal Body ── */
        .modal-body {
            padding: 2rem 2.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            background: #f8fafc;
        }

        .modal-section {
            background: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid #f1f5f9;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .modal-section-title {
            font-size: 0.7rem;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.2rem;
        }

        .modal-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .modal-field-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .modal-field-value {
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 600;
            line-height: 1.5;
        }

        .modal-field-full {
            grid-column: span 2;
        }

        /* ── Timeline ── */
        .modal-timeline {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .timeline-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
        }

        .timeline-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 22px;
            left: 60%;
            width: 80%;
            height: 2px;
            background: linear-gradient(to right, #007c91, #e2e8f0);
        }

        .timeline-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 8px;
            border: 2px solid;
            position: relative;
            z-index: 1;
        }

        .timeline-icon.received  { background: rgba(59,130,246,0.1);  color: #3b82f6;  border-color: #3b82f6; }
        .timeline-icon.assigned  { background: rgba(245,158,11,0.1);  color: #f59e0b;  border-color: #f59e0b; }
        .timeline-icon.resolved  { background: rgba(16,185,129,0.1);  color: #10b981;  border-color: #10b981; }

        .timeline-label { font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
        .timeline-date  { font-size: 0.85rem; font-weight: 700; color: #1e293b; }
        .timeline-time  { font-size: 0.75rem; color: #94a3b8; }

        /* ── Recommendation block ── */
        .recommendation-block {
            background: linear-gradient(135deg, rgba(0,124,145,0.06), rgba(0,86,98,0.04));
            border-left: 4px solid var(--primary);
            border-radius: 0 12px 12px 0;
            padding: 1rem 1.25rem;
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 600;
            line-height: 1.6;
        }

        /* ── Spare parts table ── */
        .spare-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.83rem;
            border-radius: 10px;
            overflow: hidden;
        }
        .spare-table thead th {
            background: var(--primary);
            color: #fff;
            font-weight: 700;
            padding: 10px 12px;
            text-align: left;
        }
        .spare-table tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .spare-table tbody tr:last-child td { border-bottom: none; }
        .spare-table tbody tr:nth-child(even) td { background: #f8fafc; }

        .no-parts {
            color: #94a3b8;
            font-size: 0.9rem;
            font-style: italic;
        }

        /* ── Modal footer ── */
        .modal-footer {
            padding: 1.25rem 2.5rem;
            background: #fff;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            flex-shrink: 0;
        }

        /* ── View button ── */
        .btn-view-details {
            padding: 8px 18px;
            background: rgba(0, 124, 145, 0.1);
            color: var(--primary);
            border-radius: 100px;
            border: 1px solid rgba(0, 124, 145, 0.2);
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            gap: 8px;
            opacity: 1 !important;
        }
        .btn-view-details:hover {
            background: var(--primary);
            color: white;
            box-shadow: 0 5px 15px rgba(0,124,145,0.25);
            transform: translateY(-2px);
        }

        /* ── Attachment ── */
        .modal-image-container {
            width: 100%;
            max-height: 220px;
            background: #f1f5f9;
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #e2e8f0;
        }
        .modal-image-container img {
            max-width: 100%;
            max-height: 220px;
            object-fit: contain;
            cursor: pointer;
            transition: transform 0.3s;
        }
        .modal-image-container img:hover { transform: scale(1.04); }

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
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
            animation: zoomIn 0.25s ease;
        }
        @keyframes zoomIn {
            from { opacity: 0; transform: scale(0.85); }
            to   { opacity: 1; transform: scale(1); }
        }
        .close-modal {
            position: fixed;
            top: 1.5rem;
            right: 2rem;
            font-size: 2rem;
            color: white;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
            z-index: 30000;
        }
        .close-modal:hover { opacity: 1; }
    </style>
</head>

<body>

    <div class="layout">
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

        <main class="main-content">
            <header>
                <h2>Resolution History</h2>
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
                <div class="page-card">
                    <h3>Resolved Issue Log</h3>

                    <div class="summary-banner">
                        <i class="fas fa-clipboard-check"></i>
                        Total Resolved Issues: <?= $totalCount ?>
                    </div>

                    <div class="table-wrapper">
                        <?php if (sqlsrv_has_rows($completedIssues)): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>#ID</th>
                                        <th>User</th>
                                        <th>Issue Type</th>
                                        <th>Category</th>
                                        <th>Service Tag</th>
                                        <th>Resolved Date</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = sqlsrv_fetch_array($completedIssues, SQLSRV_FETCH_ASSOC)): ?>
                                        <tr>
                                            <td style="font-weight:700; color: var(--primary);">#<?= $row['issue_id'] ?></td>
                                            <td style="font-weight:600;">
                                                <?= htmlspecialchars($row['user_name'] ?? 'Deleted User') ?>
                                            </td>
                                            <td><?= htmlspecialchars($row['issue_type']) ?></td>
                                            <td><?= htmlspecialchars($row['issue_category'] ?? '—') ?></td>
                                            <td><code><?= htmlspecialchars($row['service_tag'] ?: '—') ?></code></td>
                                            <td>
                                                <div style="font-weight: 600; color: #166534;">
                                                    <?= !empty($row['completed_date']) ? ( ($row['completed_date'] instanceof DateTime) ? $row['completed_date']->format('Y-m-d') : date('Y-m-d', strtotime($row['completed_date'])) ) : '—' ?>
                                                </div>
                                                <div style="font-size: 0.7rem; color: #64748b;">
                                                    <?= !empty($row['completed_date']) ? ( ($row['completed_date'] instanceof DateTime) ? $row['completed_date']->format('h:i A') : date('h:i A', strtotime($row['completed_date'])) ) : '' ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-Resolved">Resolved</span>
                                            </td>
                                            <td style="text-align:center;">
                                                <button class="btn-view-details" onclick="openDetails(this.closest('tr'))"
                                                    data-id="<?= $row['issue_id'] ?>"
                                                    data-user="<?= htmlspecialchars($row['user_name'] ?? 'Deleted User') ?>"
                                                    data-type="<?= htmlspecialchars($row['issue_type']) ?>"
                                                    data-category="<?= htmlspecialchars($row['issue_category'] ?? '—') ?>"
                                                    data-desc="<?= htmlspecialchars($row['issue_description']) ?>"
                                                    data-info="<?= htmlspecialchars($row['additional_information'] ?? '—') ?>"
                                                    data-tag="<?= htmlspecialchars($row['service_tag'] ?? '—') ?>"
                                                    data-received="<?= !empty($row['received_date']) ? (($row['received_date'] instanceof DateTime) ? $row['received_date']->format('M d, Y g:i A') : date('M d, Y g:i A', strtotime($row['received_date']))) : '—' ?>"
                                                    data-assigned="<?= !empty($row['assigned_date']) ? (($row['assigned_date'] instanceof DateTime) ? $row['assigned_date']->format('M d, Y g:i A') : date('M d, Y g:i A', strtotime($row['assigned_date']))) : '—' ?>"
                                                    data-completed="<?= !empty($row['completed_date']) ? (($row['completed_date'] instanceof DateTime) ? $row['completed_date']->format('M d, Y g:i A') : date('M d, Y g:i A', strtotime($row['completed_date']))) : '—' ?>"
                                                    data-recommendation="<?= htmlspecialchars($row['recommendation'] ?? '—') ?>"
                                                    data-image="<?= !empty($row['image']) ? '../uploads/issues/' . $row['image'] : '' ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </button>

                                                <div class="row-spare-parts-html" style="display:none;">
                                                    <?php
                                                    $sql = "
                                            SELECT 
                                                pri.quantity_requested,
                                                pri.quantity_approved,
                                                pri.quantity_issued,
                                                pri.item_status,
                                                it.item_name,
                                                it.brand,
                                                it.capacity_type
                                            FROM parts_requests pr
                                            INNER JOIN parts_request_items pri ON pr.request_id = pri.request_id
                                            LEFT JOIN items it ON pri.item_id = it.item_id
                                            WHERE pr.issue_id = ?
                                            ORDER BY pri.id ASC
                                        ";
                                                    $params = array($row['issue_id']);
                                                    $spareStmt = sqlsrv_query($conn, $sql, $params);
                                                    $spareParts = [];
                                                    if ($spareStmt) {
                                                        while ($part = sqlsrv_fetch_array($spareStmt, SQLSRV_FETCH_ASSOC)) {
                                                            $spareParts[] = $part;
                                                        }
                                                        sqlsrv_free_stmt($spareStmt);
                                                    }
                                                    ?>

                                                    <?php if (!empty($spareParts)): ?>
                                                        <table class="spare-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Item</th>
                                                                    <th>Brand / Spec</th>
                                                                    <th>Req</th>
                                                                    <th>App</th>
                                                                    <th>Issued</th>
                                                                    <th>Status</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($spareParts as $part): ?>
                                                                    <tr>
                                                                        <td><?= htmlspecialchars($part['item_name'] ?? '—') ?></td>
                                                                        <td><?= htmlspecialchars(trim(($part['brand'] ?? '') . ' ' . ($part['capacity_type'] ?? ''))) ?: '—' ?>
                                                                        </td>
                                                                        <td><?= intval($part['quantity_requested']) ?></td>
                                                                        <td><?= intval($part['quantity_approved']) ?></td>
                                                                        <td><?= intval($part['quantity_issued']) ?></td>
                                                                        <td><?= htmlspecialchars($part['item_status'] ?? '—') ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    <?php else: ?>
                                                        <span class="no-parts">No spare parts used</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding: 50px 20px; color: #64748b;">
                                <i class="fa-solid fa-folder-open"
                                    style="font-size: 3rem; color: #e2e8f0; margin-bottom: 15px; display: block;"></i>
                                <p>No completed issues in the record yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
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

    <!-- Drawer Overlay -->
    <div id="drawerOverlay" onclick="closeDetails()"></div>

    <!-- Issue Details Drawer -->
    <div id="detailsDrawer">

        <!-- Header -->
        <div class="drawer-header">
            <div class="drawer-header-top">
                <h3><i class="fas fa-file-medical-alt"></i> Issue Details</h3>
                <button class="drawer-close-btn" onclick="closeDetails()"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="drawer-header-meta">
                <i class="fas fa-hashtag"></i><span id="drawerIssueId"></span>
                &nbsp;·&nbsp;
                <i class="fas fa-user"></i><span id="drawerUser"></span>
            </div>
            <div class="drawer-status-badge"><i class="fas fa-circle-check"></i> Resolved</div>
        </div>

        <!-- Scrollable Body -->
        <div class="drawer-body">

            <!-- Overview -->
            <div class="drawer-card">
                <div class="drawer-card-title"><i class="fas fa-circle-info"></i> Issue Overview</div>
                <div class="drawer-grid">
                    <div class="drawer-field">
                        <span class="drawer-field-label">Issue Type</span>
                        <span class="drawer-field-value" id="drawerType"></span>
                    </div>
                    <div class="drawer-field">
                        <span class="drawer-field-label">Category</span>
                        <span class="drawer-field-value" id="drawerCategory"></span>
                    </div>
                    <div class="drawer-field">
                        <span class="drawer-field-label">Service Tag</span>
                        <span class="drawer-field-value" id="drawerTag" style="font-family:monospace;"></span>
                    </div>
                    <div class="drawer-field">
                        <span class="drawer-field-label">Status</span>
                        <span class="drawer-field-value"><span class="status-Resolved" style="width:fit-content;">Resolved</span></span>
                    </div>
                    <div class="drawer-field drawer-field-full">
                        <span class="drawer-field-label">Description</span>
                        <span class="drawer-field-value" id="drawerDesc" style="font-weight:500; color:#475569;"></span>
                    </div>
                    <div class="drawer-field drawer-field-full">
                        <span class="drawer-field-label">Additional Info</span>
                        <span class="drawer-field-value" id="drawerInfo" style="font-weight:500; color:#475569;"></span>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="drawer-card">
                <div class="drawer-card-title"><i class="fas fa-timeline"></i> Resolution Timeline</div>
                <div class="drawer-timeline">
                    <div class="dtl-step">
                        <div class="dtl-icon-wrap">
                            <div class="dtl-icon received"><i class="fas fa-inbox"></i></div>
                        </div>
                        <div class="dtl-content">
                            <div class="dtl-label">Received</div>
                            <div class="dtl-date" id="drawerReceivedDate"></div>
                            <div class="dtl-time" id="drawerReceivedTime"></div>
                        </div>
                    </div>
                    <div class="dtl-step">
                        <div class="dtl-icon-wrap">
                            <div class="dtl-icon assigned"><i class="fas fa-user-gear"></i></div>
                        </div>
                        <div class="dtl-content">
                            <div class="dtl-label">Assigned</div>
                            <div class="dtl-date" id="drawerAssignedDate"></div>
                            <div class="dtl-time" id="drawerAssignedTime"></div>
                        </div>
                    </div>
                    <div class="dtl-step">
                        <div class="dtl-icon-wrap">
                            <div class="dtl-icon resolved"><i class="fas fa-circle-check"></i></div>
                        </div>
                        <div class="dtl-content">
                            <div class="dtl-label">Resolved</div>
                            <div class="dtl-date" id="drawerCompletedDate"></div>
                            <div class="dtl-time" id="drawerCompletedTime"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recommendation -->
            <div class="drawer-card">
                <div class="drawer-card-title"><i class="fas fa-comment-medical"></i> Final Recommendation</div>
                <div class="drawer-reco" id="drawerRecommendation"></div>
            </div>

            <!-- Spare Parts -->
            <div class="drawer-card" id="drawerSpareSection">
                <div class="drawer-card-title"><i class="fas fa-gears"></i> Spare Parts Used</div>
                <div id="drawerSpareParts"></div>
            </div>

            <!-- Attachment -->
            <div class="drawer-card" id="drawerImageSection" style="display:none;">
                <div class="drawer-card-title"><i class="fas fa-paperclip"></i> Attachment</div>
                <div class="drawer-image-box">
                    <img id="drawerDetailImg" src="" alt="Issue Attachment" onclick="openImage(this.src)">
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="drawer-footer">
            <button onclick="closeDetails()"><i class="fas fa-arrow-right"></i> Close</button>
        </div>

    </div>

    <!-- STUB: old #detailsModal kept so nothing breaks -->
    <div id="detailsModal" style="display:none;"><div class="modal-card" onclick="event.stopPropagation()">

            <!-- Header Banner -->
            <div class="modal-header">
                <div class="modal-header-left">
                    <h3><i class="fas fa-file-medical-alt"></i> Issue Details</h3>
                    <div class="modal-subtitle">
                        <i class="fas fa-hashtag"></i>
                        <span id="modalIssueId"></span>
                        &nbsp;·&nbsp;
                        <span id="modalUser"></span>
                    </div>
                    <div class="modal-status-pill">
                        <i class="fas fa-circle-check"></i> Resolved
                    </div>
                </div>
                <button class="modal-close-btn" onclick="closeDetails()">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <!-- Scrollable Body -->
            <div class="modal-body">

                <!-- Issue Overview Card -->
                <div class="modal-section">
                    <div class="modal-section-title"><i class="fas fa-circle-info"></i> Issue Overview</div>
                    <div class="modal-grid">
                        <div class="modal-field">
                            <span class="modal-field-label">Issue Type</span>
                            <span class="modal-field-value" id="modalType"></span>
                        </div>
                        <div class="modal-field">
                            <span class="modal-field-label">Category</span>
                            <span class="modal-field-value" id="modalCategory"></span>
                        </div>
                        <div class="modal-field">
                            <span class="modal-field-label">Service Tag</span>
                            <span class="modal-field-value" id="modalTag" style="font-family:monospace; font-size:1rem;"></span>
                        </div>
                        <div class="modal-field">
                            <span class="modal-field-label">Status</span>
                            <span class="modal-field-value"><span class="status-Resolved" style="width:fit-content;">Resolved</span></span>
                        </div>
                        <div class="modal-field modal-field-full">
                            <span class="modal-field-label">Description</span>
                            <span class="modal-field-value" id="modalDesc" style="font-weight:500; color:#475569;"></span>
                        </div>
                        <div class="modal-field modal-field-full" id="modalInfoField">
                            <span class="modal-field-label">Additional Information</span>
                            <span class="modal-field-value" id="modalInfo" style="font-weight:500; color:#475569;"></span>
                        </div>
                    </div>
                </div>

                <!-- Timeline Card -->
                <div class="modal-section">
                    <div class="modal-section-title"><i class="fas fa-timeline"></i> Resolution Timeline</div>
                    <div class="modal-timeline">
                        <div class="timeline-step">
                            <div class="timeline-icon received"><i class="fas fa-inbox"></i></div>
                            <div class="timeline-label">Received</div>
                            <div class="timeline-date" id="modalReceivedDate"></div>
                            <div class="timeline-time" id="modalReceivedTime"></div>
                        </div>
                        <div class="timeline-step">
                            <div class="timeline-icon assigned"><i class="fas fa-user-gear"></i></div>
                            <div class="timeline-label">Assigned</div>
                            <div class="timeline-date" id="modalAssignedDate"></div>
                            <div class="timeline-time" id="modalAssignedTime"></div>
                        </div>
                        <div class="timeline-step">
                            <div class="timeline-icon resolved"><i class="fas fa-circle-check"></i></div>
                            <div class="timeline-label">Resolved</div>
                            <div class="timeline-date" id="modalCompletedDate"></div>
                            <div class="timeline-time" id="modalCompletedTime"></div>
                        </div>
                    </div>
                </div>

                <!-- Recommendation Card -->
                <div class="modal-section">
                    <div class="modal-section-title"><i class="fas fa-comment-medical"></i> Final Recommendation</div>
                    <div class="recommendation-block" id="modalRecommendation"></div>
                </div>

                <!-- Spare Parts Card -->
                <div class="modal-section" id="modalSpareSection">
                    <div class="modal-section-title"><i class="fas fa-gears"></i> Spare Parts Used</div>
                    <div id="modalSpareParts"></div>
                </div>

                <!-- Attachment Card -->
                <div class="modal-section" id="modalImageSection" style="display:none;">
                    <div class="modal-section-title"><i class="fas fa-paperclip"></i> Attachment</div>
                    <div class="modal-image-container">
                        <img id="modalDetailImg" src="" alt="Issue Attachment" onclick="openImage(this.src)">
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="modal-footer">
                <button onclick="closeDetails()" style="background:#64748b; color:white; padding:9px 22px; border-radius:10px; border:none; font-weight:700; cursor:pointer; font-size:0.9rem; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-xmark"></i> Close
                </button>
            </div>

        </div>
    </div>

    </div></div>
    <!-- end stub -->

    <!-- Image Preview Modal -->
    <div id="imgModal" onclick="closeImage()">
        <span class="close-modal" onclick="closeImage()" title="Close">&times;</span>
        <img id="modalImg" alt="Issue Image Preview" onclick="event.stopPropagation()">
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

        // ─── Drawer Logic ───
        function splitDateTime(dt) {
            if (!dt || dt === '—') return { date: '—', time: '' };
            const parts = dt.split(' ');
            const timePart = parts[parts.length - 2] + ' ' + parts[parts.length - 1];
            const datePart = parts.slice(0, parts.length - 2).join(' ');
            return { date: datePart, time: timePart };
        }

        function openDetails(row) {
            const btn  = row.querySelector('.btn-view-details');
            const data = btn.dataset;

            // Header
            document.getElementById('drawerIssueId').innerText = '#' + data.id;
            document.getElementById('drawerUser').innerText    = data.user;

            // Overview
            document.getElementById('drawerType').innerText     = data.type;
            document.getElementById('drawerCategory').innerText = data.category;
            document.getElementById('drawerTag').innerText      = data.tag;
            document.getElementById('drawerDesc').innerText     = data.desc;
            document.getElementById('drawerInfo').innerText     = (data.info && data.info !== '—') ? data.info : 'None';

            // Timeline
            const recv = splitDateTime(data.received);
            const asgn = splitDateTime(data.assigned);
            const comp = splitDateTime(data.completed);
            document.getElementById('drawerReceivedDate').innerText  = recv.date;
            document.getElementById('drawerReceivedTime').innerText  = recv.time;
            document.getElementById('drawerAssignedDate').innerText  = asgn.date;
            document.getElementById('drawerAssignedTime').innerText  = asgn.time;
            document.getElementById('drawerCompletedDate').innerText = comp.date;
            document.getElementById('drawerCompletedTime').innerText = comp.time;

            // Recommendation
            document.getElementById('drawerRecommendation').innerText =
                (data.recommendation && data.recommendation !== '—') ? data.recommendation : 'No recommendation provided.';

            // Spare parts
            document.getElementById('drawerSpareParts').innerHTML =
                row.querySelector('.row-spare-parts-html').innerHTML;

            // Attachment
            const imgSection = document.getElementById('drawerImageSection');
            const drawerImg  = document.getElementById('drawerDetailImg');
            if (data.image) {
                drawerImg.src = data.image;
                imgSection.style.display = 'block';
            } else {
                imgSection.style.display = 'none';
            }

            // Open overlay + drawer
            document.getElementById('drawerOverlay').style.display = 'block';
            document.getElementById('detailsDrawer').classList.add('drawer-open');
            document.body.style.overflow = 'hidden';
        }

        function closeDetails() {
            document.getElementById('detailsDrawer').classList.remove('drawer-open');
            document.getElementById('drawerOverlay').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openImage(src) {
            document.getElementById('modalImg').src = src;
            document.getElementById('imgModal').style.display = 'flex';
        }

        function closeImage() {
            document.getElementById('imgModal').style.display = 'none';
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeImage();
                closeDetails();
            }
        });
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>