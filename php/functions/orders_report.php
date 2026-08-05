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

    $finalStatusExpr = "LOWER(TRIM(COALESCE(status, '')))";
    $orderFinalStatusExpr = "LOWER(TRIM(COALESCE(o.status, '')))";
    $reportDateExpr = "CASE WHEN {$finalStatusExpr} IN ('not_sent', 'not sent') THEN COALESCE(completed_at, checked_at, order_date) ELSE completed_at END";
    $orderReportDateExpr = "CASE WHEN {$orderFinalStatusExpr} IN ('not_sent', 'not sent') THEN COALESCE(o.completed_at, o.checked_at, o.order_date) ELSE o.completed_at END";

    $summarySql = "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN {$finalStatusExpr} = 'sent' THEN 1 ELSE 0 END) AS sent_orders,
            SUM(CASE WHEN {$finalStatusExpr} IN ('not_sent', 'not sent') THEN 1 ELSE 0 END) AS not_sent_orders
        FROM orders
        WHERE {$finalStatusExpr} IN ('sent', 'not_sent', 'not sent')
        AND DATE({$reportDateExpr}) BETWEEN :from_date AND :to_date
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
            WHERE DATE({$orderReportDateExpr}) BETWEEN :from_date AND :to_date
            AND {$orderReportDateExpr} IS NOT NULL
            AND {$orderFinalStatusExpr} IN ('sent', 'not_sent', 'not sent')
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
                {$orderReportDateExpr} AS completed_at,
                o.customer_name,
                oi.sku_code,
                oi.description,
                MAX({$orderedQtyExpr}) AS qty_ordered,
                SUM({$suppliedQtyExpr}) AS qty_supplied,
                NULLIF(GROUP_CONCAT(DISTINCT NULLIF(TRIM(COALESCE(oi.not_supplied_reason, '')), '') SEPARATOR ', '), '') AS not_supplied_reasons
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE({$orderReportDateExpr}) BETWEEN :from_date AND :to_date
            AND {$orderReportDateExpr} IS NOT NULL
            AND {$orderFinalStatusExpr} IN ('sent', 'not_sent', 'not sent')
            GROUP BY o.id, o.invoice_no, {$orderReportDateExpr}, o.customer_name, oi.sku_code, oi.description
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

    $finalNotSentSql = "
        SELECT
            id,
            invoice_no,
            customer_name,
            order_date,
            delivery_date,
            status_reason,
            {$reportDateExpr} AS report_date
        FROM orders
        WHERE {$finalStatusExpr} IN ('not_sent', 'not sent')
        AND DATE({$reportDateExpr}) BETWEEN :from_date AND :to_date
        ORDER BY report_date DESC, invoice_no ASC
    ";

    $finalNotSentStmt = $pdo->prepare($finalNotSentSql);
    $finalNotSentStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $finalNotSent = $finalNotSentStmt->fetchAll(PDO::FETCH_ASSOC);

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
           OR TRIM(LOWER(status)) NOT IN ('sent', 'not_sent', 'not sent')
        ORDER BY order_date DESC, invoice_no ASC
    ";

    $notSentStmt = $pdo->prepare($notSentSql);
    $notSentStmt->execute();
    $notSent = $notSentStmt->fetchAll(PDO::FETCH_ASSOC);

    $topSellersSql = "
        SELECT
            grouped.sku_code,
            MAX(grouped.description) AS description,
            ROUND(SUM(grouped.qty_supplied), 4) AS total_units
        FROM (
            SELECT
                o.id AS order_id,
                oi.sku_code,
                oi.description,
                SUM({$suppliedQtyExpr}) AS qty_supplied
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE({$orderReportDateExpr}) BETWEEN :from_date AND :to_date
            AND {$orderReportDateExpr} IS NOT NULL
            AND {$orderFinalStatusExpr} IN ('sent', 'not_sent', 'not sent')
            AND TRIM(COALESCE(oi.sku_code, '')) != ''
            GROUP BY o.id, oi.sku_code, oi.description
        ) grouped
        WHERE grouped.qty_supplied > 0
        GROUP BY grouped.sku_code
        ORDER BY total_units DESC, grouped.sku_code ASC
        LIMIT 10
    ";

    $topSellersStmt = $pdo->prepare($topSellersSql);
    $topSellersStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $topSellersRows = $topSellersStmt->fetchAll(PDO::FETCH_ASSOC);

    $mostOrderedSql = "
        SELECT
            grouped.sku_code,
            MAX(grouped.description) AS description,
            ROUND(SUM(grouped.qty_ordered), 4) AS total_units
        FROM (
            SELECT
                o.id AS order_id,
                oi.sku_code,
                oi.description,
                MAX({$orderedQtyExpr}) AS qty_ordered
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE({$orderReportDateExpr}) BETWEEN :from_date AND :to_date
            AND {$orderReportDateExpr} IS NOT NULL
            AND {$orderFinalStatusExpr} IN ('sent', 'not_sent', 'not sent')
            AND TRIM(COALESCE(oi.sku_code, '')) != ''
            GROUP BY o.id, oi.sku_code, oi.description
        ) grouped
        WHERE grouped.qty_ordered > 0
        GROUP BY grouped.sku_code
        ORDER BY total_units DESC, grouped.sku_code ASC
        LIMIT 10
    ";

    $mostOrderedStmt = $pdo->prepare($mostOrderedSql);
    $mostOrderedStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $mostOrderedRows = $mostOrderedStmt->fetchAll(PDO::FETCH_ASSOC);

    $monthlyUnitsSql = "
        SELECT
            grouped.month_key,
            grouped.month_label,
            ROUND(SUM(grouped.qty_supplied), 4) AS total_units
        FROM (
            SELECT
                DATE_FORMAT({$orderReportDateExpr}, '%Y-%m') AS month_key,
                DATE_FORMAT({$orderReportDateExpr}, '%b %Y') AS month_label,
                o.id AS order_id,
                oi.sku_code,
                oi.description,
                SUM({$suppliedQtyExpr}) AS qty_supplied
            FROM order_items oi
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE DATE({$orderReportDateExpr}) BETWEEN :from_date AND :to_date
            AND {$orderReportDateExpr} IS NOT NULL
            AND {$orderFinalStatusExpr} IN ('sent', 'not_sent', 'not sent')
            GROUP BY month_key, month_label, o.id, oi.sku_code, oi.description
        ) grouped
        GROUP BY grouped.month_key, grouped.month_label
        HAVING total_units > 0
        ORDER BY grouped.month_key ASC
    ";

    $monthlyUnitsStmt = $pdo->prepare($monthlyUnitsSql);
    $monthlyUnitsStmt->execute([
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $monthlyUnitsRows = $monthlyUnitsStmt->fetchAll(PDO::FETCH_ASSOC);

    $topSellers = array_map(static function (array $row): array {
        return [
            'sku_code' => (string)($row['sku_code'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'total_units' => (float)($row['total_units'] ?? 0)
        ];
    }, $topSellersRows);

    $mostOrdered = array_map(static function (array $row): array {
        return [
            'sku_code' => (string)($row['sku_code'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'total_units' => (float)($row['total_units'] ?? 0)
        ];
    }, $mostOrderedRows);

    $monthlyUnits = array_map(static function (array $row): array {
        return [
            'month_key' => (string)($row['month_key'] ?? ''),
            'month_label' => (string)($row['month_label'] ?? ''),
            'total_units' => (float)($row['total_units'] ?? 0)
        ];
    }, $monthlyUnitsRows);

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
        'final_not_sent' => $finalNotSent,
        'not_sent' => $notSent,
        'charts' => [
            'top_sellers' => $topSellers,
            'most_ordered' => $mostOrdered,
            'monthly_units_out' => $monthlyUnits
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}