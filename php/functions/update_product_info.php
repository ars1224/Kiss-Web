<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

try {
    $pdo = db();

    $input = json_decode(file_get_contents('php://input'), true);

    $sku = trim((string)($input['sku'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $status = trim((string)($input['status'] ?? ''));

    $allowedStatuses = [
        'Continue',
        'Keep Producing but No PMs again',
        'Keep selling until OOS',
        'Discontinued'
    ];

    if ($sku === '') {
        throw new Exception('SKU is required.');
    }

    if (!in_array($status, $allowedStatuses, true)) {
        throw new Exception('Invalid status.');
    }

    $pdo->beginTransaction();

    $productSql = "
        INSERT INTO products (SKU_Code, ProductDescription)
        VALUES (:sku, :description)
        ON DUPLICATE KEY UPDATE 
            ProductDescription = VALUES(ProductDescription)
    ";

    $stmt = $pdo->prepare($productSql);
    $stmt->execute([
        ':sku' => $sku,
        ':description' => $description
    ]);

    $statusSql = "
        INSERT INTO product_status (SKU_Code, status)
        VALUES (:sku, :status)
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP
    ";

    $stmt = $pdo->prepare($statusSql);
    $stmt->execute([
        ':sku' => $sku,
        ':status' => $status
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Product updated.'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}