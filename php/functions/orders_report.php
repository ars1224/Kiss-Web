<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

$pdo = db();

try {
    $fromDate = $_GET['from_date'] ?? date('Y-m-d');
    $toDate = $_GET['to_date'] ?? date('Y-m-d');

    if ($fromDate === '' || $toDate === '') {
        throw new Exception('Please select from date and to date.');
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
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

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
    $qty = $qtyStmt->fetch(PDO::FETCH_ASSOC);

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

    $notSuppliedStmt = $pdo->prepare($notSuppliedSql);
    $notSuppliedStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $notSupplied = $notSuppliedStmt->fetchAll(PDO::FETCH_ASSOC);

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
    WHERE status IS NULL
       OR TRIM(LOWER(status)) != 'sent'
    ORDER BY order_date DESC, invoice_no ASC
";

$notSentStmt = $pdo->prepare($notSentSql);
$notSentStmt->execute();

$notSent = $notSentStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_orders' => (int)($summary['total_orders'] ?? 0),
            'sent_orders' => (int)($summary['sent_orders'] ?? 0),
            'not_sent_orders' => (int)($summary['not_sent_orders'] ?? 0),
            'total_qty_ordered' => (int)($qty['total_qty_ordered'] ?? 0),
            'total_qty_supplied' => (int)($qty['total_qty_supplied'] ?? 0),
            'total_qty_not_supplied' => (int)($qty['total_qty_not_supplied'] ?? 0)
        ],
        'not_supplied' => $notSupplied,
        'not_sent' => $notSent
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
