<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=item_wise_cost_summary_" . date("Y-m-d") . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

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
$rows = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
} else {
    error_log("Database query error in exportItemCostExcel.php: " . print_r(sqlsrv_errors(), true));
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta charset="UTF-8">
    <!--[if gte mso 9]>
    <xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
    <x:Name>Item-wise Cost Summary</x:Name>
    <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
    </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
    <![endif]-->
</head>
<body>
<table border="1">
    <thead>
        <tr>
            <th colspan="8" style="font-size:14pt; font-weight:bold;">Item-wise Cost Summary Report</th>
        </tr>
        <tr>
            <td colspan="8">Generated: <?= date("Y-m-d H:i:s") ?></td>
        </tr>
        <tr>
            <th style="background:#007c91; color:#fff;">Item Name</th>
            <th style="background:#007c91; color:#fff;">Capacity/Type</th>
            <th style="background:#007c91; color:#fff;">Brand</th>
            <th style="background:#007c91; color:#fff;">Unit Price (Rs.)</th>
            <th style="background:#007c91; color:#fff;">Total Approved Qty</th>
            <th style="background:#007c91; color:#fff;">Total Issued Qty</th>
            <th style="background:#007c91; color:#fff;">Total Approved Value (Rs.)</th>
            <th style="background:#007c91; color:#fff;">Total Issued Value (Rs.)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
            <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '-' ?></td>
            <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '-' ?></td>
            <td><?= number_format((float) $row['unit_price'], 2) ?></td>
            <td><?= (int) $row['total_approved_qty'] ?></td>
            <td><?= (int) $row['total_issued_qty'] ?></td>
            <td><?= number_format((float) $row['total_approved_value'], 2) ?></td>
            <td><?= number_format((float) $row['total_issued_value'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>