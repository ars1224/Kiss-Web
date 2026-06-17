<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/notification_helper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = (int)($input['id'] ?? 0);
    $status = (string)($input['status'] ?? '');

    $allowed = ['pending', 'ongoing', 'booking', 'waiting_packing_slip', 'sent'];

    if ($id <= 0) {
        throw new Exception('Invalid order ID.');
    }

    if (!in_array($status, $allowed, true)) {
        throw new Exception('Invalid status.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = :status
        WHERE id = :id
    ");

    $stmt->execute([
        ':status' => $status,
        ':id' => $id
    ]);

    if ($status === 'sent') {

    createNotification(
        $pdo,
        null,
        'outwards',
        'sent',
        'Order Sent',
        'An order has been marked as sent.',
        'orders_list.php'
    );
}

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}