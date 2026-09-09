<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

//page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

//fetch username
$user_id = $_SESSION['user_id'];
$stmt = sqlsrv_prepare($conn, "SELECT username, profile_image FROM users WHERE id = ? AND role = 'user'", array(&$user_id));
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$user) {
    die("Unauthorized access");
}

$username = $user['username'];
$profileImage = $user['profile_image'] ?? null;
$hasProfileImage = !empty($profileImage) && file_exists("../uploads/" . $profileImage);

// Unread notification count
$notifStmt = sqlsrv_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0", array(&$user_id));
if ($notifStmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($notifStmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$notifRow = sqlsrv_fetch_array($notifStmt, SQLSRV_FETCH_ASSOC);
$unreadCount = $notifRow['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Raise an Issue | Hardware Maintenance System</title>

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
            background: linear-gradient(rgba(248, 250, 252, 0.8), rgba(248, 250, 252, 0.9)), url('../images/dashboard_bg.png');
            background-size: cover;
            background-attachment: fixed;
        }

        /* Header */
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

        /* Containers */
        .page-container {
            padding: 30px 40px;
            flex-grow: 1;
        }

        .section-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 30px 40px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            margin: 0 auto 25px;
            border: 1px solid #f1f5f9;
            max-width: 700px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 25px;
            color: #1e2a38;
            text-align: center;
        }

        /* Forms */
        label {
            display: block;
            margin-top: 20px;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e2a38;
            font-size: 0.95rem;
        }

        form select,
        form textarea,
        form input[type="text"] {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid #cfd8dc;
            font-size: 0.95rem;
            color: #334155;
            transition: border-color 0.2s, box-shadow 0.2s;
            background-color: #fff;
            outline: none;
        }

        form select:focus,
        form textarea:focus,
        form input[type="text"]:focus {
            border-color: #007c91;
            box-shadow: 0 0 0 3px rgba(0, 124, 145, 0.15);
        }

        .upload-box {
            margin-top: 25px;
            padding: 20px;
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            text-align: center;
            background: #f8fafc;
            transition: 0.2s;
        }

        .upload-box:hover {
            border-color: #007c91;
            background: #f1f5f9;
        }

        .upload-box input[type="file"] {
            margin-top: 10px;
        }

        #preview {
            margin-top: 15px;
            max-width: 100%;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .btn-submit {
            width: 100%;
            background: #007c91;
            color: #fff;
            border: none;
            padding: 14px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 1rem;
            margin-top: 30px;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            background: #009db5;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 124, 145, 0.2);
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

        /* Responsiveness */
        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .layout {
                flex-direction: column;
            }

            .page-container {
                padding: 20px;
            }

            .section-card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="logo" style="cursor: pointer;" onclick="window.location.href='userdash.php';">
                <img src="../images/hardware_logo.png" alt="HMS Logo">
                <h1>HMS</h1>
            </div>

            <ul class="menu">
                <li><a href="userdash.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="userissues.php" class="active"><i class="fas fa-plus-circle"></i> Raise Issue</a></li>
                <li><a href="myissues.php"><i class="fas fa-list-check"></i> My Issues</a></li>

                <li class="logout">
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </aside>

        <main class="main-content">
            <header>
                <h2>Raise An Issue</h2>
                <div class="header-right">
                    <a href="userNotifications.php" class="notification-bell">
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
                    <h3 class="section-title">Issue Details</h3>
                    <form method="POST" action="submit_issue.php" enctype="multipart/form-data" id="issueForm">

                        <label>Issue Type</label>
                        <select name="issue_type" id="issue_type" onchange="updateDescriptions()" required>
                            <option value="">Select Issue Type</option>
                            <option value="Hardware">Hardware</option>
                            <option value="Software">Software</option>
                        </select>

                        <label>Issue Description</label>
                        <select name="description" id="description" onchange="handleDescriptionChange()" required>
                            <option value="">Choose an issue</option>
                        </select>

                        <label id="softwareLabel" style="display:none;">Application Name</label>
                        <select name="software_name" id="software_name" style="display:none;"
                            onchange="handleSoftwareChange()">
                            <option value="">Select Application</option>
                        </select>

                        <input type="hidden" name="final_description" id="final_description">

                        <textarea name="other_description" id="other_desc" placeholder="Describe the issue in detail..."
                            style="display:none; margin-top: 15px;"></textarea>

                        <label>Service Tag / Serial Number</label>
                        <input type="text" name="service_tag" placeholder="Enter the Service Tag">

                        <label>Additional Information</label>
                        <textarea name="additional_info" rows="4"
                            placeholder="Any other details that might help us..."></textarea>

                        <div class="upload-box">
                            <label>Upload Error Evidence (Optional)</label>
                            <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 10px;">Screenshot or photo of
                                the error</p>
                            <input type="file" name="error_image" accept="image/*" onchange="previewImage(this)">
                            <img id="preview">
                        </div>

                        <button class="btn-submit" type="submit">Submit Maintenance Request</button>

                    </form>
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
        const hardwareIssues = [
            "No power", "Restart", "Monitor failure", "Keyboard failure", "Mouse failure", "Printer failure", "other"
        ];

        const softwareIssues = [
            "OS booting error", "Virus guard issues", "Application Freezes / Not Responding", "other"
        ];

        const freezeSoftwares = [
            "Office Package", "Browser", "CEB Assist", "SMC", "MITFIN", "ASD", "Payroll", "MIS Report", "SPS", "CEB Info", "AnyDesk", "VNC", "PCDS", "other"
        ];

        function updateDescriptions() {
            const issueType = document.getElementById("issue_type").value;
            const descriptionSelect = document.getElementById("description");
            descriptionSelect.innerHTML = '<option value="">Choose an issue</option>';

            let issues = [];
            if (issueType === "Hardware") issues = hardwareIssues;
            if (issueType === "Software") issues = softwareIssues;

            issues.forEach(issue => {
                const option = document.createElement("option");
                option.value = issue;
                option.textContent = issue === "other" ? "Other" : issue;
                descriptionSelect.appendChild(option);
            });

            resetExtraFields();
        }

        function handleDescriptionChange() {
            const desc = document.getElementById("description").value;
            resetExtraFields();

            if (desc === "other") {
                const otherBox = document.getElementById("other_desc");
                otherBox.style.display = "block";
                otherBox.required = true;
            }

            if (desc === "Application Freezes / Not Responding") {
                const softwareSelect = document.getElementById("software_name");
                const softwareLabel = document.getElementById("softwareLabel");
                softwareSelect.innerHTML = '<option value="">Select Application</option>';
                freezeSoftwares.forEach(software => {
                    const option = document.createElement("option");
                    option.value = software;
                    option.textContent = software === "other" ? "Other" : software;
                    softwareSelect.appendChild(option);
                });
                softwareSelect.style.display = "block";
                softwareLabel.style.display = "block";
                softwareSelect.required = true;
            }
        }

        function resetExtraFields() {
            const otherBox = document.getElementById("other_desc");
            const softwareSelect = document.getElementById("software_name");
            const softwareLabel = document.getElementById("softwareLabel");
            otherBox.style.display = "none";
            otherBox.required = false;
            otherBox.value = "";
            softwareSelect.style.display = "none";
            softwareSelect.required = false;
            softwareSelect.value = "";
            softwareLabel.style.display = "none";
        }

        document.getElementById("issueForm").addEventListener("submit", function (e) {
            const desc = document.getElementById("description").value;
            const software = document.getElementById("software_name").value;
            const otherText = document.getElementById("other_desc").value.trim();
            const finalDescInput = document.getElementById("final_description");

            if (desc === "Application Freezes / Not Responding") {
                if (!software) {
                    e.preventDefault(); alert("Please select an Application Name."); return;
                }
                if (software === "other") {
                    if (!otherText) { e.preventDefault(); alert("Please describe the software issue."); return; }
                    finalDescInput.value = desc + " - " + otherText;
                } else {
                    finalDescInput.value = desc + " - " + software;
                }
            } else if (desc === "other") {
                if (!otherText) { e.preventDefault(); alert("Please describe the issue."); return; }
                finalDescInput.value = otherText;
            } else {
                finalDescInput.value = desc;
            }
        });

        function handleSoftwareChange() {
            const software = document.getElementById("software_name").value;
            const otherBox = document.getElementById("other_desc");
            if (software === "other") {
                otherBox.style.display = "block";
                otherBox.required = true;
            } else {
                otherBox.style.display = "none";
                otherBox.required = false;
                otherBox.value = "";
            }
        }

        function previewImage(input) {
            const preview = document.getElementById('preview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }

        function toggleDropdown() {
            const d = document.getElementById("profileDropdown");
            d.style.display = d.style.display === "block" ? "none" : "block";
        }

        window.onclick = function (event) {
            if (!event.target.closest('.profile-btn')) {
                const dropdown = document.getElementById("profileDropdown");
                if (dropdown && dropdown.style.display === "block") {
                    dropdown.style.display = "none";
                }
            }
        }
    </script>
<script src="/HMS/desktop_notify.php"></script>
</body>

</html>