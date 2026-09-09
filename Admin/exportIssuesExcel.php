<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=issue_reports_" . date("Y-m-d") . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

$sql = "
    SELECT 
        i.issue_id,
        i.issue_type,
        i.issue_category,
        i.software_name,
        i.issue_description,
        i.additional_information,
        i.service_tag,
        i.status,
        i.recommendation,
        i.received_date,
        i.assigned_date,
        i.completed_date,
        u.username AS username,
        t.username AS technician,
        p.parts_list
    FROM issues i
    LEFT JOIN users u ON i.user_id = u.id
    LEFT JOIN users t ON i.technician_id = t.id
    LEFT JOIN (
        SELECT pr.issue_id, STRING_AGG(CONCAT(it.item_name, ' (', pri.quantity_requested, ')'), ', ') as parts_list
        FROM parts_requests pr
        JOIN parts_request_items pri ON pr.request_id = pri.request_id
        JOIN items it ON pri.item_id = it.item_id
        GROUP BY pr.issue_id
    ) p ON i.issue_id = p.issue_id
    WHERE 1=1
";

if (!empty($search)) {
    $searchTerm = "%" . $search . "%";
    $sql .= " AND (u.username LIKE ? OR i.issue_description LIKE ? OR i.service_tag LIKE ? OR i.issue_id LIKE ?)";
}

if (!empty($status)) {
    $sql .= " AND i.status = ?";
}

$sql .= " ORDER BY i.received_date DESC";

$rows = [];
try {
    $params = [];
    if (!empty($search) && !empty($status)) {
        $params = [&$searchTerm, &$searchTerm, &$searchTerm, &$searchTerm, &$status];
    } elseif (!empty($search)) {
        $params = [&$searchTerm, &$searchTerm, &$searchTerm, &$searchTerm];
    } elseif (!empty($status)) {
        $params = [&$status];
    }
    
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    if (!sqlsrv_execute($stmt)) {
        throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
} catch (Exception $e) {
    error_log("Database Error in exportIssuesExcel.php: " . $e->getMessage());
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta charset="UTF-8">
    <!--[if gte mso 9]>
    <xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
    <x:Name>Issue Reports</x:Name>
    <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
    </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
    <![endif]-->
</head>
<body>
<table border="1">
    <thead>
        <tr>
            <th colspan="15" style="font-size:14pt; font-weight:bold; text-align:left;">Hardware Maintenance System - Issue Reports</th>
        </tr>
        <tr>
            <td colspan="15" style="text-align:left;">Generated: <?= date("Y-m-d H:i:s") ?></td>
        </tr>
        <?php if(!empty($search)): ?>
        <tr>
            <td colspan="15" style="text-align:left;">Search Query: <?= htmlspecialchars($search) ?></td>
        </tr>
        <?php endif; ?>
        <?php if(!empty($status)): ?>
        <tr>
            <td colspan="15" style="text-align:left;">Status Filter: <?= htmlspecialchars($status) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Issue ID</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Reported By</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Issue Type</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Category</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Software</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Service Tag</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Description</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Additional Info</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Requested Parts</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Technician</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Status</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Recommendation</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Received Date</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Assigned Date</th>
            <th style="background:#007c91; color:#fff; font-weight:bold;">Completed Date</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td>#<?= $row['issue_id'] ?></td>
            <td><?= htmlspecialchars($row['username'] ?? 'Deleted User') ?></td>
            <td><?= htmlspecialchars($row['issue_type']) ?></td>
            <td><?= htmlspecialchars($row['issue_category']) ?></td>
            <td><?= htmlspecialchars($row['software_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($row['service_tag'] ?: '—') ?></td>
            <td><?= htmlspecialchars($row['issue_description']) ?></td>
            <td><?= htmlspecialchars($row['additional_information'] ?? '—') ?></td>
            <td><?= htmlspecialchars($row['parts_list'] ?? '—') ?></td>
            <td><?= htmlspecialchars($row['technician'] ?? 'Unassigned') ?></td>
            <td><?= htmlspecialchars($row['status']) ?></td>
            <td><?= htmlspecialchars($row['recommendation'] ?? '—') ?></td>
            <td><?= ($row['received_date'] ? ( ($row['received_date'] instanceof DateTime) ? $row['received_date']->format("Y-m-d h:i A") : date("Y-m-d h:i A", strtotime($row['received_date'])) ) : '—') ?></td>
            <td><?= ($row['assigned_date'] ? ( ($row['assigned_date'] instanceof DateTime) ? $row['assigned_date']->format("Y-m-d h:i A") : date("Y-m-d h:i A", strtotime($row['assigned_date'])) ) : '—') ?></td>
            <td><?= ($row['completed_date'] ? ( ($row['completed_date'] instanceof DateTime) ? $row['completed_date']->format("Y-m-d h:i A") : date("Y-m-d h:i A", strtotime($row['completed_date'])) ) : '—') ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
