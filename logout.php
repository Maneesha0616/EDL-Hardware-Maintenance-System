<?php
require_once __DIR__ . '/security.php';
session_start();
include "db.php";

if (isset($_SESSION['user_id'])) {
    // Log the logout activity using SQL Server (log_system_activity now uses sqlsrv)
    log_system_activity($conn, $_SESSION['user_id'], 'User Logout', 'User logged out');
}

// Clear session data
$_SESSION = [];
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>
