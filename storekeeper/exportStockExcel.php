<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'storekeeper') {
    header("Location: ../login.php");
    exit();
}

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=stock_report_" . date("Y-m-d") . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$query = "SELECT * FROM items";
$params = array();
if ($search !== '') {
    $query .= " WHERE item_name LIKE ? OR capacity_type LIKE ? OR brand LIKE ?";
    $searchTerm = "%$search%";
    $params = array($searchTerm, $searchTerm, $searchTerm);
}
$query .= " ORDER BY item_name ASC, capacity_type ASC, brand ASC";
$result = sqlsrv_query($conn, $query, $params);

if ($result === false) {
    die(print_r(sqlsrv_errors(), true));
}

$rows = [];
while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $row;
}
sqlsrv_free_stmt($result);
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>Stock Report</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
</head>
<body>

<table border="1">
    <thead>
        <tr>
            <th colspan="9" style="font-size:14pt; font-weight:bold; text-align:center; background:#f8fafc; padding: 10px;">Stock Report</th>
        </tr>
        <tr>
            <th colspan="9" style="font-size:10pt; text-align:center; background:#f8fafc; padding: 5px; font-weight:normal;">Generated: <?= date("Y-m-d h:i A") ?></th>
        </tr>
        <tr>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; text-align:center; border:1px solid #005662; padding:8px;">No</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; border:1px solid #005662; padding:8px;">Item Name</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; border:1px solid #005662; padding:8px;">Capacity/Type</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; border:1px solid #005662; padding:8px;">Brand</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; text-align:center; border:1px solid #005662; padding:8px;">Quantity</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; text-align:right; border:1px solid #005662; padding:8px;">Unit Price (Rs.)</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; text-align:right; border:1px solid #005662; padding:8px;">Total Value (Rs.)</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; text-align:center; border:1px solid #005662; padding:8px;">Stock Status</th>
            <th style="background:#007c91; color:#ffffff; font-weight:bold; border:1px solid #005662; padding:8px;">Created At</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $i = 1;
        foreach ($rows as $row):
            $qty = (int) $row['quantity'];
            $unitPrice = (float) $row['unit_price'];
            $totalValue = $qty * $unitPrice;
            $isLow = ($qty <= 5);
            $status = $isLow ? "Low Stock" : "Available";
            $statusStyle = $isLow 
                ? 'background:#fef2f2; color:#991b1b; font-weight:bold; text-align:center; border:1px solid #e2e8f0; padding:8px;' 
                : 'background:#f0fdf4; color:#166534; font-weight:bold; text-align:center; border:1px solid #e2e8f0; padding:8px;';

            $createdAt = '—';
            if (isset($row['created_at'])) {
                if ($row['created_at'] instanceof DateTime) {
                    $createdAt = $row['created_at']->format('Y-m-d h:i A');
                } else {
                    $createdAt = date('Y-m-d h:i A', strtotime($row['created_at']));
                }
            }

            $rowBg = ($i % 2 === 0) ? 'background:#f8fafc;' : 'background:#ffffff;';
        ?>
        <tr style="<?= $rowBg ?>">
            <td style="text-align:center; border:1px solid #e2e8f0; padding:8px;"><?= $i++ ?></td>
            <td style="border:1px solid #e2e8f0; padding:8px;"><?= htmlspecialchars($row['item_name']) ?></td>
            <td style="border:1px solid #e2e8f0; padding:8px;"><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '—' ?></td>
            <td style="border:1px solid #e2e8f0; padding:8px;"><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '—' ?></td>
            <td style="text-align:center; border:1px solid #e2e8f0; padding:8px;"><?= $qty ?></td>
            <td style="text-align:right; border:1px solid #e2e8f0; padding:8px;"><?= number_format($unitPrice, 2, '.', '') ?></td>
            <td style="text-align:right; border:1px solid #e2e8f0; padding:8px;"><?= number_format($totalValue, 2, '.', '') ?></td>
            <td style="<?= $statusStyle ?>"><?= $status ?></td>
            <td style="border:1px solid #e2e8f0; padding:8px;"><?= $createdAt ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>