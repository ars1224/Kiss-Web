<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

require_once __DIR__ . '/../util/inventory_helper.php';

$masterTable = masterTable();
$locationTable = inventoryTable();

if ($masterTable === 'all' || $locationTable === 'all') {
    $masterTable = 'products';
    $locationTable = 'productlocation';
    $statusTable = 'product_status';
}
$statusTable = statusTable();

$requestedType = strtolower(trim($_GET['inventory'] ?? ''));

if (str_contains($requestedType, 'component')) {
    $masterTable = 'components';
    $locationTable = 'componentlocation';
    $statusTable = 'component_status';
} elseif (str_contains($requestedType, 'raw')) {
    $masterTable = 'raw_materials';
    $locationTable = 'rmlocation';
    $statusTable = 'rm_status';
} elseif (str_contains($requestedType, 'product')) {
    $masterTable = 'products';
    $locationTable = 'productlocation';
    $statusTable = 'product_status';
}

try {
    $sku = trim($_GET['sku'] ?? '');

    if ($sku === '') {
        throw new Exception('SKU is required.');
    }

    $pdo = db();

    $summarySql = "
    SELECT 
        x.SKU_Code,
        COALESCE(MAX(p.ProductDescription), '') AS ProductDescription,
        COALESCE(SUM(pl.TotalQty), 0) AS TotalQty,
        COALESCE(MAX(pl.QtyPerCtn), 0) AS QtyPerCtn,
        COALESCE(MAX(ps.status), 'Continue') AS Status
    FROM (
        SELECT SKU_Code FROM {$masterTable}
        UNION
        SELECT SKU_Code FROM {$locationTable}
    ) x
    LEFT JOIN {$masterTable} p
        ON TRIM(UPPER(p.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
    LEFT JOIN {$locationTable} pl 
        ON TRIM(UPPER(pl.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
    LEFT JOIN {$statusTable} ps
        ON TRIM(UPPER(ps.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
    WHERE TRIM(UPPER(x.SKU_Code)) = TRIM(UPPER(?))
    GROUP BY x.SKU_Code
";

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute([$sku]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$summary) {
        throw new Exception('Product not found.');
    }

    $locSql = "
        SELECT
            Location,
            BatchNo,
            ExpiryDate,
            TotalQty,
            QtyPerCtn,
            Comments
        FROM {$locationTable}
        WHERE TRIM(UPPER(SKU_Code)) = TRIM(UPPER(?))
        ORDER BY Location ASC, ExpiryDate ASC
    ";

    $locStmt = $pdo->prepare($locSql);
    $locStmt->execute([$sku]);

    $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'locations' => $locations
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}