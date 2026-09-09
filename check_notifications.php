<?php
require_once 'security.php';
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get un-notified notifications
$query = "SELECT notification_id, title, message FROM notifications WHERE user_id = ? AND is_desktop_notified = 0 ORDER BY created_at DESC";
$params = array($user_id);
$stmt = sqlsrv_query($conn, $query, $params);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

$notifications = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $notifications[] = $row;
}
sqlsrv_free_stmt($stmt);

foreach ($notifications as $row) {
    // Mark as desktop notified
    $updateQuery = "UPDATE notifications SET is_desktop_notified = 1 WHERE notification_id = ?";
    $updateParams = array($row['notification_id']);
    $updateStmt = sqlsrv_query($conn, $updateQuery, $updateParams);
    if ($updateStmt !== false) {
        sqlsrv_free_stmt($updateStmt);
    }
}

header('Content-Type: application/json');
echo json_encode($notifications);
exit();
?>
