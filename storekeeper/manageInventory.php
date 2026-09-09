<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";
require_once __DIR__ . '/../Admin/AdminNotifyHelper.php';

//Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'storekeeper') {
    header("Location: ../login.php");
    exit();
}

//fetch user
$user_id = $_SESSION['user_id'];
$sql = "SELECT username, profile_image FROM users WHERE id=?";
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
if (isset($_GET['added']))
    $success = "Item added successfully!";
if (isset($_GET['stock']))
    $success = "Stock quantity increased successfully!";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = trim($_POST['item_name']);
    $capacity_type = trim($_POST['capacity_type']);
    $brand = trim($_POST['brand']);
    $quantity = intval($_POST['quantity']);
    $unit_price = floatval($_POST['unit_price']);

    $capacity_type = ($capacity_type === '') ? null : $capacity_type;
    $brand = ($brand === '') ? null : $brand;

    if ($item_name && $quantity > 0 && $unit_price >= 0) {
        $check_sql = "SELECT item_id, quantity FROM items 
              WHERE item_name=? 
              AND ((capacity_type = ?) OR (capacity_type IS NULL AND ? IS NULL))
              AND ((brand = ?) OR (brand IS NULL AND ? IS NULL))";
        $check_params = array($item_name, $capacity_type, $capacity_type, $brand, $brand);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        if ($check_stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }

        if (sqlsrv_has_rows($check_stmt)) {
            $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($check_stmt);
            $new_quantity = $existing['quantity'] + $quantity;
            $update_sql = "UPDATE items SET quantity=?, unit_price=? WHERE item_id=?";
            $update_params = array($new_quantity, $unit_price, $existing['item_id']);
            $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
            if ($update_stmt === false) {
                die(print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($update_stmt);
            header("Location: manageInventory.php?stock=added");
            exit();
        } else {
            sqlsrv_free_stmt($check_stmt);
            $insert_sql = "INSERT INTO items (item_name, capacity_type, brand, quantity, unit_price) VALUES (?, ?, ?, ?, ?)";
            $insert_params = array($item_name, $capacity_type, $brand, $quantity, $unit_price);
            $insert_stmt = sqlsrv_query($conn, $insert_sql, $insert_params);
            if ($insert_stmt === false) {
                die(print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($insert_stmt);
            header("Location: manageInventory.php?added=1");
            exit();
        }
    } else {
        $error = "Please fill all fields correctly.";
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Inventory | Hardware Maintenance System</title>
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

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #64748b;
            margin-left: 4px;
        }

        .field input,
        .field select {
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .field input:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(0, 124, 145, 0.1);
            outline: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 124, 145, 0.2);
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
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

        tbody tr:hover {
            background: #f8fafc;
        }

        .actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            transition: 0.2s;
        }



        /* Filter Bar */
        .filter-bar {
            margin-bottom: 20px;
            position: relative;
        }

        .filter-bar i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .filter-bar input {
            width: 100%;
            padding: 12px 16px 12px 45px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 0.95rem;
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
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='storekeeperdash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="storekeeperdash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="issueHardware.php"><i class="fas fa-share-square"></i> Issue Hardware</a></li>
                <li><a href="manageInventory.php" class="active"><i class="fas fa-boxes"></i> Manage Inventory</a></li>
                <li><a href="stockReport.php"><i class="fas fa-file-invoice-dollar"></i> Stock Reports</a></li>

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
                <h2>Manage Inventory</h2>
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
                        <i class="fas fa-plus-circle"></i> Add New Inventory Item
                    </div>

                    <?php if ($success): ?>
                        <div class="alert alert-success" id="successMsg">
                            <i class="fas fa-check-circle"></i> <?= $success ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="form-grid">
                            <div class="field">
                                <label>Item Name</label>
                                <input type="text" name="item_name" required>
                            </div>
                            <div class="field">
                                <label>Capacity / Type</label>
                                <input type="text" name="capacity_type">
                            </div>
                            <div class="field">
                                <label>Brand</label>
                                <input type="text" name="brand">
                            </div>
                            <div class="field">
                                <label>Quantity</label>
                                <input type="number" name="quantity" min="1" required>
                            </div>
                            <div class="field">
                                <label>Unit Price (Rs.)</label>
                                <input type="number" name="unit_price" step="0.01" min="0" required>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Add Item
                        </button>
                    </form>
                </div>

                <div class="section-card">
                    <div class="section-title" style="display:flex; justify-content:space-between; align-items:center;">
                        <span><i class="fas fa-list-ul"></i> Inventory Stock List</span>
                        <div class="action-buttons" style="display:flex; gap:10px;">
                            <a href="../Admin/exportStorePDF.php" target="_blank" class="btn-export pdf" id="pdfExportBtn"
                                style="background:var(--primary); color:white; border:none; padding:8px 18px; border-radius:10px; cursor:pointer; font-weight:700; font-size:0.85rem; display:flex; align-items:center; gap:8px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0, 124, 145, 0.2); text-decoration:none;">
                                <i class="fas fa-file-pdf" style="color: white !important;"></i> Download PDF
                            </a>
                            <a href="../Admin/exportStoreExcel.php" target="_blank" class="btn-export excel" id="excelExportBtn"
                                style="background:var(--primary); color:white; border:none; padding:8px 18px; border-radius:10px; cursor:pointer; font-weight:700; font-size:0.85rem; display:flex; align-items:center; gap:8px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(30, 42, 56, 0.2); text-decoration:none;">
                                <i class="fas fa-file-excel" style="color: white !important;"></i> Download Excel
                            </a>
                        </div>
                    </div>

                    <div class="filter-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" id="itemSearch" placeholder="Search inventory by name, brand, or type...">
                    </div>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Capacity/Type</th>
                                    <th>Brand</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                </tr>
                            </thead>
                            <tbody id="inventoryTable">
                                <?php
                                $i = 1;
                                $result = sqlsrv_query($conn, "SELECT * FROM items ORDER BY item_id DESC");
                                if ($result === false) {
                                    die(print_r(sqlsrv_errors(), true));
                                }
                                while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)):
                                    $qty = (int) $row['quantity'];
                                    $price = (float) $row['unit_price'];
                                    $isLow = $qty <= 5;
                                    ?>
                                    <tr>
                                        <td style="font-weight:600;"><?= htmlspecialchars($row['item_name']); ?></td>
                                        <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '<span style="color:#cbd5e1;">—</span>'; ?>
                                        </td>
                                        <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '<span style="color:#cbd5e1;">—</span>'; ?>
                                        </td>
                                        <td>
                                            <span
                                                style="font-weight:800; <?= $isLow ? 'color:#ef4444;' : 'color:#059669;' ?>">
                                                <?= $qty; ?>
                                                <?= $isLow ? '<i class="fas fa-exclamation-triangle" style="margin-left:5px;font-size:0.8rem;"></i>' : '' ?>
                                            </span>
                                        </td>
                                        <td style="font-weight:600;">Rs. <?= number_format($price, 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
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

        document.getElementById('itemSearch').addEventListener('keyup', function () {
            const filter = this.value.toLowerCase();
            const rawFilter = this.value;
            const rows = document.querySelectorAll('#inventoryTable tr');
            rows.forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
            });

            // Update export links
            const pdfBtn = document.getElementById('pdfExportBtn');
            const excelBtn = document.getElementById('excelExportBtn');
            const searchParam = rawFilter ? '?search=' + encodeURIComponent(rawFilter) : '';

            pdfBtn.href = '../Admin/exportStorePDF.php' + searchParam;
            excelBtn.href = '../Admin/exportStoreExcel.php' + searchParam;
        });

        const successMsg = document.getElementById("successMsg");
        if (successMsg) {
            setTimeout(() => {
                successMsg.style.opacity = "0";
                successMsg.style.transition = "0.5s";
                setTimeout(() => successMsg.remove(), 500);
            }, 3000);
        }
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>