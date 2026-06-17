<?php
declare(strict_types=1);

function getLowStockList(PDO $pdo, string $role): array
{
    $role = strtolower(trim($role));

    if ($role === 'admin') {
      $sql = "
    SELECT *
    FROM (
        SELECT 'products' AS InventoryType, pl.SKU_Code,
               COALESCE(MAX(p.ProductDescription), '') AS ProductDescription,
               COALESCE(MAX(pl.QtyPerCtn), 0) AS QtyPerCtn,
               SUM(pl.TotalQty) AS TotalQty
        FROM productlocation pl
        LEFT JOIN products p ON TRIM(UPPER(p.SKU_Code)) = TRIM(UPPER(pl.SKU_Code))
        GROUP BY pl.SKU_Code

        UNION ALL

        SELECT 'components' AS InventoryType, cl.SKU_Code,
               '' AS ProductDescription,
               COALESCE(MAX(cl.QtyPerCtn), 0) AS QtyPerCtn,
               SUM(cl.TotalQty) AS TotalQty
        FROM componentlocation cl
        GROUP BY cl.SKU_Code

        UNION ALL

        SELECT 'rm' AS InventoryType, rl.SKU_Code,
               '' AS ProductDescription,
               COALESCE(MAX(rl.QtyPerCtn), 0) AS QtyPerCtn,
               SUM(rl.TotalQty) AS TotalQty
        FROM rmlocation rl
        GROUP BY rl.SKU_Code
    ) x
    WHERE x.TotalQty > 0
      AND x.TotalQty <= 500
    ORDER BY x.TotalQty ASC
";
    } elseif ($role === 'inwards') {
        $sql = "
            SELECT 'components' AS InventoryType, cl.SKU_Code,
                   '' AS ProductDescription,
                   SUM(cl.TotalQty) AS TotalQty,
                   COALESCE(MAX(cl.QtyPerCtn), 0) AS QtyPerCtn
            FROM componentlocation cl
            GROUP BY cl.SKU_Code
HAVING TotalQty > 0 AND TotalQty <= 500
            ORDER BY TotalQty ASC
        ";
    } elseif ($role === 'rawmat') {
        $sql = "
            SELECT 'rm' AS InventoryType, rl.SKU_Code,
                   '' AS ProductDescription,
                   SUM(rl.TotalQty) AS TotalQty,
                   COALESCE(MAX(rl.QtyPerCtn), 0) AS QtyPerCtn
            FROM rmlocation rl
            GROUP BY rl.SKU_Code
HAVING TotalQty > 0 AND TotalQty <= 500
            ORDER BY TotalQty ASC
        ";
    } else {
        $sql = "
            SELECT 'products' AS InventoryType, pl.SKU_Code,
                   COALESCE(MAX(p.ProductDescription), '') AS ProductDescription,
                   SUM(pl.TotalQty) AS TotalQty,
                   COALESCE(MAX(pl.QtyPerCtn), 0) AS QtyPerCtn
            FROM productlocation pl
            LEFT JOIN products p ON TRIM(UPPER(p.SKU_Code)) = TRIM(UPPER(pl.SKU_Code))
            GROUP BY pl.SKU_Code
HAVING SUM(pl.TotalQty) > 0 AND SUM(pl.TotalQty) <= 500
            ORDER BY TotalQty ASC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}