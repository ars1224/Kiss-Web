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
            COALESCE(SUM(oi.order_qty), 0) AS total_qty_ordered,

            COALESCE(SUM(
                CASE
                    WHEN UPPER(TRIM(oi.qty_supplied)) = 'NO STOCK' THEN 0
                    WHEN oi.qty_supplied REGEXP '^[0-9]+$' THEN CAST(oi.qty_supplied AS UNSIGNED)
                    ELSE 0
                END
            ), 0) AS total_qty_supplied,

            COALESCE(SUM(
                GREATEST(
                    oi.order_qty -
                    CASE
                        WHEN UPPER(TRIM(oi.qty_supplied)) = 'NO STOCK' THEN 0
                        WHEN oi.qty_supplied REGEXP '^[0-9]+$' THEN CAST(oi.qty_supplied AS UNSIGNED)
                        ELSE 0
                    END,
                    0
                )
            ), 0) AS total_qty_not_supplied

        FROM order_items oi
        INNER JOIN orders o ON oi.order_id = o.id
        WHERE DATE(o.completed_at) BETWEEN :from_date AND :to_date
    ";

    $qtyStmt = $pdo->prepare($qtySql);
    $qtyStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $qty = $qtyStmt->fetch(PDO::FETCH_ASSOC);

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
    WHERE DATE(o.completed_at) BETWEEN :from_date AND :to_date
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