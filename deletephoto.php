<?php
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) exit;

$user_id = $_SESSION['user_id'];

// Fetch current profile image using sqlsrv
$sql = "SELECT profile_image FROM users WHERE id = ?";
$params = array($user_id);
$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

// Delete image file if it exists
if (!empty($user['profile_image']) && file_exists("uploads/" . $user['profile_image'])) {
    unlink("uploads/" . $user['profile_image']);
}

// Update database to remove profile image reference
$updateSql = "UPDATE users SET profile_image = NULL WHERE id = ?";
$updateParams = array($user_id);
$updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);
if ($updateStmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
sqlsrv_free_stmt($updateStmt);

header("Location: userprofile.php");
exit();
?>
