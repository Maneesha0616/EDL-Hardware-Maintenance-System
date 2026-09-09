<?php
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

//Redirect if not logged in 
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// Fetch user data using sqlsrv
$sql = "SELECT name, phone, username, role, profile_image FROM users WHERE id = ?";
$stmt = sqlsrv_prepare($conn, $sql, array(&$user_id));
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

// Check if profile image exists
$hasProfileImage = !empty($user['profile_image']) && file_exists(__DIR__ . "/uploads/" . $user['profile_image']);

// Determine dashboard URL based on role
$role = $user['role'] ?? ($_SESSION['role'] ?? 'user');
switch ($role) {
    case 'admin':
        $dashboardUrl = 'Admin/Admindash.php';
        break;
    case 'technician':
        $dashboardUrl = 'technician/techniciandash.php';
        break;
    case 'unithead':
        $dashboardUrl = 'unithead/unitheaddash.php';
        break;
    case 'storekeeper':
        $dashboardUrl = 'storekeeper/storekeeperdash.php';
        break;
    default:
        $dashboardUrl = 'user/userdash.php';
        break;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>User Profile</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --bg-gradient: linear-gradient(rgba(248, 250, 252, 0.85), rgba(248, 250, 252, 0.92));
            --card: rgba(255, 255, 255, 0.85);
            --text: #1e2a38;
            --muted: #64748b;
            --line: #e2e8f0;
            --primary: #224167;
            /* Specific Navy Blue */
            --primary-hover: #1a3250;
            --danger: #e11d48;
            /* Keeping Rose for Delete */
            --danger-hover: #be123c;
            --secondary: #334155;
            /* Slate for secondary */
            --secondary-hover: #475569;
            --radius: 20px;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        }

        body {
            min-height: 100vh;
            background: var(--bg-gradient), url('images/dashboard_bg.png');
            background-size: cover;
            background-attachment: fixed;
            color: var(--text);
            padding: 20px;
        }

        /* Glassmorphism container */
        .adminProfile {
            display: flex;
            gap: 30px;
            max-width: 1100px;
            margin: 20px auto 40px;
            padding: 0 15px;
        }

        /* Back button premium */
        .back-btn-container {
            max-width: 1100px;
            margin: 20px auto 0;
            padding: 0 15px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            border-radius: 12px;
            background: white;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border: 1px solid var(--line);
        }

        .back-btn i {
            color: #2563eb;
        }

        .back-btn:hover {
            transform: translateX(-5px);
            box-shadow: var(--shadow-lg);
            background: #f8fafc;
        }

        /* Left profile card */
        .profile {
            flex: 0 0 320px;
            background: var(--card);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: var(--radius);
            padding: 40px 25px;
            text-align: center;
            box-shadow: var(--shadow-lg);
            height: fit-content;
            position: sticky;
            top: 40px;
            transition: transform 0.3s ease;
        }

        .profile:hover {
            transform: translateY(-5px);
        }

        /* Avatar section */
        .profile img,
        .default-avatar {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 20px;
            border: 4px solid #fff;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
        }

        .default-avatar {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .default-avatar i {
            font-size: 80px;
            color: #ffffff;
        }

        .profile h2 {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1e2a38;
            margin-bottom: 5px;
        }

        .profile p {
            color: var(--muted);
            font-weight: 600;
            margin-bottom: 25px;
        }

        /* Profile action buttons */
        .profile-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn-primary,
        .btn-danger {
            border: none;
            padding: 12px 15px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 8px 16px rgba(0, 124, 145, 0.15);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 124, 145, 0.25);
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
            box-shadow: 0 8px 16px rgba(220, 53, 69, 0.15);
        }

        .btn-danger:hover {
            background: var(--danger-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(220, 53, 69, 0.25);
        }

        /* Right Content Area */
        .profileContent {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .section-card {
            background: var(--card);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
        }

        .section-card h2 {
            font-size: 1.25rem;
            font-weight: 800;
            color: #1e2a38;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-card h2 i {
            color: #2563eb;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .info-item {
            background: white;
            padding: 15px 20px;
            border-radius: 15px;
            border: 1px solid #f1f5f9;
            box-shadow: var(--shadow-sm);
        }

        .info-item label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-item span {
            font-weight: 700;
            color: #1e2a38;
            font-size: 1rem;
        }

        /* Edit button in section (Dark Blue) */
        .edit-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--secondary);
            color: #fff;
            padding: 10px 25px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2);
        }

        .edit-btn:hover {
            background: var(--secondary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.3);
        }

        /* Password Form */
        .grid-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .input-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .input-field label {
            font-weight: 700;
            font-size: 0.9rem;
            color: #1e2a38;
        }

        .input-field input {
            padding: 12px 18px;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
            background: #fff;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }

        .input-field input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .update-btn {
            background: linear-gradient(135deg, #162a43, #224167);
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
            align-self: flex-start;
            box-shadow: 0 8px 20px rgba(34, 65, 103, 0.2);
        }

        .update-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(34, 65, 103, 0.3);
        }

        /* Responsive */
        @media (max-width: 980px) {
            .adminProfile {
                flex-direction: column;
            }

            .profile {
                flex: none;
                width: 100%;
                position: relative;
                top: 0;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <!--Back to dashboard-->
    <div class="back-btn-container">
        <a href="<?= $dashboardUrl ?>" class="back-btn">
            <i class="fa fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <div class="adminProfile">
        <div class="profile">
            <?php if ($hasProfileImage): ?>
                <img id="profilePreview" src="uploads/<?= htmlspecialchars($user['profile_image']); ?>">
            <?php else: ?>
                <div class="default-avatar" id="profilePreview">
                    <i class="fa-solid fa-user"></i>
                </div>
            <?php endif; ?>

            <h2><?= htmlspecialchars($user['name']); ?></h2>
            <p>@<?= htmlspecialchars($user['username']); ?></p>

            <div class="profile-actions">
                <a href="editphoto.php" class="btn-primary">
                    <i class="fa fa-camera"></i> Edit Photo
                </a>

                <?php if ($hasProfileImage): ?>
                    <form action="deletephoto.php" method="POST" onsubmit="return confirm('Delete profile photo?')">
                        <button type="submit" class="btn-danger" style="width: 100%;">
                            <i class="fa fa-trash"></i> Delete Photo
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="profileContent">
            <div class="section-card">
                <h2><i class="fas fa-id-card"></i> Account Information</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <label>Full Name</label>
                        <span><?= htmlspecialchars($user['name']); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Phone Number</label>
                        <span><?= htmlspecialchars($user['phone']); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Username</label>
                        <span><?= htmlspecialchars($user['username']); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Access Role</label>
                        <span><?= ucfirst(htmlspecialchars($user['role'])); ?></span>
                    </div>
                </div>
                <a href="editprofile.php" class="edit-btn">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
            </div>

            <div class="section-card">
                <h2><i class="fas fa-key"></i> Security</h2>
                <form id="changePasswordForm" class="grid-form" method="POST" action="changepassword.php">
                    <div class="input-field">
                        <label>Current Password</label>
                        <input type="password" name="current_password" placeholder="Enter current password" required>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="input-field">
                            <label>New Password</label>
                            <input type="password" name="new_password" placeholder="New password" required>
                        </div>
                        <div class="input-field">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" placeholder="Confirm password" required>
                        </div>
                    </div>
                    <button type="submit" class="update-btn">
                        <i class="fas fa-shield-alt"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('changePasswordForm');
        form.addEventListener('submit', e => {
            e.preventDefault();

            const formData = new FormData(form);

            fetch('changepassword.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        form.reset();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => alert('Something went wrong!'));
        });
    </script>

    <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
        <script>
            alert("Profile updated successfully!");
        </script>
    <?php endif; ?>

    <script src="/HMS/desktop_notify.php"></script>
</body>

</html>