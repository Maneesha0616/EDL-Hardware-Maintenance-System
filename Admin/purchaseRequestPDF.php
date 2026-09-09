<?php
require_once dirname(__DIR__) . '/security.php';
session_start();
include "../db.php";

// Allow both admin and unithead to view
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'unithead'])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Purchase request ID is required.");
}

$purchase_request_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Set back page based on role
$backPage = ($role === 'admin') ? 'purchaseRequest.php' : 'approveRequest.php';

// Admin can only view their own requests
try {
    if ($role === 'admin') {
        $stmt = sqlsrv_prepare($conn, "
            SELECT 
                pr.purchase_request_id,
                pr.item_name,
                pr.quantity,
                pr.unit_price,
                pr.request_type,
                pr.justification,
                pr.letter_content,
                pr.status,
                pr.unit_head_remarks,
                pr.created_at,
                pr.reviewed_at,
                u.name AS admin_name
            FROM purchase_requests pr
            INNER JOIN users u ON pr.admin_id = u.id
            WHERE pr.purchase_request_id = ? AND pr.admin_id = ?
        ", [$purchase_request_id, $user_id]);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
        }
        if (!sqlsrv_execute($stmt)) {
            throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
        }
    } else {
        // Unit Head can view any purchase request
        $stmt = sqlsrv_prepare($conn, "
            SELECT 
                pr.purchase_request_id,
                pr.item_name,
                pr.quantity,
                pr.unit_price,
                pr.request_type,
                pr.justification,
                pr.letter_content,
                pr.status,
                pr.unit_head_remarks,
                pr.created_at,
                pr.reviewed_at,
                u.name AS admin_name
            FROM purchase_requests pr
            INNER JOIN users u ON pr.admin_id = u.id
            WHERE pr.purchase_request_id = ?
        ", [$purchase_request_id]);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . print_r(sqlsrv_errors(), true));
        }
        if (!sqlsrv_execute($stmt)) {
            throw new Exception('Execute failed: ' . print_r(sqlsrv_errors(), true));
        }
    }
    $request = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Database Error in purchaseRequestPDF.php: " . $e->getMessage());
    $request = null;
}

if (!$request) {
    die("Purchase request not found or access denied.");
}

// Format dates
$createdDate = '';
$createdTime = '';
if (!empty($request['created_at'])) {
    if ($request['created_at'] instanceof DateTime) {
        $createdDate = $request['created_at']->format('d/m/Y');
        $createdTime = $request['created_at']->format('h:i A');
    } else {
        $createdDate = date('d/m/Y', strtotime($request['created_at']));
        $createdTime = date('h:i A', strtotime($request['created_at']));
    }
}

$reviewedDate = '';
if (!empty($request['reviewed_at'])) {
    if ($request['reviewed_at'] instanceof DateTime) {
        $reviewedDate = $request['reviewed_at']->format('d/m/Y h:i A');
    } else {
        $reviewedDate = date('d/m/Y h:i A', strtotime($request['reviewed_at']));
    }
}

