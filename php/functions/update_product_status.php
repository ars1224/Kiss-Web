<?php
require_once __DIR__ . '/../conn/db.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$sku = trim($input['sku'] ?? '');
$status = trim($input['status'] ?? '');

$allowedStatuses = [
    'Continue',
    'Keep Producing but No PMs again',
    'Keep selling until OOS',
    'Discontinued'
];

if ($sku === '' || !in_array($status, $allowedStatuses, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid SKU or status.'
    ]);
    exit;
}

try {
    $sql = "
        INSERT INTO product_status (SKU_Code, status)
        VALUES (:sku, :status)
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sku' => $sku,
        ':status' => $status
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Status updated.'
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}