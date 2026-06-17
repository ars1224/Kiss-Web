<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

require_once __DIR__ . '/../util/inventory_helper.php';

$masterTable = masterTable();

try {

    $pdo = db();

 $stmt = $pdo->query("
    SELECT DISTINCT
        TRIM(SKU_Code) AS SKU_Code,
        COALESCE(ProductDescription, '') AS ProductDescription
    FROM {$masterTable}
    WHERE TRIM(SKU_Code) <> ''
    ORDER BY SKU_Code ASC
");

    $rows = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => $rows
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}