$adminName = htmlspecialchars($request['admin_name']);
$itemName = htmlspecialchars($request['item_name']);
$quantity = (int) $request['quantity'];
$unitPrice = (float) ($request['unit_price'] ?? 0);
$totalCost = $quantity * $unitPrice;
$justification = nl2br(htmlspecialchars($request['justification']));
$status = $request['status'];
$remarks = htmlspecialchars($request['unit_head_remarks'] ?? '');
$requestType = $request['request_type'] ?? 'Manual';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Purchase Request Letter - #<?= $request['purchase_request_id'] ?></title>
    <style>
        @page {
            size: A4;
            margin: 20mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Times New Roman", Georgia, serif;
            background: #e8e8e8;
            margin: 0;
            padding: 30px;
            color: #000;
        }

        .top-actions {
            text-align: center;
            margin-bottom: 25px;
        }

        .print-btn,
        .back-btn {
            display: inline-block;
            padding: 10px 22px;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-family: Arial, sans-serif;
            font-weight: 600;
            font-size: 14px;
            margin: 0 5px;
            transition: background 0.2s;
        }

        .print-btn {
            background: #007c91;
        }

        .print-btn:hover {
            background: #005f6b;
        }

        .back-btn {
            background: #6c757d;
        }

        .back-btn:hover {
            background: #545b62;
        }

        /* ─── A4 Page Container ─── */
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 25mm 30mm;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.18);
            position: relative;
        }

        /* ─── Company Letterhead ─── */
        .letterhead {
            text-align: center;
            border-bottom: 3px double #000;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }

        .letterhead h1 {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .letterhead .subtitle {
            font-size: 13px;
            color: #444;
            margin: 2px 0;
        }

        .letterhead .doc-title {
            font-size: 16px;
            font-weight: bold;
            margin-top: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1a1a1a;
        }

        /* ─── Reference & Date Row ─── */
        .ref-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .ref-row .ref-left,
        .ref-row .ref-right {
            line-height: 1.8;
        }

        .ref-row .ref-right {
            text-align: right;
        }

        /* ─── Addressee Block ─── */
        .addressee {
            font-size: 15px;
            line-height: 1.8;
            margin-bottom: 20px;
        }

        /* ─── Subject Line ─── */
        .subject {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 20px;
            text-decoration: underline;
        }

        /* ─── Salutation ─── */
        .salutation {
            font-size: 15px;
            margin-bottom: 18px;
        }

        /* ─── Body Paragraphs ─── */
        .body-text {
            font-size: 15px;
            line-height: 1.8;
            text-align: justify;
            margin-bottom: 18px;
        }

        /* ─── Details Table ─── */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0 20px 0;
            font-size: 14px;
        }

        .details-table td {
            border: 1px solid #333;
            padding: 10px 14px;
            vertical-align: top;
        }

        .details-table .label-col {
            width: 35%;
            font-weight: bold;
            background: #f7f7f7;
        }

        /* ─── Closing ─── */
        .closing {
            font-size: 15px;
            line-height: 1.6;
            margin-top: 20px;
        }

        .closing .thank-you {
            margin-bottom: 15px;
        }

        .closing .sign-off {
            margin-bottom: 5px;
        }

        .signature-block {
            margin-top: 30px;
        }

        .signature-block .name {
            font-weight: bold;
            font-size: 15px;
        }

        .signature-block .title {
            font-size: 14px;
            color: #333;
            line-height: 1.5;
        }

        /* ─── Status Section ─── */
        .status-section {
            margin-top: 35px;
            padding-top: 20px;
            border-top: 1px solid #999;
        }

        .status-section h3 {
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-section p {
            font-size: 14px;
            line-height: 1.8;
            margin-bottom: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 3px;
            font-size: 13px;
            font-weight: bold;
            font-family: Arial, sans-serif;
        }

        .status-Pending {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
        }

        .status-Approved {
            background: #d4edda;
            color: #155724;
            border: 1px solid #28a745;
        }

        .status-Rejected {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #dc3545;
        }

        .status-Received {
            background: #cce5ff;
            color: #004085;
            border: 1px solid #007bff;
        }

        /* ─── Print Styles ─── */
        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .top-actions {
                display: none;
            }

            .page {
                width: 100%;
                min-height: auto;
                box-shadow: none;
                margin: 0;
                padding: 0;
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>

    <div class="top-actions">
        <a href="#" onclick="window.print();" class="print-btn">🖨 Print / Save as PDF</a>
        <a href="<?= htmlspecialchars($backPage); ?>" class="back-btn">← Back</a>
    </div>

    <div class="page">

        <!-- Company Letterhead -->
        <div class="letterhead">
            <h1>Electricity Distribution Lanka (Pvt) Ltd</h1>
            <p class="subtitle">Hardware Maintenance System</p>
            <p class="doc-title">
                <?php
                if ($requestType === 'Inventory') {
                    echo "Inventory Restock Request";
                } else {
                    echo "Purchase Request Letter";
                }
                ?>
            </p>
        </div>

        <!-- Reference Number & Date -->
        <div class="ref-row">
            <div class="ref-left">
                <strong>Ref No:</strong>
                PR/<?= str_pad($request['purchase_request_id'], 4, '0', STR_PAD_LEFT) ?>/<?= date('Y') ?>
            </div>
            <div class="ref-right">
                <strong>Date:</strong> <?= $createdDate ?><br>
                <strong>Time:</strong> <?= $createdTime ?>
            </div>
        </div>

        <!-- Addressee -->
        <div class="addressee">
            The Unit Head,<br>
            Electricity Distribution Lanka (Pvt) Ltd
        </div>

        <!-- Subject -->
        <p class="subject">Request for Approval to Purchase Required Item</p>

        <!-- Salutation -->
        <p class="salutation">Dear Sir/Madam,</p>

        <!-- Body -->
        <p class="body-text">
            I respectfully submit this request seeking your kind approval to purchase the item detailed below
            for official operational purposes. The requested item is essential to support ongoing work activities
            efficiently and to prevent delays in service delivery.
        </p>

        <p class="body-text"><strong>Purchase Request Details:</strong></p>

        <!-- Details Table -->
        <table class="details-table">
            <tr>
                <td class="label-col">Item Name</td>
                <td><?= $itemName ?></td>
            </tr>
            <tr>
                <td class="label-col">Unit Price (LKR)</td>
                <td>Rs. <?= number_format($unitPrice, 2) ?></td>
            </tr>
            <tr>
                <td class="label-col">Quantity</td>
                <td><?= $quantity ?></td>
            </tr>
            <tr>
                <td class="label-col">Total Estimated Cost</td>
                <td><strong>Rs. <?= number_format($totalCost, 2) ?></strong></td>
            </tr>
            <tr>
                <td class="label-col">Justification</td>
                <td><?= $justification ?></td>
            </tr>
        </table>

        <!-- Closing paragraph -->
        <p class="body-text">
            In view of the above, I kindly request your approval to proceed with this purchase.
            Your favorable consideration and approval would be greatly appreciated.
        </p>

        <!-- Thank you & Sign-off -->
        <div class="closing">
            <p class="thank-you">Thank you.</p>
            <p class="sign-off">Yours faithfully,</p>
        </div>

        <div class="signature-block">
            <p class="name"><?= $adminName ?></p>
            <p class="title">Administrator</p>
            <p class="title">Electricity Distribution Lanka (Pvt) Ltd</p>
        </div>

        <!-- Status Section (only shown if reviewed) -->
        <?php if ($status !== 'Pending'): ?>
            <div class="status-section">
                <h3>Official Decision</h3>
                <p>
                    <strong>Status:</strong>
                    <span
                        class="status-badge status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
                </p>
                <?php if (!empty($reviewedDate)): ?>
                    <p><strong>Reviewed Date:</strong> <?= $reviewedDate ?></p>
                <?php endif; ?>
                <?php if (!empty($remarks)): ?>
                    <p><strong>Remarks:</strong> <?= $remarks ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

</body>

</html>