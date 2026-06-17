<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/inventory_helper.php';

$table = inventoryTable();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('Invalid order ID.');
    }

    $pdo = db();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = :id");
    $stmt->execute([':id' => $id]);

    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = :id");
    $stmt->execute([':id' => $id]);

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}