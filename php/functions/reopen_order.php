<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../util/notification_helper.php';

try {
    if (!isLoggedIn()) {
        http_response_code(401);
        throw new Exception('Your session has expired. Please sign in again.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['id'] ?? 0);

    if ($orderId <= 0) {
        throw new Exception('Invalid order ID.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, invoice_no, status
        FROM orders
        WHERE id = :id
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    if (!in_array((string)$order['status'], ['booking', 'waiting_packing_slip'], true)) {
        throw new Exception('Only an order in Booking or Waiting Slip can be reopened.');
    }

    $reopenedBy = trim((string)(
        $_SESSION['full_name']
        ?? $_SESSION['name']
        ?? $_SESSION['username']
        ?? 'Unknown user'
    ));

    if ($reopenedBy === '') {
        $reopenedBy = 'Unknown user';
    }

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'ongoing',
            picker_name = :picker_name,
            checker_name = NULL,
            checked_by = NULL,
            packed_by = NULL,
            checked_at = NULL,
            courier = NULL,
            courier_name = NULL,
            courier_reference = NULL,
            courier_booked_at = NULL,
            completed_at = NULL,
            sent_at = NULL,
            status_reason = NULL
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $orderId,
        ':picker_name' => $reopenedBy
    ]);

    $invoiceNo = trim((string)($order['invoice_no'] ?? '')) ?: (string)$orderId;

    createNotification(
        $pdo,
        null,
        'outwards',
        'order_reopened',
        'Order Reopened',
        "Order #{$invoiceNo} was reopened by {$reopenedBy} so additional items can be added.",
        'orders.php?edit=' . $orderId . '&reopened=1'
    );

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'status' => 'ongoing',
        'edit_url' => 'orders.php?edit=' . $orderId . '&reopened=1'
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
