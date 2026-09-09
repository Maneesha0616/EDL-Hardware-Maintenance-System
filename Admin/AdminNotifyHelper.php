<?php
function notifyAllAdmins($conn, string $title, string $message): void
{
    // Retrieve all admin user IDs
    $sql = "SELECT id FROM users WHERE role = ?";
    $params = ['admin'];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        error_log('Database error (fetch admins): ' . print_r(sqlsrv_errors(), true));
        return;
    }
    $admins = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $admins[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    if (empty($admins)) {
        return;
    }

    $insertSql = "INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, GETDATE())";
    foreach ($admins as $admin) {
        $admin_id = (int)$admin['id'];
        $insertStmt = sqlsrv_query($conn, $insertSql, [$admin_id, $title, $message]);
        if ($insertStmt === false) {
            error_log('Database error (notify admin): ' . print_r(sqlsrv_errors(), true));
        } else {
            sqlsrv_free_stmt($insertStmt);
        }
    }
}

function createLowStockAlert($conn, int $item_id): void
{
    $sql = "SELECT item_name, quantity, low_stock_threshold FROM items WHERE item_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$item_id]);
    if ($stmt === false) {
        error_log('Database error (low stock fetch): ' . print_r(sqlsrv_errors(), true));
        return;
    }
    $item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$item) {
        return;
    }
    $qty = (int)$item['quantity'];
    $threshold = isset($item['low_stock_threshold']) ? (int)$item['low_stock_threshold'] : 5;
    if ($qty > $threshold) {
        return;
    }
    $title = "Low Stock Warning";
    $message = "The stock level for '{$item['item_name']}' is running low. " .
           "Only {$qty} items remain, which is below the minimum threshold of {$threshold}. " .
           "Please restock this item soon.";
    notifyAllAdmins($conn, $title, $message);
}
?>