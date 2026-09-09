<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'storekeeper'])) {
    header("Location: ../login.php");
    exit();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$query = "SELECT * FROM items";
$params = [];
if ($search !== '') {
    $query .= " WHERE item_name LIKE ? OR capacity_type LIKE ? OR brand LIKE ?";
    $searchTerm = "%" . $search . "%";
    $params = [$searchTerm, $searchTerm, $searchTerm];
}
$query .= " ORDER BY item_name ASC, capacity_type ASC, brand ASC";

try {
    $stmt = sqlsrv_query($conn, $query, $params);
    if ($stmt === false) {
        error_log('Database Error in exportStorePDF.php: ' . implode(' ', sqlsrv_errors()));
        $result = [];
    } else {
        $result = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $result[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} catch (PDOException $e) {
    error_log("Database Error in exportStorePDF.php: " . $e->getMessage());
    $result = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Store Report PDF</title>
<style>
    body{
        font-family: Arial, sans-serif;
        margin: 20mm;
        color: #000;
    }
    @page {
        size: auto;
        margin: 0;
    }
    h2{
        text-align: center;
        margin-bottom: 5px;
    }
    p{
        text-align: center;
        margin-top: 0;
        margin-bottom: 20px;
        font-size: 14px;
    }
    table{
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    th, td{
        border: 1px solid #000;
        padding: 8px;
        text-align: left;
    }
    th{
        background: #f2f2f2;
    }
    @media print{
        .no-print{
            display:none;
        }
        body {
            margin: 20mm;
        }
    }
</style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom:20px;">
    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #007c91; color: white; border: none; border-radius: 5px; font-weight: bold;">Print / Save as PDF</button>
</div>

<h2>Store Report</h2>
<p>Generated on <?= date("Y-m-d h:i A") ?></p>

<table>
    <thead>
        <tr>
            <th>No</th>
            <th>Item Name</th>
            <th>Capacity/Type</th>
            <th>Brand</th>
            <th>Quantity</th>
            <th>Unit Price</th>
            <th>Total Value</th>
            <th>Status</th>
            <th>Created At</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $i = 1;
        foreach ($result as $row):
            $qty = (int)$row['quantity'];
            $unitPrice = (float)$row['unit_price'];
            $lineTotal = $qty * $unitPrice;
            $status = ($qty <= 5) ? "Low Stock" : "Available";
        ?>
        <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($row['item_name']) ?></td>
            <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '—' ?></td>
            <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '—' ?></td>
            <td><?= $qty ?></td>
            <td>Rs. <?= number_format($unitPrice, 2) ?></td>
            <td>Rs. <?= number_format($lineTotal, 2) ?></td>
            <td><?= $status ?></td>
            <td><?= !empty($row['created_at']) ? ( ($row['created_at'] instanceof DateTime) ? $row['created_at']->format('Y-m-d h:i A') : date('Y-m-d h:i A', strtotime($row['created_at'])) ) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
window.onload = function() {
    window.print();
};
</script>

</body>
</html>
