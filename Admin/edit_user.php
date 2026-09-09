<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch admin info
$admin_id = $_SESSION['user_id'];
$admin_stmt = sqlsrv_query($conn, "SELECT username, profile_image FROM users WHERE id = ?", [$admin_id]);
$admin = ($admin_stmt !== false) ? sqlsrv_fetch_array($admin_stmt, SQLSRV_FETCH_ASSOC) : [];
$username = $admin['username'] ?? 'Admin';
$profileImage = $admin['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists(__DIR__ . "/../uploads/" . $profileImage);

// Unread notification count
$notifQuery = sqlsrv_query($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", [$admin_id]);
$notifRow = ($notifQuery !== false) ? sqlsrv_fetch_array($notifQuery, SQLSRV_FETCH_ASSOC) : [];
$unreadCount = $notifRow['total'] ?? 0;

$success = $error = "";

// Fetch target user info
if (!isset($_GET['id'])) {
    header("Location: manageUsers.php");
    exit();
}
$target_id = intval($_GET['id']);
$target_stmt = sqlsrv_query($conn, "SELECT * FROM users WHERE id = ?", [$target_id]);
$target_user = ($target_stmt !== false) ? sqlsrv_fetch_array($target_stmt, SQLSRV_FETCH_ASSOC) : null;

if (!$target_user) {
    header("Location: manageUsers.php");
    exit();
}

// Update Logic
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST['name']);
    $uname = trim($_POST['username']);
    $phone = trim($_POST['phone']);
    $role = trim($_POST['role']);
    $new_password = trim($_POST['new_password']);

    // Check if username/phone exists for OTHER users
    $check = sqlsrv_query($conn, "SELECT id FROM users WHERE (username = ? OR phone = ?) AND id != ?", [$uname, $phone, $target_id]);
    if ($check !== false && sqlsrv_has_rows($check)) {
        $error = "Username or Phone Number already exists!";
    } else {
        if (!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = sqlsrv_query($conn, "UPDATE users SET name = ?, username = ?, phone = ?, role = ?, password = ? WHERE id = ?", [$name, $uname, $phone, $role, $hashed, $target_id]);
        } else {
            $update = sqlsrv_query($conn, "UPDATE users SET name = ?, username = ?, phone = ?, role = ? WHERE id = ?", [$name, $uname, $phone, $role, $target_id]);
        }

        if ($update === false) {
            error_log("Database Error in edit_user.php: " . print_r(sqlsrv_errors(), true));
            $error = "Update failed due to a system error. Please try again later.";
        } else {
            $success = "User updated successfully!";
            log_system_activity($conn, $_SESSION['user_id'], 'Update User', "Updated user details for '{$uname}' (ID {$target_id})");
            // Refresh data
            $target_user['name'] = $name;
            $target_user['username'] = $uname;
            $target_user['phone'] = $phone;
            $target_user['role'] = $role;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit User | HMS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700" rel="stylesheet">
    <style>
        :root {
            --primary: #224167;
            --primary-hover: #1a3250;
            --bg-gradient: linear-gradient(rgba(248, 250, 252, 0.85), rgba(248, 250, 252, 0.95));
            --radius: 20px;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #f8fafc;
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
            background: var(--bg-gradient), url('../images/dashboard_bg.png');
            background-size: cover;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: #fff;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        header h2 {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1e2a38;
        }

        /* Form Card */
        .page-container {
            padding: 40px;
            flex-grow: 1;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }

        .edit-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-radius: var(--radius);
            padding: 40px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .edit-card h3 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 30px;
            color: #1e2a38;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .edit-card h3 i {
            color: var(--primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .full-width {
            grid-column: span 2;
        }

        .field {
            margin-bottom: 20px;
        }

        .field label {
            display: block;
            font-weight: 700;
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        input,
        select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }

        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(34, 65, 103, 0.1);
        }

        /* Buttons */
        .btn-update {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 14px 25px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            transition: 0.2s;
            box-shadow: 0 8px 15px rgba(34, 65, 103, 0.2);
            margin-top: 10px;
        }

        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px rgba(34, 65, 103, 0.3);
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 25px;
            color: #64748b;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .back-btn:hover {
            color: var(--primary);
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }

        /* User Profile Button */
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
        }

        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
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
                <h2>Edit User Information</h2>
                <div class="header-right">
                    <button class="profile-btn">
                        <?php if ($hasProfileImage): ?>
                            <img src="../uploads/<?= htmlspecialchars($profileImage); ?>" class="profile-pic">
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size: 1.2rem; color: #64748b;"></i>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($username); ?></span>
                    </button>
                </div>
            </header>

            <div class="page-container">
                <div class="edit-card">
                    <h3><i class="fas fa-user-edit"></i> Edit Details</h3>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="form-grid">
                            <div class="field full-width">
                                <label>Full Name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($target_user['name']) ?>"
                                    required>
                            </div>
                            <div class="field">
                                <label>Username</label>
                                <input type="text" name="username"
                                    value="<?= htmlspecialchars($target_user['username']) ?>" required>
                            </div>
                            <div class="field">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($target_user['phone']) ?>"
                                    required>
                            </div>
                            <div class="field">
                                <label>Access Role</label>
                                <select name="role" required>
                                    <option value="admin" <?= $target_user['role'] == 'admin' ? 'selected' : '' ?>>Admin
                                    </option>
                                    <option value="user" <?= $target_user['role'] == 'user' ? 'selected' : '' ?>>User
                                    </option>
                                    <option value="technician" <?= $target_user['role'] == 'technician' ? 'selected' : '' ?>>Technician</option>
                                    <option value="storekeeper" <?= $target_user['role'] == 'storekeeper' ? 'selected' : '' ?>>Storekeeper</option>
                                    <option value="unithead" <?= $target_user['role'] == 'unithead' ? 'selected' : '' ?>>
                                        Unit Head</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Password Reset <span
                                        style="text-transform:none; font-weight:400; color:#94a3b8;">(Optional)</span></label>
                                <input type="password" name="new_password" placeholder="Enter new password">
                            </div>
                        </div>

                        <button type="submit" class="btn-update">
                            <i class="fas fa-save" style="margin-right:8px;"></i> Update User Account
                        </button>
                    </form>

                    <a href="manageUsers.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to User Management
                    </a>
                </div>
            </div>
        </main>
    </div>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>