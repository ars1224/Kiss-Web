<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/notification_helper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = (int)($input['id'] ?? 0);
    $courierName = trim((string)($input['courier_name'] ?? ''));
    $courierReference = trim((string)($input['courier_reference'] ?? ''));

    if ($id <= 0) {
        throw new Exception('Invalid order ID.');
    }

    if ($courierName === '') {
        throw new Exception('Courier is required.');
    }

    if ($courierReference === '') {
        throw new Exception('Courier reference/code is required.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'waiting_packing_slip',
            courier_name = :courier_name,
            courier_reference = :courier_reference,
            courier_booked_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        ':courier_name' => $courierName,
        ':courier_reference' => $courierReference,
        ':id' => $id
    ]);

    $stmt = $pdo->prepare("
        SELECT invoice_no
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    $invoiceNo = $order['invoice_no'] ?? $id;

    createNotification(
        $pdo,
        null,
        'outwards',
        'courier',
        'Waiting for Packing Slip',
        "Order #{$invoiceNo} has been booked with {$courierName} and is waiting for packing slip.",
        'orders_list.php?status=waiting&search=' . urlencode($invoiceNo)
    );

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}