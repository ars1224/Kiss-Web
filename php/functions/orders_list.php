<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

try {
    $pdo = db();

    $stmt = $pdo->query("
       SELECT
            o.id,
            o.invoice_no,
            o.order_date,
            o.customer_name,
            o.order_number,
            o.status,
            o.packing_slip_file,
            o.picker_name,
            o.checker_name,
            o.courier_name,
            COALESCE(sku_index.sku_codes, '') AS sku_codes
        FROM orders o
        LEFT JOIN (
            SELECT
                order_id,
                GROUP_CONCAT(
                    DISTINCT TRIM(sku_code)
                    ORDER BY TRIM(sku_code)
                    SEPARATOR ' '
                ) AS sku_codes
            FROM order_items
            WHERE TRIM(COALESCE(sku_code, '')) != ''
            GROUP BY order_id
        ) sku_index ON sku_index.order_id = o.id
        ORDER BY o.id DESC
    ");

    echo json_encode([
        'success' => true,
        'orders' => $stmt->fetchAll()
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}