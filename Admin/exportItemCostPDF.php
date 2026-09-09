<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

try {
    // Use sqlsrv_query for SQL Server connection
    $sql = "
        SELECT
            itm.item_name,
            itm.capacity_type,
            itm.brand,
            itm.unit_price,
            COALESCE(SUM(pri.quantity_approved), 0) AS total_approved_qty,
            COALESCE(SUM(pri.quantity_issued), 0) AS total_issued_qty,
            COALESCE(SUM(pri.quantity_approved * itm.unit_price), 0) AS total_approved_value,
            COALESCE(SUM(pri.quantity_issued * itm.unit_price), 0) AS total_issued_value
        FROM items itm
        LEFT JOIN parts_request_items pri ON itm.item_id = pri.item_id
        GROUP BY itm.item_id, itm.item_name, itm.capacity_type, itm.brand, itm.unit_price
        ORDER BY itm.item_name ASC";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        error_log("Database query error in exportItemCostPDF.php: " . print_r(sqlsrv_errors(), true));
        $result = [];
    } else {
        $result = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $result[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} catch (PDOException $e) {
    error_log("Database Error in exportItemCostPDF.php: " . $e->getMessage());
    $result = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Item-wise Cost Summary PDF</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #000; }
        h2 { text-align: center; margin-bottom: 5px; }
        p { text-align: center; margin-top: 0; margin-bottom: 20px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; }
        th { background: #f2f2f2; }
        @media print { .no-print { display:none; } }

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

<h2>Item-wise Cost Summary Report</h2>
<p>Generated on <?= date("Y-m-d h:i A") ?></p>

<table>
    <thead>
        <tr>
            <th>Item Name</th>
            <th>Capacity/Type</th>
            <th>Brand</th>
            <th>Unit Price</th>
            <th>Total Approved Qty</th>
            <th>Total Issued Qty</th>
            <th>Total Approved Value</th>
            <th>Total Issued Value</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($result as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['item_name'] ?? '—') ?></td>
            <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '—' ?></td>
            <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '—' ?></td>
            <td>Rs. <?= number_format((float)$row['unit_price'], 2) ?></td>
            <td><?= (int)$row['total_approved_qty'] ?></td>
            <td><?= (int)$row['total_issued_qty'] ?></td>
            <td>Rs. <?= number_format((float)$row['total_approved_value'], 2) ?></td>
            <td>Rs. <?= number_format((float)$row['total_issued_value'], 2) ?></td>
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
