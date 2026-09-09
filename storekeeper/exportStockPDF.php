<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Page protection
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'storekeeper') {
    header("Location: ../login.php");
    exit();
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Stock Report PDF</title>
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

.text-center{
    text-align: center;
}

@media print{
    .no-print{
        display:none;
    }
}
@page {
        size: auto;   /* auto is the initial value */
        margin: 0;    /* This strips out the browser's default header/footer text */
    }

    @media print {
        body {
            margin: 15mm; /* This gives your report clean padding inside the page instead */
        }
    }


</style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom:20px;">
    <button onclick="window.print()">Print / Save as PDF</button>
</div>

<h2>Stock Report</h2>
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
        while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)):
            $qty = (int)$row['quantity'];
            $unitPrice = (float)$row['unit_price'];
            $totalValue = $qty * $unitPrice;
            $status = ($qty <= 5) ? "Low Stock" : "Available";

            $createdAtStr = '—';
            if (!empty($row['created_at'])) {
                if ($row['created_at'] instanceof DateTime) {
                    $createdAtStr = $row['created_at']->format('Y-m-d h:i A');
                } else {
                    $createdAtStr = date("Y-m-d h:i A", strtotime($row['created_at']));
                }
            }
        ?>
        <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($row['item_name']) ?></td>
            <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '—' ?></td>
            <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '—' ?></td>
            <td><?= $qty ?></td>
            <td>Rs. <?= number_format($unitPrice, 2) ?></td>
            <td>Rs. <?= number_format($totalValue, 2) ?></td>
            <td><?= $status ?></td>
            <td><?= htmlspecialchars($createdAtStr) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<script>
window.onload = function() {
    window.print();
};
</script>

</body>
</html>