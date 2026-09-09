<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

try {
    $stmt = $conn->query("
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
        ORDER BY pr.request_id DESC
    ");
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database Error in exportIssuedHistoryPDF.php: " . $e->getMessage());
    $result = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Issued Parts History PDF</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #000;
        }

        h2 {
            text-align: center;
            margin-bottom: 5px;
        }

        p {
            text-align: center;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 6px;
            text-align: left;
        }

        th {
            background: #f2f2f2;
        }

        .badge {
            font-weight: bold;
        }

        @media print {
            .no-print {
                display: none;
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

    <h2>Issued Parts History Report</h2>
    <p>Generated on <?= date("Y-m-d h:i A") ?></p>

    <table>
        <thead>
            <tr>
                <th>Req ID</th>
                <th>Issue ID</th>
                <th>Technician</th>
                <th>Item Name</th>
                <th>Cap/Type</th>
                <th>Brand</th>
                <th>Unit Price</th>
                <th>Req</th>
                <th>Appr</th>
                <th>Issued</th>
                <th>Cost</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($result as $row):
                ?>
                <tr>
                    <td><?= (int) $row['request_id'] ?></td>
                    <td><?= (int) $row['issue_id'] ?></td>
                    <td><?= htmlspecialchars($row['technician_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['item_name'] ?? '—') ?></td>
                    <td><?= !empty($row['capacity_type']) ? htmlspecialchars($row['capacity_type']) : '—' ?></td>
                    <td><?= !empty($row['brand']) ? htmlspecialchars($row['brand']) : '—' ?></td>
                    <td>Rs. <?= number_format((float) $row['unit_price'], 2) ?></td>
                    <td><?= (int) $row['quantity_requested'] ?></td>
                    <td><?= (int) $row['quantity_approved'] ?></td>
                    <td><?= (int) $row['quantity_issued'] ?></td>
                    <td>Rs. <?= number_format((float) $row['issued_cost'], 2) ?></td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                    <td><?= !empty($row['approval_date']) ? date("Y-m-d h:i A", strtotime($row['approval_date'])) : '—' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <script>
        window.onload = function () {
            window.print();
        };
    </script>

</body>

</html>