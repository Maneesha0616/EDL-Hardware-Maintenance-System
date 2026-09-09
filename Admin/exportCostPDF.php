<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

try {
    $stmt = sqlsrv_query($conn, "
        SELECT
            pr.request_id,
            pr.issue_id,
            pr.status,
            pr.approval_date,
            tech.username AS technician_name,
            itm.item_name,
            itm.capacity_type,
            itm.brand,
            itm.unit_price,
            pri.quantity_requested,
            pri.quantity_approved,
            pri.quantity_issued,
            (COALESCE(pri.quantity_issued, 0) * itm.unit_price) AS issued_cost,
            (COALESCE(pri.quantity_approved, 0) * itm.unit_price) AS approved_cost
        FROM parts_requests pr
        LEFT JOIN parts_request_items pri ON pr.request_id = pri.request_id
        LEFT JOIN items itm ON pri.item_id = itm.item_id
        LEFT JOIN users tech ON pr.technician_id = tech.id
        ORDER BY pr.request_id DESC
    ", $params = array());
    if ($stmt === false) {
        error_log('Database Error in exportCostPDF.php: ' . implode(' ', sqlsrv_errors()));
        $result = [];
    } else {
        $result = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $result[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} catch (PDOException $e) {
    error_log("Database Error in exportCostPDF.php: " . $e->getMessage());
    $result = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cost Report PDF</title>
<style>
body{
    font-family: Arial, sans-serif;
    margin: 20px;
    color: #000;
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
    font-size: 10px;
}
th, td{
    border: 1px solid #000;
    padding: 6px;
    text-align: left;
}
th{
    background: #f2f2f2;
}
@media print{
    .no-print{
        display:none;
    }
}
@page {
        size: auto;   
        margin: 0;    
    }

    @media print {
        body {
            margin: 15mm; 
        }
    }

</style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom:20px;">
    <button onclick="window.print()">Print / Save as PDF</button>
</div>

<h2>Request-wise Cost Report</h2>
<p>Generated on <?= date("Y-m-d h:i A") ?></p>

<table>
    <thead>
        <tr>
            <th>Req ID</th>
            <th>Issue ID</th>
            <th>Technician</th>
            <th>Item</th>
            <th>Type</th>
            <th>Brand</th>
            <th>Price</th>
            <th>App. Qty</th>
            <th>Iss. Qty</th>
            <th>App. Cost</th>
            <th>Iss. Cost</th>
            <th>Status</th>
            <th>Approval Date</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($result as $row): ?>
        <tr>
            <td><?= (int)$row['request_id'] ?></td>
            <td><?= (int)$row['issue_id'] ?></td>
            <td><?= htmlspecialchars($row['technician_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($row['item_name'] ?? '—') ?></td>
            <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '—' ?></td>
            <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '—' ?></td>
            <td>Rs. <?= number_format((float)$row['unit_price'], 2) ?></td>
            <td><?= (int)$row['quantity_approved'] ?></td>
            <td><?= (int)$row['quantity_issued'] ?></td>
            <td>Rs. <?= number_format((float)$row['approved_cost'], 2) ?></td>
            <td>Rs. <?= number_format((float)$row['issued_cost'], 2) ?></td>
            <td><?= htmlspecialchars($row['status']) ?></td>
            <td><?= !empty($row['approval_date']) ? ( ($row['approval_date'] instanceof DateTime) ? $row['approval_date']->format('Y-m-d h:i A') : date('Y-m-d h:i A', strtotime($row['approval_date'])) ) : '—' ?></td>
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
