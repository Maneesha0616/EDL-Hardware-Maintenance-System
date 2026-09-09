<?php
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$current = $_POST['current_password'];
$new     = $_POST['new_password'];
$confirm = $_POST['confirm_password'];

if ($new !== $confirm) {
    echo json_encode(["status" => "error", "message" => "Passwords do not match"]);
    exit;
}

/*GET CURRENT PASSWORD*/
$sql = "SELECT password FROM users WHERE id = ?";
$params = array($user_id);

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$user) {
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit;
}

/* Plain comparison */
if ($current !== $user['password']) {
    echo json_encode(["status" => "error", "message" => "Current password incorrect"]);
    exit;
}

/*UPDATE PASSWORD*/
$update = "UPDATE users SET password = ? WHERE id = ?";
$paramsUpdate = array($new, $user_id);

$stmtUpdate = sqlsrv_query($conn, $update, $paramsUpdate);

if ($stmtUpdate) {
    echo json_encode(["status" => "success", "message" => "Password updated successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Update failed"]);
}
?>