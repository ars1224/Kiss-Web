<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../util/notification_helper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('Invalid order ID.');
    }

    $pickerName =
        $_SESSION['full_name']
        ?? $_SESSION['name']
        ?? $_SESSION['username']
        ?? 'Unknown';

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT invoice_no
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    $invoiceNo = (string)($order['invoice_no'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'ongoing',
            picker_name = :picker_name
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $id,
        ':picker_name' => $pickerName
    ]);

    createNotification(
        $pdo,
        null,
        'outwards',
        'picking',
        'Picking Started',
        "Order picking has started by {$pickerName} for Invoice # {$invoiceNo}.",
        'orders_list.php?status=ongoing&search=' . urlencode($invoiceNo)
    );

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}