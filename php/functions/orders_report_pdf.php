<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';

$pdo = db();

$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

if ($fromDate === '' || $toDate === '') {
    die('Missing date range.');
}

$notSuppliedSql = "
    SELECT
        o.invoice_no,
        o.completed_at,
        o.customer_name,
        oi.sku_code,
        oi.description,
        oi.order_qty AS qty_ordered,

        CASE
            WHEN UPPER(TRIM(oi.qty_supplied)) = 'NO STOCK' THEN 0
            WHEN oi.qty_supplied REGEXP '^[0-9]+$' THEN CAST(oi.qty_supplied AS UNSIGNED)
            ELSE 0
        END AS qty_supplied,

        GREATEST(
            oi.order_qty -
            CASE
                WHEN UPPER(TRIM(oi.qty_supplied)) = 'NO STOCK' THEN 0
                WHEN oi.qty_supplied REGEXP '^[0-9]+$' THEN CAST(oi.qty_supplied AS UNSIGNED)
                ELSE 0
            END,
            0
        ) AS qty_not_supplied,

        CASE
            WHEN UPPER(TRIM(oi.qty_supplied)) = 'NO STOCK' THEN 'NO STOCK'
            WHEN oi.not_supplied_reason IS NOT NULL AND oi.not_supplied_reason != '' THEN oi.not_supplied_reason
            ELSE 'SHORT SUPPLY'
        END AS not_supplied_reason

    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    WHERE DATE(o.order_date) BETWEEN :from_date AND :to_date
    AND o.completed_at IS NOT NULL
    AND LOWER(o.status) = 'sent'
    AND oi.order_qty >
        CASE
            WHEN UPPER(TRIM(oi.qty_supplied)) = 'NO STOCK' THEN 0
            WHEN oi.qty_supplied REGEXP '^[0-9]+$' THEN CAST(oi.qty_supplied AS UNSIGNED)
            ELSE 0
        END
    ORDER BY o.completed_at DESC, o.invoice_no ASC, oi.sku_code ASC
";

$stmt = $pdo->prepare($notSuppliedSql);
$stmt->execute([
    ':from_date' => $fromDate,
    ':to_date' => $toDate
]);
$notSupplied = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notSentSql = "
    SELECT
        id,
        invoice_no,
        customer_name,
        order_date,
        delivery_date,
        status,
        status_reason
    FROM orders
    WHERE DATE(order_date) BETWEEN :from_date AND :to_date
    AND LOWER(status) != 'sent'
    ORDER BY order_date DESC, invoice_no ASC
";

$stmt = $pdo->prepare($notSentSql);
$stmt->execute([
    ':from_date' => $fromDate,
    ':to_date' => $toDate
]);
$notSent = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notSentItemsSql = "
    SELECT
        order_id,
        sku_code,
        description,
        order_qty,
        total_qty,
        qty_supplied
    FROM order_items
    WHERE order_id IN (
        SELECT id
        FROM orders
        WHERE DATE(order_date) BETWEEN :from_date AND :to_date
        AND LOWER(status) != 'sent'
    )
    ORDER BY order_id ASC, sku_code ASC
";

$stmt = $pdo->prepare($notSentItemsSql);
$stmt->execute([
    ':from_date' => $fromDate,
    ':to_date' => $toDate
]);
$itemsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notSentItems = [];

foreach ($itemsRaw as $item) {
    $notSentItems[$item['order_id']][] = $item;
}

$stmt = $pdo->prepare($notSentSql);
$stmt->execute([
    ':from_date' => $fromDate,
    ':to_date' => $toDate
]);
$notSent = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Orders Report PDF</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 24px;
            color: #132752;
        }

        h1 {
            margin-bottom: 4px;
        }

        .subtitle {
            margin-bottom: 22px;
            color: #555;
        }

        h2 {
            margin-top: 26px;
            border-bottom: 2px solid #2451b3;
            padding-bottom: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 12px;
        }

        th {
            background: #2451b3;
            color: white;
            padding: 8px;
            text-align: left;
        }

        td {
            border: 1px solid #d1d5db;
            padding: 7px;
        }

        .danger {
            color: #dc2626;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            color: #666;
        }

        .order-box {
    margin-top: 18px;
    padding: 12px;
    border: 1px solid #d1d5db;
    page-break-inside: avoid;
}

