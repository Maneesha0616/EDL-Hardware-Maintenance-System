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
        i.received_date,
        i.recommendation,
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

try {
    $params = [];
    if (!empty($search) && !empty($status)) {
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $status];
    } elseif (!empty($search)) {
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    } elseif (!empty($status)) {
        $params = [$status];
    }

    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
    }
    if (!sqlsrv_execute($stmt)) {
        throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
    }
    $result = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result[] = $row;
    }
    sqlsrv_free_stmt($stmt);
} catch (Exception $e) {
    error_log("Database Error in exportIssuesPDF.php: " . $e->getMessage());
    $result = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Issue Reports PDF</title>
    <style>
        body { font-family: 'Poppins', Arial, sans-serif; margin: 20px; color: #1e293b; }
        h2 { text-align: center; color: #007c91; margin-bottom: 5px; }
        p { text-align: center; margin-top: 0; margin-bottom: 20px; font-size: 14px; color: #64748b; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        th { background: #f8fafc; color: #1e2a38; font-weight: 700; }
        .status-Pending { color: #f59e0b; font-weight: bold; }
        .status-Assigned { color: #3b82f6; font-weight: bold; }
        .status-Completed { color: #10b981; font-weight: bold; }
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

<div class="no-print" style="text-align:right; margin-bottom:20px;">
    <button onclick="window.print()" style="background:#007c91; color:#fff; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:600;">
        <i class="fas fa-print"></i> Print / Save as PDF
    </button>
</div>

<h2>Hardware Maintenance System - Issue Reports</h2>
<p>
    Generated on <?= date("Y-m-d h:i A") ?>
    <?php if(!empty($search)) echo " | Search: " . htmlspecialchars($search); ?>
    <?php if(!empty($status)) echo " | Status: " . htmlspecialchars($status); ?>
</p>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Reported By</th>
            <th>Type</th>
            <th>Category</th>
            <th>Service Tag</th>
            <th>Description</th>
            <th>Requested Parts</th>
            <th>Technician</th>
            <th>Status</th>
            <th>Recommendation</th>
            <th>Received Date</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($result as $row): ?>
        <tr>
            <td>#<?= $row['issue_id'] ?></td>
            <td><?= htmlspecialchars($row['username'] ?? 'Deleted User') ?></td>
            <td><?= htmlspecialchars($row['issue_type']) ?></td>
            <td><?= htmlspecialchars($row['issue_category']) ?></td>
            <td><?= htmlspecialchars($row['service_tag']) ?></td>
            <td><?= htmlspecialchars($row['issue_description']) ?></td>
            <td><?= htmlspecialchars($row['parts_list'] ?? '—') ?></td>
            <td><?= htmlspecialchars($row['technician'] ?? 'Unassigned') ?></td>
            <td class="status-<?= str_replace(' ', '', $row['status']) ?>">
                <?= htmlspecialchars($row['status']) ?>
            </td>
            <td><?= htmlspecialchars($row['recommendation'] ?? '—') ?></td>
            <td><?= !empty($row['received_date']) ? (($row['received_date'] instanceof DateTime) ? $row['received_date']->format('Y-m-d h:i A') : date('Y-m-d h:i A', strtotime($row['received_date']))) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
    window.onload = function() {
        // window.print(); // Uncomment to auto-print
    };
</script>

</body>
</html>
