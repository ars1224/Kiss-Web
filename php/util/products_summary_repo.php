<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/inventory_helper.php';

function getProductsSummary(PDO $pdo): array
{
    if (strtolower(trim(currentUserRole())) === 'admin') {
        $sql = "
            SELECT *
            FROM (
                SELECT
                    'Products' AS InventoryType,
                    x.SKU_Code,
                    COALESCE(MAX(p.ProductDescription), '') AS ProductDescription,
                    COALESCE(SUM(pl.TotalQty), 0) AS TotalUnitQty,
                    COALESCE(MAX(ps.status), 'Continue') AS Status
                FROM (
                    SELECT SKU_Code FROM products
                    UNION
                    SELECT SKU_Code FROM productlocation
                ) x
                LEFT JOIN products p
                    ON TRIM(UPPER(p.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                LEFT JOIN productlocation pl
                    ON TRIM(UPPER(pl.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                LEFT JOIN product_status ps
                    ON TRIM(UPPER(ps.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                WHERE TRIM(x.SKU_Code) <> ''
                GROUP BY x.SKU_Code

                UNION ALL

                SELECT
                    'Components' AS InventoryType,
                    x.SKU_Code,
                    COALESCE(MAX(c.ProductDescription), '') AS ProductDescription,
                    COALESCE(SUM(cl.TotalQty), 0) AS TotalUnitQty,
                    COALESCE(MAX(cs.status), 'Continue') AS Status
                FROM (
                    SELECT SKU_Code FROM components
                    UNION
                    SELECT SKU_Code FROM componentlocation
                ) x
                LEFT JOIN components c
                    ON TRIM(UPPER(c.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                LEFT JOIN componentlocation cl
                    ON TRIM(UPPER(cl.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                LEFT JOIN component_status cs
                    ON TRIM(UPPER(cs.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                WHERE TRIM(x.SKU_Code) <> ''
                GROUP BY x.SKU_Code

                UNION ALL

                SELECT
                    'Raw Materials' AS InventoryType,
                    x.SKU_Code,
                    COALESCE(MAX(r.ProductDescription), '') AS ProductDescription,
                    COALESCE(SUM(rl.TotalQty), 0) AS TotalUnitQty,
                    COALESCE(MAX(rs.status), 'Continue') AS Status
                FROM (
                    SELECT SKU_Code FROM raw_materials
                    UNION
                    SELECT SKU_Code FROM rmlocation
                ) x
                LEFT JOIN raw_materials r
                    ON TRIM(UPPER(r.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                LEFT JOIN rmlocation rl
                    ON TRIM(UPPER(rl.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                LEFT JOIN rm_status rs
                    ON TRIM(UPPER(rs.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
                WHERE TRIM(x.SKU_Code) <> ''
                GROUP BY x.SKU_Code
            ) all_summary
            ORDER BY InventoryType, SKU_Code
        ";

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    $master = masterTable();
    $location = inventoryTable();
    $status = statusTable();

    $sql = "
        SELECT
            x.SKU_Code,
            COALESCE(MAX(m.ProductDescription), '') AS ProductDescription,
            COALESCE(SUM(l.TotalQty), 0) AS TotalUnitQty,
            COALESCE(MAX(s.status), 'Continue') AS Status
        FROM (
            SELECT SKU_Code FROM {$master}
            UNION
            SELECT SKU_Code FROM {$location}
        ) x
        LEFT JOIN {$master} m
            ON TRIM(UPPER(m.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
        LEFT JOIN {$location} l
            ON TRIM(UPPER(l.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
        LEFT JOIN {$status} s
            ON TRIM(UPPER(s.SKU_Code)) = TRIM(UPPER(x.SKU_Code))
        WHERE TRIM(x.SKU_Code) <> ''
        GROUP BY x.SKU_Code
        ORDER BY x.SKU_Code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}