.order-box h3 {
    margin: 0 0 10px;
    font-size: 16px;
}

.order-box h4 {
    margin: 14px 0 6px;
    font-size: 14px;
}

        @media print {
            button {
                display: none;
            }

            body {
                padding: 12px;
            }
        }
    </style>
</head>
<body onload="window.print()">

<button onclick="window.print()">Print / Save as PDF</button>

<h1>Orders Report</h1>
<div class="subtitle">
    Date Range: <?= htmlspecialchars($fromDate) ?> to <?= htmlspecialchars($toDate) ?>
</div>

<h2>Products Not Supplied</h2>

<table>
    <thead>
        <tr>
            <th>Invoice</th>
            <th>Completed Date</th>
            <th>Customer</th>
            <th>SKU</th>
            <th>Description</th>
            <th>Ordered</th>
            <th>Supplied</th>
            <th>Not Supplied</th>
            <th>Reason</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$notSupplied): ?>
            <tr>
                <td colspan="9" class="empty">No products not supplied for this date range.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($notSupplied as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['invoice_no']) ?></td>
                    <td><?= htmlspecialchars((string)$row['completed_at']) ?></td>
                    <td><?= htmlspecialchars((string)$row['customer_name']) ?></td>
                    <td><?= htmlspecialchars((string)$row['sku_code']) ?></td>
                    <td><?= htmlspecialchars((string)$row['description']) ?></td>
                    <td><?= htmlspecialchars((string)$row['qty_ordered']) ?></td>
                    <td><?= htmlspecialchars((string)$row['qty_supplied']) ?></td>
                    <td class="danger"><?= htmlspecialchars((string)$row['qty_not_supplied']) ?></td>
                    <td><?= htmlspecialchars((string)$row['not_supplied_reason']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<h2>Orders Still Not Sent</h2>

<?php if (!$notSent): ?>
    <table>
        <tr>
            <td class="empty">No orders still not sent for this date range.</td>
        </tr>
    </table>
<?php else: ?>
    <?php foreach ($notSent as $order): ?>
        <div class="order-box">
            <h3>
                Invoice <?= htmlspecialchars((string)$order['invoice_no']) ?>
                - <?= htmlspecialchars((string)$order['customer_name']) ?>
            </h3>

            <table>
                <tr>
                    <th>Order Date</th>
                    <th>Delivery Date</th>
                    <th>Status</th>
                    <th>Reason</th>
                </tr>
                <tr>
                    <td><?= htmlspecialchars((string)$order['order_date']) ?></td>
                    <td><?= htmlspecialchars((string)($order['delivery_date'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)$order['status']) ?></td>
                    <td><?= htmlspecialchars((string)($order['status_reason'] ?? 'No reason added')) ?></td>
                </tr>
            </table>

            <h4>Order Items</h4>

            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Description</th>
                        <th>Order Qty</th>
                        <th>Total Qty</th>
                        <th>Qty Supplied</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notSentItems[$order['id']])): ?>
                        <tr>
                            <td colspan="5" class="empty">No items found for this order.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($notSentItems[$order['id']] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$item['sku_code']) ?></td>
                                <td><?= htmlspecialchars((string)$item['description']) ?></td>
                                <td><?= htmlspecialchars((string)$item['order_qty']) ?></td>
                                <td><?= htmlspecialchars((string)$item['total_qty']) ?></td>
                                <td><?= htmlspecialchars((string)$item['qty_supplied']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>