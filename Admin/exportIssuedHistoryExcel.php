<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=issued_parts_history_" . date("Y-m-d") . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

$sql = "
    SELECT
        pr.request_id,
        pr.issue_id,
        pr.status,
        pr.approval_date,
        u.username AS technician_name,
        it.item_name,
        it.capacity_type,
        it.brand,
        it.unit_price,
        pri.quantity_requested,
        pri.quantity_approved,
        pri.quantity_issued,
        (COALESCE(pri.quantity_issued, 0) * it.unit_price) AS issued_cost
    FROM parts_requests pr
    LEFT JOIN parts_request_items pri ON pr.request_id = pri.request_id
    LEFT JOIN items it ON pri.item_id = it.item_id
    LEFT JOIN users u ON pr.technician_id = u.id
    WHERE pr.status = 'Supplied'
    ORDER BY pr.request_id DESC";

$stmt = sqlsrv_query($conn, $sql);
$rows = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
} else {
    error_log("Database query error in exportIssuedHistoryExcel.php: " . print_r(sqlsrv_errors(), true));
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta charset="UTF-8">
    <!--[if gte mso 9]>
    <xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
    <x:Name>Issued Parts History</x:Name>
    <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
    </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
    <![endif]-->
</head>
<body>
<table border="1">
    <thead>
        <tr>
            <th colspan="13" style="font-size:14pt; font-weight:bold;">Issued Parts History Report</th>
        </tr>
        <tr>
            <td colspan="13">Generated: <?= date("Y-m-d H:i:s") ?></td>
        </tr>
        <tr>
            <th style="background:#007c91; color:#fff;">Request ID</th>
            <th style="background:#007c91; color:#fff;">Issue ID</th>
            <th style="background:#007c91; color:#fff;">Technician</th>
            <th style="background:#007c91; color:#fff;">Item Name</th>
            <th style="background:#007c91; color:#fff;">Capacity/Type</th>
            <th style="background:#007c91; color:#fff;">Brand</th>
            <th style="background:#007c91; color:#fff;">Unit Price (Rs.)</th>
            <th style="background:#007c91; color:#fff;">Requested Qty</th>
            <th style="background:#007c91; color:#fff;">Approved Qty</th>
            <th style="background:#007c91; color:#fff;">Issued Qty</th>
            <th style="background:#007c91; color:#fff;">Issued Cost (Rs.)</th>
            <th style="background:#007c91; color:#fff;">Status</th>
            <th style="background:#007c91; color:#fff;">Approval Date</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <?php
            $approval_date = '-';
            if (!empty($row['approval_date'])) {
                $approval_date = ($row['approval_date'] instanceof DateTime)
                    ? $row['approval_date']->format('Y-m-d h:i A')
                    : date("Y-m-d h:i A", strtotime($row['approval_date']));
            }
        ?>
        <tr>
            <td><?= (int) $row['request_id'] ?></td>
            <td><?= (int) $row['issue_id'] ?></td>
            <td><?= htmlspecialchars($row['technician_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['item_name'] ?? '-') ?></td>
            <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '-' ?></td>
            <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '-' ?></td>
            <td><?= number_format((float) $row['unit_price'], 2) ?></td>
            <td><?= (int) $row['quantity_requested'] ?></td>
            <td><?= (int) $row['quantity_approved'] ?></td>
            <td><?= (int) $row['quantity_issued'] ?></td>
            <td><?= number_format((float) $row['issued_cost'], 2) ?></td>
            <td><?= htmlspecialchars($row['status']) ?></td>
            <td><?= $approval_date ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>