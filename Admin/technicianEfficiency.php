<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch admin details
$stmt = sqlsrv_prepare($conn, "SELECT username, profile_image FROM users WHERE id = ?", array($user_id));
if ($stmt === false) { die(print_r(sqlsrv_errors(), true)); }
sqlsrv_execute($stmt);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$user) {
    die("Admin details not found.");
}

$username = $user['username'];
$profileImage = $user['profile_image'] ?? '';
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifQuery = sqlsrv_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", array($user_id));
if ($notifQuery === false) { die(print_r(sqlsrv_errors(), true)); }
sqlsrv_execute($notifQuery);
$notifRow = sqlsrv_fetch_array($notifQuery, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;

$technicians = [];
$totalIssues = 0;
$totalTechnicians = 0;
$totalAreas = [];

// Technician performance query
$sql = "
    SELECT 
        t.id AS tech_id,
        t.username AS technician,
        u.username AS area_office,
        COUNT(i.issue_id) AS total_issues
    FROM issues i
    JOIN users t ON i.technician_id = t.id
    JOIN users u ON i.user_id = u.id
    WHERE t.role = 'technician'
    GROUP BY t.id, t.username, u.username
    ORDER BY t.username, total_issues DESC
";

// Execute performance query using PDO
$stmtPerf = sqlsrv_query($conn, $sql);
if ($stmtPerf) {
    while ($row = sqlsrv_fetch_array($stmtPerf, SQLSRV_FETCH_ASSOC)) {
        $tech = $row['technician'];
        $area = $row['area_office'];
        $count = (int) $row['total_issues'];

        if (!isset($technicians[$tech])) {
            $technicians[$tech] = [
                'areas' => [],
                'total' => 0
            ];
        }

        $technicians[$tech]['areas'][$area] = $count;
        $technicians[$tech]['total'] += $count;

        $totalIssues += $count;
        $totalAreas[$area] = true;
    }
}

$totalTechnicians = count($technicians);
$totalAreaCount = count($totalAreas);

// Sort technicians by total issue count descending
uasort($technicians, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

$topTechnician = $totalTechnicians > 0 ? array_key_first($technicians) : "N/A";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Efficiency</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.5;
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
            gap: 18px;
            position: relative;
        }

        .notification-bell {
            position: relative;
            color: #64748b;
            font-size: 1.25rem;
            text-decoration: none;
            width: 46px;
            height: 46px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .notification-bell:hover {
            color: #1e2a38;
            background: #e2e8f0;
        }

        .notification-count {
            position: absolute;
            top: -4px;
            right: -4px;
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

        .profile-btn {
            background: #f1f5f9;
            color: #1e2a38;
            border: 1px solid #e2e8f0;
            padding: 8px 16px;
            border-radius: 999px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .profile-btn:hover {
            background: #e2e8f0;
        }

        .profile-pic {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .dropdown {
            display: none;
            position: absolute;
            top: 68px;
            right: 0;
            background: #fff;
            width: 210px;
            border-radius: 16px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            z-index: 100;
        }

        .dropdown a {
            display: block;
            padding: 13px 18px;
            color: #1e2a38;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.92rem;
            transition: all 0.2s ease;
        }

        .dropdown a:hover {
            background: #f8fafc;
            color: #007c91;
        }

        /* Page Content */
        .page-content,
        .page-container,
        .container {
            padding: 30px 40px;
            flex-grow: 1;
        }

        /* Common Cards */
        .section-card,
        .page-card,
        .summary-card,
        .chart-card,
        .empty-state {
            background: #fff;
            border-radius: 22px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .section-card,
        .page-card {
            padding: 25px 30px;
            margin-bottom: 25px;
        }

        /* Titles */
        .section-title {
            margin-bottom: 24px;
        }

        .section-title h3,
        .page-card h3 {
            font-size: 1.3rem;
            font-weight: 800;
            color: #1e2a38;
            margin-bottom: 6px;
        }

        .section-title p {
            font-size: 0.95rem;
            color: #64748b;
        }

        /* General Grid */
        .dashboard,
        .cards,
        .summary-grid,
        .chart-grid {
            display: grid;
            gap: 24px;
        }

        .dashboard,
        .cards {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            margin-bottom: 30px;
        }

        .summary-grid {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-bottom: 30px;
        }

        .chart-grid {
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            margin-top: 15px;
        }

        /* Default card */
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            text-decoration: none;
            color: #1e2a38;
            transition: all 0.3s ease;
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
            transition: transform 0.3s ease;
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

        /* Summary Cards */
        .summary-card {
            padding: 24px;
            text-align: center;
            border-bottom: 5px solid #007c91;
            transition: all 0.25s ease;
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
            font-size: 1.08rem;
            color: #1e2a38;
            margin-bottom: 10px;
            font-weight: 800;
        }

        .summary-card .value {
            font-size: 2rem;
            font-weight: 800;
            color: #007c91;
            margin-bottom: 5px;
            line-height: 1.2;
            word-break: break-word;
        }

        .summary-card .subtext {
            font-size: 0.86rem;
            color: #64748b;
        }

        /* Chart Cards */
        .chart-card {
            padding: 24px;
            transition: all 0.25s ease;
        }

        .chart-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.07);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
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
            font-size: 0.86rem;
            color: #64748b;
        }

        .chart-wrap {
            height: 270px;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-wrap canvas {
            max-width: 100%;
            max-height: 100%;
        }

        .area-list {
            margin-top: 15px;
            border-top: 1px solid #f1f5f9;
            padding-top: 15px;
        }

        .area-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 10px 0;
            font-size: 0.92rem;
            color: #334155;
            border-bottom: 1px dashed #e2e8f0;
        }

        .area-item:last-child {
            border-bottom: none;
        }

        /* Badges */
        .badge,
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 800;
            text-align: center;
            background: rgba(0, 124, 145, 0.12);
            color: #007c91;
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
            padding: 13px 16px;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
            font-size: 0.95rem;
            color: #334155;
            transition: all 0.2s ease;
            background: #fff;
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

        /* Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button,
        .btn,
        .action-btn,
        .pdf-btn,
        .btn-primary {
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
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease-in-out;
        }

        button:hover,
        .btn:hover,
        .action-btn:hover,
        .pdf-btn:hover,
        .btn-primary:hover {
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
            width: 100%;
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
            transition: background-color 0.2s ease;
            background: rgba(255, 255, 255, 0.8);
        }

        tbody tr:nth-of-type(even) {
            background-color: #f8fafc;
        }

        tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.05);
        }

        td.actions {
            white-space: nowrap;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }

        .empty-state i {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            color: #1e2a38;
            margin-bottom: 8px;
            font-weight: 800;
        }

        .empty-state p {
            color: #64748b;
            font-size: 0.95rem;
        }

        /* Table Images */
        .table-wrapper img,
        table img {
            max-width: 65px;
            max-height: 65px;
            object-fit: cover;
            border-radius: 6px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
            cursor: pointer;
        }

        .table-wrapper img:hover,
        table img:hover {
            transform: scale(2);
            z-index: 10;
            position: relative;
        }

        /* Footer */
        .footer {
            background: #fff;
            padding: 35px 20px 40px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            margin-top: auto;
        }

        .footer img {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            margin-bottom: 12px;
            object-fit: cover;
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

        /* Print */
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
            .container,
            .page-content {
                padding: 0;
            }

            body {
                background: #fff;
            }

            .section-card,
            .page-card,
            .summary-card,
            .chart-card,
            .card,
            .empty-state {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
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

            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {

            .page-content,
            .page-container,
            .container {
                padding: 20px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .chart-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .chart-wrap {
                height: 240px;
            }
        }

        @media (max-width: 576px) {
            header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 15px 20px;
            }

            .header-right {
                width: 100%;
                justify-content: space-between;
            }

            .page-content,
            .page-container,
            .container {
                padding: 15px;
            }

            .section-card,
            .page-card,
            .summary-card,
            .chart-card,
            .card,
            .empty-state {
                padding: 20px 15px;
            }

            .dropdown {
                right: 0;
                width: 100%;
                max-width: 220px;
            }

            .profile-btn {
                padding: 8px 14px;
            }

            .chart-wrap {
                height: 220px;
            }
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
                <li><a class="active" href="technicianEfficiency.php"><i class="fas fa-chart-line"></i> Technician Efficiency</a></li>
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
                <h2>Technician Efficiency</h2>
                <div class="header-right">
                    <a href="adminNotifications.php" class="notification-bell">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-count"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>

                    <button class="profile-btn" onclick="toggleDropdown()">
                        <?php if ($hasProfileImage): ?>
                            <img src="../uploads/<?= htmlspecialchars($profileImage); ?>" alt="Profile" class="profile-pic">
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

            <div class="page-content">
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="icon"><i class="fas fa-tools"></i></div>
                        <h3>Total Technicians</h3>
                        <div class="value"><?= $totalTechnicians; ?></div>
                        <div class="subtext">Active technicians with assigned issues</div>
                    </div>

                    <div class="summary-card">
                        <div class="icon"><i class="fas fa-ticket-alt"></i></div>
                        <h3>Total Assigned Issues</h3>
                        <div class="value"><?= $totalIssues; ?></div>
                        <div class="subtext">All issues counted across technicians</div>
                    </div>

                    <div class="summary-card">
                        <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
                        <h3>Total Area Offices</h3>
                        <div class="value"><?= $totalAreaCount; ?></div>
                        <div class="subtext">Distinct reporting areas</div>
                    </div>

                    <div class="summary-card">
                        <div class="icon"><i class="fas fa-award"></i></div>
                        <h3>Top Loaded Technician</h3>
                        <div class="value" style="font-size:20px;"><?= htmlspecialchars($topTechnician); ?></div>
                        <div class="subtext">Highest issue allocation</div>
                    </div>
                </div>

                <div class="section-title">
                    <h3>Area-wise Technician Charts</h3>
                    <p>Each chart shows how issues are distributed by area office for each technician.</p>
                </div>

                <?php if (!empty($technicians)): ?>
                    <div class="chart-grid">
                        <?php $i = 0;
                        foreach ($technicians as $techName => $techData): ?>
                            <div class="chart-card">
                                <div class="chart-header">
                                    <div class="tech-info">
                                        <h4><i class="fas fa-user-cog"></i> <?= htmlspecialchars($techName); ?></h4>
                                        <p>Performance distribution by reporting area</p>
                                    </div>
                                    <div class="badge"><?= $techData['total']; ?> Issues</div>
                                </div>

                                <div class="chart-wrap">
                                    <canvas id="chart<?= $i; ?>"></canvas>
                                </div>

                                <div class="area-list">
                                    <?php foreach ($techData['areas'] as $areaName => $areaCount): ?>
                                        <div class="area-item">
                                            <span><?= htmlspecialchars($areaName); ?></span>
                                            <span><?= $areaCount; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php $i++; endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-pie"></i>
                        <h3>No technician issue data found</h3>
                        <p>There are no assigned technician issues available to display at the moment.</p>
                    </div>
                <?php endif; ?>
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

        window.addEventListener("click", function (e) {
            const btn = document.querySelector(".profile-btn");
            const dropdown = document.getElementById("profileDropdown");

            if (!btn.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = "none";
            }
        });

        const chartColors = [
            "#0891b2", "#06b6d4", "#22c55e", "#f59e0b",
            "#ef4444", "#8b5cf6", "#ec4899", "#14b8a6",
            "#3b82f6", "#f97316"
        ];

        <?php $i = 0;
        foreach ($technicians as $techName => $techData): ?>
            new Chart(document.getElementById('chart<?= $i; ?>'), {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_keys($techData['areas'])); ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($techData['areas'])); ?>,
                        backgroundColor: chartColors,
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    cutout: '62%',
                    plugins: {
                        datalabels: {
                            color: '#ffffff',
                            font: {
                                weight: 'bold',
                                size: 12
                            },
                            formatter: function (value, context) {
                                let total = context.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return percentage + '%';
                            }
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 14,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    let value = context.raw;
                                    let percent = ((value / total) * 100).toFixed(1);
                                    return context.label + ': ' + value + ' issues (' + percent + '%)';
                                }
                            }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });
            <?php $i++; endforeach; ?>
    </script>

<script src="/HMS/desktop_notify.php"></script>
</body>

</html>