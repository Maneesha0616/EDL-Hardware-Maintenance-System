<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check valid user
$stmt = sqlsrv_prepare($conn, "SELECT id, username FROM users WHERE id = ? AND role = 'user'", array(&$user_id));
if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Execution failed: " . print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$user) {
    die("Unauthorized submission");
}

$user_name = $user['username'];

$issue_type        = trim($_POST['issue_type'] ?? '');
$description       = trim($_POST['description'] ?? '');
$final_description = trim($_POST['final_description'] ?? '');
$other_description = trim($_POST['other_description'] ?? '');
$software_name     = trim($_POST['software_name'] ?? '');
$service_tag       = trim($_POST['service_tag'] ?? '');
$additional_info   = trim($_POST['additional_info'] ?? '');

date_default_timezone_set("Asia/Colombo");
$received_date = date("Y-m-d H:i:s");
$status = "Pending";

// Build issue category and final description
$issue_category = $description;

$issue_description = $final_description !== ''
    ? $final_description
    : (($description === 'other') ? $other_description : $description);

// Validation
if ($issue_type === '' || $issue_category === '' || $issue_description === '' || $service_tag === '') {
    die("Required fields missing. Please fill all required fields.");
}

// Validate issue type
$allowed_issue_types = ['Hardware', 'Software'];
if (!in_array($issue_type, $allowed_issue_types, true)) {
    die("Invalid issue type selected.");
}

// Validate service tag
// if (!preg_match('/^[A-Za-z0-9\-\/]+$/', $service_tag)) {
//     die("Invalid service tag format.");
// }

// Validate software name only for application freeze issue
if ($issue_category !== 'Application Freezes / Not Responding') {
    $software_name = null;
} else {
    if ($software_name === '') {
        die("Please select the software name.");
    }
}

// Image upload
$image_name = null;

if (!empty($_FILES['error_image']['name'])) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    $ext = strtolower(pathinfo($_FILES['error_image']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        die("Invalid image type.");
    }

    if ($_FILES['error_image']['size'] > 10 * 1024 * 1024) {
        die("Image too large.");
    }

    $dir = "../uploads/issues/";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $image_name = uniqid("issue_", true) . "." . $ext;

    if (!move_uploaded_file($_FILES['error_image']['tmp_name'], $dir . $image_name)) {
        die("Image upload failed.");
    }
}

// Insert issue
$sql = "INSERT INTO issues (
            user_id,
            issue_type,
            issue_category,
            software_name,
            issue_description,
            service_tag,
            image,
            additional_information,
            status,
            received_date
        ) OUTPUT INSERTED.issue_id VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$params = array(&$user_id, &$issue_type, &$issue_category, &$software_name, &$issue_description, &$service_tag, &$image_name, &$additional_info, &$status, &$received_date);
$stmt = sqlsrv_prepare($conn, $sql, $params);

if ($stmt === false) {
    die("Prepare failed: " . print_r(sqlsrv_errors(), true));
}

if (!sqlsrv_execute($stmt)) {
    die("Insert failed: " . print_r(sqlsrv_errors(), true));
}

// Get inserted issue id
$idRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$issue_id = $idRow['issue_id'] ?? null;
if (!$issue_id) {
    die("Failed to retrieve issue ID.");
}

// Send notification to all admins
$title = "New Issue Reported";
$message = "A new issue has been submitted by {$user_name}. "
         . "The issue has been assigned ID {$issue_id} and is categorized as {$issue_category} under {$issue_type}. "
         . "The service tag associated with this issue is {$service_tag}, and the current status is Pending.";


$admins = sqlsrv_query($conn, "SELECT id FROM users WHERE role = 'admin'");

if ($admins) {
    while ($admin = sqlsrv_fetch_array($admins, SQLSRV_FETCH_ASSOC)) {
        $admin_id = $admin['id'];
        $notifSql = "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())";
        $notifParams = array(&$admin_id, &$title, &$message);
        $notifStmt = sqlsrv_prepare($conn, $notifSql, $notifParams);
        if ($notifStmt !== false) {
            sqlsrv_execute($notifStmt);
        }
    }
}

header("Location: myissues.php?success=1");
exit();
?>