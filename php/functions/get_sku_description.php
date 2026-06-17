<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

require_once __DIR__ . '/../util/inventory_helper.php';

$masterTable = masterTable();

try {

    $sku = trim($_GET['sku'] ?? '');

    if ($sku === '') {
        echo json_encode([
            'success' => false,
            'description' => ''
        ]);
        exit;
    }

    $pdo = db();

   $stmt = $pdo->prepare("
    SELECT ProductDescription
    FROM {$masterTable}
    WHERE TRIM(UPPER(SKU_Code)) = TRIM(UPPER(?))
    LIMIT 1
");

    $stmt->execute([$sku]);

    $description = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'description' => $description ?: ''
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'success' => false,
        'description' => '',
        'message' => $e->getMessage()
    ]);
}