<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

try {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('Invalid order ID.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new Exception('Order not found.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id = :id
        ORDER BY id ASC
    ");
    $stmt->execute([':id' => $id]);
    $items = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}