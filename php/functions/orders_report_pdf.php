<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

function reportDateParam(string $name): string
{
    $value = trim((string)($_GET[$name] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new InvalidArgumentException('Invalid date range.');
    }

    return $value;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function pdfQty(mixed $value): string
{
    if ($value === null || $value === '') {
        return '0';
    }

    if (!is_numeric($value)) {
        return h($value);
    }

    $number = (float)$value;

    if ((float)(int)$number === $number) {
        return number_format($number, 0, '.', ',');
    }

    return rtrim(rtrim(number_format($number, 4, '.', ','), '0'), '.');
}

function pdfDate(mixed $value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('Y-m-d H:i', $timestamp) : h($value);
}

try {
    $pdo = db();
    $fromDate = reportDateParam('from_date');
    $toDate = reportDateParam('to_date');

    if ($fromDate > $toDate) {
        throw new InvalidArgumentException('From date must be before to date.');
    }

    if (!class_exists('TCPDF')) {
        throw new RuntimeException('PDF generator is not available.');
    }

    $orderedQtyValue = "TRIM(COALESCE(NULLIF(oi.total_qty, ''), NULLIF(oi.order_qty, ''), ''))";
    $suppliedQtyValue = "TRIM(COALESCE(NULLIF(oi.total_qty_supplied, ''), NULLIF(oi.qty_supplied, ''), ''))";
    $orderedQtyExpr = "
        CASE
            WHEN {$orderedQtyValue} REGEXP '^[0-9]+([.][0-9]+)?$'
            THEN CAST({$orderedQtyValue} AS DECIMAL(12,4))
            ELSE 0
        END
    ";
    $suppliedQtyExpr = "
        CASE
            WHEN UPPER({$suppliedQtyValue}) = 'NO STOCK' THEN 0
            WHEN {$suppliedQtyValue} REGEXP '^[0-9]+([.][0-9]+)?$'
            THEN CAST({$suppliedQtyValue} AS DECIMAL(12,4))
            ELSE 0
        END
    ";

    $summarySql = "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN LOWER(status) = 'sent' THEN 1 ELSE 0 END) AS sent_orders,
            SUM(CASE WHEN LOWER(status) != 'sent' THEN 1 ELSE 0 END) AS not_sent_orders
        FROM orders
        WHERE DATE(completed_at) BETWEEN :from_date AND :to_date
    ";

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $qtySql = "
        SELECT
            COALESCE(SUM(grouped.qty_ordered), 0) AS total_qty_ordered,
            COALESCE(SUM(grouped.qty_supplied), 0) AS total_qty_supplied,
            COALESCE(SUM(GREATEST(grouped.qty_ordered - grouped.qty_supplied, 0)), 0) AS total_qty_not_supplied
        FROM (
            SELECT
                o.id AS order_id,
                oi.sku_code,
                oi.description,
                MAX({$orderedQtyExpr}) AS qty_ordered,
                SUM({$suppliedQtyExpr}) AS qty_supplied
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.completed_at) BETWEEN :from_date AND :to_date
            GROUP BY o.id, oi.sku_code, oi.description
        ) grouped
    ";

    $qtyStmt = $pdo->prepare($qtySql);
    $qtyStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $qty = $qtyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $notSuppliedSql = "
        SELECT
            grouped.invoice_no,
            grouped.completed_at,
            grouped.customer_name,
            grouped.sku_code,
            grouped.description,
            grouped.qty_ordered,
            grouped.qty_supplied,
            GREATEST(grouped.qty_ordered - grouped.qty_supplied, 0) AS qty_not_supplied,
            CASE
                WHEN grouped.qty_supplied <= 0 THEN 'NO STOCK'
                WHEN grouped.not_supplied_reasons IS NOT NULL AND grouped.not_supplied_reasons != '' THEN grouped.not_supplied_reasons
                ELSE 'SHORT SUPPLY'
            END AS not_supplied_reason
        FROM (
            SELECT
                o.id AS order_id,
                o.invoice_no,
                o.completed_at,
                o.customer_name,
                oi.sku_code,
                oi.description,
                MAX({$orderedQtyExpr}) AS qty_ordered,
                SUM({$suppliedQtyExpr}) AS qty_supplied,
                NULLIF(GROUP_CONCAT(DISTINCT NULLIF(TRIM(COALESCE(oi.not_supplied_reason, '')), '') SEPARATOR ', '), '') AS not_supplied_reasons
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.completed_at) BETWEEN :from_date AND :to_date
            AND o.completed_at IS NOT NULL
            AND LOWER(o.status) = 'sent'
            GROUP BY o.id, o.invoice_no, o.completed_at, o.customer_name, oi.sku_code, oi.description
        ) grouped
        WHERE grouped.qty_ordered > grouped.qty_supplied
        ORDER BY grouped.completed_at DESC, grouped.invoice_no ASC, grouped.sku_code ASC
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
        AND (status IS NULL OR TRIM(LOWER(status)) != 'sent')
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
            AND (status IS NULL OR TRIM(LOWER(status)) != 'sent')
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
        $notSentItems[(int)$item['order_id']][] = $item;
    }

    $notSuppliedRows = '';

    if (!$notSupplied) {
        $notSuppliedRows = '<tr><td colspan="9" class="empty">No products not supplied for this date range.</td></tr>';
    } else {
        foreach ($notSupplied as $row) {
            $notSuppliedRows .= '
                <tr>
                    <td width="8%">' . h($row['invoice_no']) . '</td>
                    <td width="12%">' . pdfDate($row['completed_at']) . '</td>
                    <td width="15%">' . h($row['customer_name']) . '</td>
                    <td width="7%">' . h($row['sku_code']) . '</td>
                    <td width="30%">' . h($row['description']) . '</td>
                    <td width="7%" align="right">' . pdfQty($row['qty_ordered']) . '</td>
                    <td width="7%" align="right">' . pdfQty($row['qty_supplied']) . '</td>
                    <td width="6%" align="right" class="danger">' . pdfQty($row['qty_not_supplied']) . '</td>
                    <td width="8%">' . h($row['not_supplied_reason']) . '</td>
                </tr>
            ';
        }
    }

    $notSentHtml = '';

    if (!$notSent) {
        $notSentHtml = '<p class="empty-box">No orders still not sent for this date range.</p>';
    } else {
        foreach ($notSent as $order) {
            $orderId = (int)$order['id'];
            $itemRows = '';

            if (empty($notSentItems[$orderId])) {
                $itemRows = '<tr><td colspan="5" class="empty">No items found for this order.</td></tr>';
            } else {
                foreach ($notSentItems[$orderId] as $item) {
                    $itemRows .= '
                        <tr>
                            <td width="14%">' . h($item['sku_code']) . '</td>
                            <td width="46%">' . h($item['description']) . '</td>
                            <td width="13%" align="right">' . pdfQty($item['order_qty']) . '</td>
                            <td width="13%" align="right">' . pdfQty($item['total_qty']) . '</td>
                            <td width="14%" align="right">' . h($item['qty_supplied']) . '</td>
                        </tr>
                    ';
                }
            }

            $notSentHtml .= '
                <div class="order-box">
                    <h3>Invoice ' . h($order['invoice_no']) . ' - ' . h($order['customer_name']) . '</h3>
                    <table cellpadding="6" cellspacing="0">
                        <tr>
                            <th width="18%">Order Date</th>
                            <th width="18%">Delivery Date</th>
                            <th width="16%">Status</th>
                            <th width="48%">Reason</th>
                        </tr>
                        <tr>
                            <td width="18%">' . h($order['order_date']) . '</td>
                            <td width="18%">' . h($order['delivery_date'] ?? '') . '</td>
                            <td width="16%">' . h($order['status'] ?? 'Pending') . '</td>
                            <td width="48%">' . h($order['status_reason'] ?? 'No reason added') . '</td>
                        </tr>
                    </table>
                    <h4>Order Items</h4>
                    <table cellpadding="6" cellspacing="0">
                        <thead>
                            <tr>
                                <th width="14%">SKU</th>
                                <th width="46%">Description</th>
                                <th width="13%" align="right">Order Qty</th>
                                <th width="13%" align="right">Total Qty</th>
                                <th width="14%" align="right">Qty Supplied</th>
                            </tr>
                        </thead>
                        <tbody>' . $itemRows . '</tbody>
                    </table>
                </div>
            ';
        }
    }

    $html = '
        <style>
            body { color: #0f172a; font-family: helvetica, sans-serif; }
            h1 { margin: 0; font-size: 22px; color: #0f172a; font-weight: bold; }
            h2 { margin: 18px 0 8px; font-size: 14px; color: #0f172a; border-bottom: 1.5px solid #0f766e; padding-bottom: 5px; font-weight: bold; }
            h3 { margin: 0 0 7px; font-size: 12px; color: #0f172a; font-weight: bold; }
            h4 { margin: 10px 0 5px; font-size: 10px; color: #334155; font-weight: bold; }
            .kicker { color: #0f766e; font-size: 8px; text-transform: uppercase; font-weight: bold; letter-spacing: 0.3px; }
            .subtitle { margin: 4px 0 10px; color: #475569; font-size: 9.5px; }
            .generated { color: #64748b; font-size: 8.5px; text-align: right; }
            .summary { width: 100%; margin: 10px 0 14px; border-collapse: collapse; }
            .summary td { border: 1px solid #dbe3ef; padding: 8px 7px; background-color: #f8fafc; text-align: center; }
            .summary .label { color: #64748b; font-size: 7.5px; text-transform: uppercase; font-weight: bold; }
            .summary .value { color: #0f172a; font-size: 14px; font-weight: bold; line-height: 1.35; }
            table { width: 100%; border-collapse: collapse; font-size: 8.6px; }
            th { background-color: #0f766e; color: #ffffff; padding: 7px 6px; font-weight: bold; vertical-align: middle; }
            td { border: 1px solid #dbe3ef; padding: 7px 6px; vertical-align: top; line-height: 1.4; }
            .num { text-align: right; }
            .danger { color: #b91c1c; font-weight: bold; }
            .empty { text-align: center; color: #64748b; padding: 13px; }
            .empty-box { border: 1px solid #dbe3ef; padding: 13px; color: #64748b; text-align: center; }
            .order-box { margin-top: 12px; padding: 9px; border: 1px solid #dbe3ef; page-break-inside: avoid; background-color: #ffffff; }
        </style>

        <div class="kicker">KISS-Web Orders</div>
        <h1>Orders Report</h1>
        <div class="subtitle">Date Range: ' . h($fromDate) . ' to ' . h($toDate) . '</div>
        <div class="generated">Generated: ' . h(date('Y-m-d H:i')) . '</div>

        <table class="summary" cellpadding="8" cellspacing="0">
            <tr>
                <td width="16.66%"><div class="label">Total Orders</div><div class="value">' . pdfQty($summary['total_orders'] ?? 0) . '</div></td>
                <td width="16.66%"><div class="label">Sent</div><div class="value">' . pdfQty($summary['sent_orders'] ?? 0) . '</div></td>
                <td width="16.66%"><div class="label">Not Sent</div><div class="value">' . pdfQty(count($notSent)) . '</div></td>
                <td width="16.66%"><div class="label">Qty Ordered</div><div class="value">' . pdfQty($qty['total_qty_ordered'] ?? 0) . '</div></td>
                <td width="16.66%"><div class="label">Qty Supplied</div><div class="value">' . pdfQty($qty['total_qty_supplied'] ?? 0) . '</div></td>
                <td width="16.7%"><div class="label">Qty Not Supplied</div><div class="value">' . pdfQty($qty['total_qty_not_supplied'] ?? 0) . '</div></td>
            </tr>
        </table>

        <h2>Products Not Supplied</h2>
        <table cellpadding="6" cellspacing="0">
            <thead>
                <tr>
                    <th width="8%">Invoice</th>
                    <th width="12%">Completed</th>
                    <th width="15%">Customer</th>
                    <th width="7%">SKU</th>
                    <th width="30%">Description</th>
                    <th width="7%" align="right">Ordered</th>
                    <th width="7%" align="right">Supplied</th>
                    <th width="6%" align="right">Short</th>
                    <th width="8%">Reason</th>
                </tr>
            </thead>
            <tbody>' . $notSuppliedRows . '</tbody>
        </table>

        <h2>Orders Still Not Sent</h2>
        ' . $notSentHtml . '
    ';

    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('KISS-Web');
    $pdf->SetAuthor('KISS-Web');
    $pdf->SetTitle('Orders Report');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(true, 9);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = 'orders-report-' . $fromDate . '-to-' . $toDate . '.pdf';
    $pdf->Output($filename, 'D');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF download failed: ' . $e->getMessage();
}
