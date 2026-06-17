<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

try {
    $pdo = db();

    $stmt = $pdo->query("
       SELECT
            id,
            invoice_no,
            order_date,
            customer_name,
            order_number,
            status,
            packing_slip_file,
            picker_name,
            checker_name,
            courier_name
        FROM orders
        ORDER BY id DESC
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