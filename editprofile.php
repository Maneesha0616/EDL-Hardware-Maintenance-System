<?php
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

// page protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error = "";
$success = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);

    // Validate input
    if (empty($name) || empty($phone)) {
        $error = "All fields are required!";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = "Invalid phone number! It must be exactly 10 digits.";
    } else {
        $updateSql = "UPDATE users SET name = ?, phone = ? WHERE id = ?";
        $params = array($name, $phone, $user_id);
        $stmt = sqlsrv_prepare($conn, $updateSql, $params);
        if ($stmt === false) {
            $error = "Prepare failed: " . print_r(sqlsrv_errors(), true);
        } else {
            if (sqlsrv_execute($stmt)) {
                $success = "Profile updated successfully!";
            } else {
                $error = "Failed to update profile. Try again.";
            }
        }
    }
}

// Fetch current user data
$selectSql = "SELECT name, phone FROM users WHERE id = ?";
$stmt = sqlsrv_prepare($conn, $selectSql, array(&$user_id));
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (sqlsrv_execute($stmt)) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $user = $row;
} else {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Profile</title>
  <style>
    :root {
      --bg-gradient: linear-gradient(rgba(248, 250, 252, 0.85), rgba(248, 250, 252, 0.95));
      --card: rgba(255, 255, 255, 0.88);
      --text: #1e293b;
      --muted: #64748b;
      --line: #e2e8f0;
      --btn: #bae6fd;
      --btnHover: #7dd3fc;
      --btnText: #075985;
      --success: #16a34a;
      --danger: #ef4444;
      --radius: 24px;
      --shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
    }
    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 30px 20px;
      background: var(--bg-gradient), url('images/dashboard_bg.png');
      background-size: cover;
      background-attachment: fixed;
      color: var(--text);
    }
    .edit-box {
      width: 100%;
      max-width: 480px;
      background: var(--card);
      backdrop-filter: blur(15px);
      -webkit-backdrop-filter: blur(15px);
      border: 1px solid rgba(255, 255, 255, 0.6);
      padding: 45px 40px;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }
    .edit-box h2 {
      text-align: center;
      font-size: 1.6rem;
      font-weight: 800;
      color: #1e2a38;
      margin-bottom: 30px;
    }
    .edit-box h2 i { color: #2563eb; margin-right: 12px; }
    .message, .error {
      text-align: center;
      margin: -10px 0 25px;
      padding: 12px;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.9rem;
    }
    .message { background: rgba(22, 163, 74, 0.1); color: var(--success); border: 1px solid rgba(22, 163, 74, 0.2); }
    .error { background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); }
    .form-group { margin-bottom: 20px; }
    .edit-box label { display: block; margin-bottom: 8px; font-weight: 700; color: #1e2a38; font-size: 0.9rem; }
    .edit-box input { width: 100%; padding: 12px 18px; border-radius: 12px; border: 1px solid #cfd8dc; background: #fff; font-size: 0.95rem; outline: none; transition: all 0.2s; color: #334155; }
    .edit-box input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }
    .edit-box button { width: 100%; margin-top: 15px; padding: 14px; background: linear-gradient(135deg, #162a43, #224167); color: #fff; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; font-size: 1rem; transition: all 0.2s; box-shadow: 0 8px 20px rgba(34, 65, 103, 0.2); }
    .edit-box button:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(34, 65, 103, 0.3); }
    .back-area { text-align: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
    .back-btn { display: inline-flex; align-items: center; gap: 10px; color: var(--muted); text-decoration: none; font-weight: 700; font-size: 0.9rem; transition: all 0.2s; }
    .back-btn:hover { color: #2563eb; }
  </style>
</head>
<body>
  <div class="edit-box">
    <h2><i class="fas fa-user-edit"></i> Edit Profile</h2>
    <?php if ($success): ?>
      <div class="message"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" placeholder="Enter your full name" required>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="e.g. 0712345678" required>
      </div>
      <button type="submit"><i class="fas fa-save"></i> Save Changes</button>
    </form>
    <div class="back-area">
      <a href="userprofile.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Profile</a>
    </div>
  </div>
  <script src="/HMS/desktop_notify.php"></script>
</body>
</html>