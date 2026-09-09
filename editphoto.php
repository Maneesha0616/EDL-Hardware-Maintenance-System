<?php
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch current profile image using sqlsrv
$sql = "SELECT profile_image FROM users WHERE id = ?";
$params = array($user_id);
$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$hasProfileImage = !empty($user['profile_image']) && file_exists("uploads/" . $user['profile_image']);

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_image'])) {
    $file = $_FILES['profile_image'];

    if ($file['error'] != 0) {
        $error = "Upload error.";
    } elseif ($file['size'] > 1024 * 1024) {
        $error = "File size must be less than 1MB.";
    } else {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $error = "Invalid file type.";
        } else {
            $newName = "user_" . $user_id . "_" . time() . "." . $ext;
            $path = "uploads/" . $newName;

            if (!move_uploaded_file($file['tmp_name'], $path)) {
                $error = "Failed to upload file.";
            } else {
                // Delete old image
                if (!empty($user['profile_image']) && file_exists("uploads/" . $user['profile_image'])) {
                    unlink("uploads/" . $user['profile_image']);
                }

                // Update DB with sqlsrv
                $updateSql = "UPDATE users SET profile_image = ? WHERE id = ?";
                $updateParams = array($newName, $user_id);
                $updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);
                if ($updateStmt === false) {
                    $error = "Database update failed.";
                } else {
                    sqlsrv_free_stmt($updateStmt);
                    $success = true;
                    $hasProfileImage = true;
                    $user['profile_image'] = $newName;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Profile Photo</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root {
      --bg-gradient: linear-gradient(rgba(248, 250, 252, 0.85), rgba(248, 250, 252, 0.95));
      --card: rgba(255, 255, 255, 0.88);
      --text: #1e293b;
      --muted: #64748b;
      --line: #e2e8f0;
      --primary: #7dd3fc;
      --primary-hover: #38bdf8;
      --radius: 24px;
      --shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', 'Segoe UI', Arial, sans-serif; }
    body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 30px 20px; background: var(--bg-gradient), url('images/dashboard_bg.png'); background-size: cover; background-attachment: fixed; color: var(--text); }
    .container { width: 100%; max-width: 480px; background: var(--card); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.6); padding: 50px 40px; text-align: center; border-radius: var(--radius); box-shadow: var(--shadow); }
    .container h2 { font-size: 1.6rem; font-weight: 800; color: #1e2a38; margin-bottom: 30px; }
    .container h2 i { color: #2563eb; margin-right: 12px; }
    .img-wrap { position: relative; width: 180px; height: 180px; margin: 0 auto 30px; }
    .container img, .default-avatar { width: 100%; height: 100%; border-radius: 50%; border: 5px solid #fff; box-shadow: 0 15px 30px rgba(0,0,0,0.1); object-fit: cover; }
    .default-avatar { background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%); display: flex; align-items: center; justify-content: center; }
    .default-avatar i { font-size: 90px; color: #94a3b8; }
    .upload-form { margin-top: 10px; }
    .file-input-label { display: block; margin-bottom: 20px; }
    .container input[type="file"] { width: 100%; padding: 15px; border: 2px dashed #cfd8dc; background: rgba(255,255,255,0.5); border-radius: 12px; color: var(--muted); cursor: pointer; transition: all 0.2s; font-weight: 600; font-size: 0.9rem; }
    .container input[type="file"]:hover { background: #fff; border-color: #2563eb; }
    .container button { width: 100%; padding: 14px; background: linear-gradient(135deg, #162a43, #224167); color: #fff; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; font-size: 1rem; transition: all 0.2s; box-shadow: 0 8px 20px rgba(34,65,103,0.2); display: flex; align-items: center; justify-content: center; gap: 10px; }
    .container button:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(34,65,103,0.3); }
    .back-area { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
    .back-btn { display: inline-flex; align-items: center; gap: 10px; color: var(--muted); text-decoration: none; font-weight: 700; font-size: 0.9rem; transition: all 0.2s; }
    .back-btn:hover { color: #2563eb; }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <div class="container">
    <h2><i class="fas fa-camera"></i> Edit Profile Photo</h2>
    <div class="img-wrap">
      <?php if ($hasProfileImage): ?>
        <img src="uploads/<?= htmlspecialchars($user['profile_image']); ?>" alt="Profile Photo">
      <?php else: ?>
        <div class="default-avatar"><i class="fa-solid fa-circle-user"></i></div>
      <?php endif; ?>
    </div>
    <form action="" method="POST" enctype="multipart/form-data" class="upload-form">
      <div class="file-input-label"><input type="file" name="profile_image" accept="image/*" required></div>
      <button type="submit"><i class="fas fa-upload"></i> Upload New Photo</button>
    </form>
    <div class="back-area"><a href="userprofile.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Profile</a></div>
  </div>
  <?php if ($success): ?>
    <script>Swal.fire({icon:'success',title:'Updated!',text:'Profile photo updated successfully!',timer:2000,showConfirmButton:false});</script>
  <?php elseif (!empty($error)): ?>
    <script>Swal.fire({icon:'error',title:'Error!',text:'<?= $error; ?>'});</script>
  <?php endif; ?>
  <script src="/HMS/desktop_notify.php"></script>
</body>
</html>