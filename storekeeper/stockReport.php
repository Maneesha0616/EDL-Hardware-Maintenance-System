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
$sql = "SELECT username, profile_image FROM users WHERE id=?";
$params = array($user_id);
$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

$username = $user['username'] ?? "Storekeeper";
$profileImage = $user['profile_image'] ?? "";
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Summary cards
$totalItems = 0;
$totalQty = 0;
$totalValue = 0;
$lowStockCount = 0;

$q1 = sqlsrv_query($conn, "SELECT COUNT(*) AS total_items FROM items");
if ($q1 === false) {
    die(print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($q1, SQLSRV_FETCH_ASSOC);
$totalItems = $row['total_items'] ?? 0;
sqlsrv_free_stmt($q1);

$q2 = sqlsrv_query($conn, "
    SELECT 
        COALESCE(SUM(quantity), 0) AS total_qty,
        COALESCE(SUM(quantity * unit_price), 0) AS total_value,
        COALESCE(SUM(CASE WHEN quantity <= 5 THEN 1 ELSE 0 END), 0) AS low_stock_count
    FROM items
");
if ($q2 === false) {
    die(print_r(sqlsrv_errors(), true));
}
$row = sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC);
$totalQty = $row['total_qty'] ?? 0;
$totalValue = $row['total_value'] ?? 0;
$lowStockCount = $row['low_stock_count'] ?? 0;
sqlsrv_free_stmt($q2);

// Full stock list
$stocks = sqlsrv_query($conn, "
    SELECT *
    FROM items
    ORDER BY item_name ASC, capacity_type ASC, brand ASC
");
if ($stocks === false) {
    die(print_r(sqlsrv_errors(), true));
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Stock Report | Hardware Maintenance System</title>
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

        .page-container {
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            border-left: 5px solid var(--primary);
        }

        .stat-card h3 {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--secondary);
        }

        .section-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
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

        .section-title i {
            color: var(--primary);
        }

        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .search-bar {
            position: relative;
            flex: 1;
            min-width: 300px;
        }

        .search-bar i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 16px 12px 45px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.95rem;
        }

        .btn-group {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            font-size: 0.85rem;
            text-decoration: none;
            background: var(--primary);
            color: white;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 124, 145, 0.2);
        }

        /* Table Styles */
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

        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .badge-low {
            background: #fff5f5;
            color: #e53e3e;
            border: 1px solid #feb2b2;
            animation: alertPulse 2s infinite;
        }

        .badge-ok {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #dcfce7;
        }

        @keyframes alertPulse {
            0% {
                box-shadow: 0 0 0 0 rgba(229, 62, 62, 0.2);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(229, 62, 62, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(229, 62, 62, 0);
            }
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

        @media print {

            .sidebar,
            header,
            .top-actions,
            .footer {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                border: none !important;
                background: #fff !important;
            }

            .page-container {
                padding: 0;
            }

            .section-card {
                box-shadow: none !important;
                border: 1px solid #eee !important;
            }
        }
    </style>
</head>

<body>

    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='storekeeperdash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="storekeeperdash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="issueHardware.php"><i class="fas fa-share-square"></i> Issue Hardware</a></li>
                <li><a href="manageInventory.php"><i class="fas fa-boxes"></i> Manage Inventory</a></li>
                <li><a href="stockReport.php" class="active"><i class="fas fa-file-invoice-dollar"></i> Stock
                        Reports</a></li>

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
                <h2>Stock Inventory Report</h2>
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
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Item Varieties</h3>
                        <div class="value"><?= $totalItems ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Total Units</h3>
                        <div class="value"><?= number_format($totalQty) ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Asset Value</h3>
                        <div class="value">Rs. <?= number_format($totalValue, 2) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #ef4444;">
                        <h3>Low Stock Alerts</h3>
                        <div class="value" style="color: #ef4444;"><?= $lowStockCount ?></div>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-title">
                        <i class="fas fa-boxes"></i>
                        Inventory Breakdown
                    </div>

                    <div class="top-actions">
                        <div class="search-bar">
                            <i class="fas fa-search"></i>
                            <input type="text" id="stockSearch" placeholder="Search by name, specs, or brand...">
                        </div>
                        <div class="btn-group">

                            <a href="exportStockExcel.php" class="btn btn-excel" id="excelExportBtn"><i class="fas fa-file-excel"></i>
                                Excel</a>
                            <a href="exportStockPDF.php" class="btn btn-pdf" id="pdfExportBtn"><i class="fas fa-file-pdf"></i> PDF</a>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table id="stockTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item Name</th>
                                    <th>Capacity/Type</th>
                                    <th>Brand</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total Value</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $i = 1;
                                if ($stocks && sqlsrv_has_rows($stocks)):
                                    while ($row = sqlsrv_fetch_array($stocks, SQLSRV_FETCH_ASSOC)):
                                        $qty = (int) $row['quantity'];
                                        $price = (float) $row['unit_price'];
                                        $total = $qty * $price;
                                        $isLow = $qty <= 5;

                                        $createdAtStr = '—';
                                        if (!empty($row['created_at'])) {
                                            if ($row['created_at'] instanceof DateTime) {
                                                $createdAtStr = $row['created_at']->format('Y-m-d h:i A');
                                            } else {
                                                $createdAtStr = date("Y-m-d h:i A", strtotime($row['created_at']));
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td style="font-weight:700;color:#64748b;"><?= $i++ ?></td>
                                            <td style="font-weight:600;"><?= htmlspecialchars($row['item_name']) ?></td>
                                            <td style="font-size:0.85rem;"><?= htmlspecialchars($row['capacity_type'] ?: '—') ?>
                                            </td>
                                            <td style="font-size:0.85rem;"><?= htmlspecialchars($row['brand'] ?: '—') ?></td>
                                            <td style="font-weight:800; <?= $isLow ? 'color:#ef4444;' : '' ?>"><?= $qty ?></td>
                                            <td style="font-weight:600;">Rs. <?= number_format($price, 2) ?></td>
                                            <td style="font-weight:700;">Rs. <?= number_format($total, 2) ?></td>
                                            <td>
                                                <span class="badge <?= $isLow ? 'badge-low' : 'badge-ok' ?>">
                                                    <?= $isLow ? 'Low Stock' : 'Available' ?>
                                                </span>
                                            </td>
                                            <td style="font-size:0.85rem;color:#64748b;">
                                                <?= htmlspecialchars($createdAtStr) ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; else: ?>
                                    <tr>
                                        <td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">No inventory
                                            items found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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

        document.getElementById('stockSearch').addEventListener('keyup', function () {
            const filter = this.value.toLowerCase();
            const rawFilter = this.value;
            const rows = document.querySelectorAll('#stockTable tbody tr');
            rows.forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
            });

            // Update export links
            const pdfBtn = document.getElementById('pdfExportBtn');
            const excelBtn = document.getElementById('excelExportBtn');
            const searchParam = rawFilter ? '?search=' + encodeURIComponent(rawFilter) : '';

            pdfBtn.href = 'exportStockPDF.php' + searchParam;
            excelBtn.href = 'exportStockExcel.php' + searchParam;
        });
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>