<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../conn/db.php';

try {
    if (!isLoggedIn()) {
        http_response_code(401);
        throw new Exception('Your session has expired. Please sign in again.');
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new Exception('Only POST requests are allowed.');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        throw new Exception('Invalid request payload.');
    }

    $orderId = (int)($input['id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));

    if ($orderId <= 0) {
        http_response_code(400);
        throw new Exception('Invalid order ID.');
    }

    $reasonLength = function_exists('mb_strlen')
        ? mb_strlen($reason, 'UTF-8')
        : strlen($reason);

    if ($reasonLength > 255) {
        http_response_code(400);
        throw new Exception('Reason must be 255 characters or fewer.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT status
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        throw new Exception('Order not found.');
    }

    $currentStatus = strtolower(trim((string)($order['status'] ?? '')));

    if ($currentStatus === 'sent') {
        http_response_code(409);
        throw new Exception('A reason cannot be changed after the order has been sent.');
    }

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status_reason = NULLIF(:reason, '')
        WHERE id = :id
    ");
    $stmt->execute([
        ':reason' => $reason,
        ':id' => $orderId
    ]);

    echo json_encode([
        'success' => true,
        'message' => $reason === '' ? 'Reason removed.' : 'Reason saved.',
        'status_reason' => $reason
    ]);
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
