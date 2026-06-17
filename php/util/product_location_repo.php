<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/inventory_helper.php';

function pl_table(): string
{
    return '`' . inventoryTable() . '`';
}

/**
 * Get all product-location rows, optionally filtered by a search string.
 *
 * @param string|null $search Search text for Location / SKU / Batch / Expiry
 * @return array
 */
function pl_all(?string $search = null): array
{
    $pdo = db();

    // =========================
    // ADMIN = ALL INVENTORIES
    // =========================
    if (inventoryTable() === 'all') {

        $baseSql = "
            SELECT 'Products' AS InventoryType, productlocation.*
            FROM productlocation

            UNION ALL

            SELECT 'Components' AS InventoryType, componentlocation.*
            FROM componentlocation

            UNION ALL

            SELECT 'Raw Materials' AS InventoryType, rmlocation.*
            FROM rmlocation
        ";

        if ($search !== null && $search !== '') {

            $like = '%' . $search . '%';

            $sql = "
                SELECT *
                FROM (
                    {$baseSql}
                ) AS all_locations
                WHERE Location   LIKE ?
                   OR SKU_Code   LIKE ?
                   OR BatchNo    LIKE ?
                   OR ExpiryDate LIKE ?
                   OR Comments   LIKE ?
                ORDER BY Location, SKU_Code, BatchNo, ExpiryDate, COALESCE(Comments,'')
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$like, $like, $like, $like, $like]);

        } else {

            $sql = "
                SELECT *
                FROM (
                    {$baseSql}
                ) AS all_locations
                ORDER BY Location, SKU_Code, BatchNo, ExpiryDate, COALESCE(Comments,'')
            ";

            $stmt = $pdo->query($sql);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================
    // NORMAL SINGLE INVENTORY
    // =========================

    if ($search !== null && $search !== '') {

        $like = '%' . $search . '%';

        $sql = "
            SELECT *
            FROM " . pl_table() . "
            WHERE Location   LIKE ?
               OR SKU_Code   LIKE ?
               OR BatchNo    LIKE ?
               OR ExpiryDate LIKE ?
               OR Comments   LIKE ?
            ORDER BY Location, SKU_Code, BatchNo, ExpiryDate, COALESCE(Comments,'')
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like, $like, $like, $like, $like]);

    } else {

        $sql = "
            SELECT *
            FROM " . pl_table() . "
            ORDER BY Location, SKU_Code, BatchNo, ExpiryDate, COALESCE(Comments,'')
        ";

        $stmt = $pdo->query($sql);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



/**
 * Updates a quantity only (simple adjustment)
 */
function pl_update_qty(int $id, int $qty): void
{
    $pdo = db();
    $sql = "UPDATE " . pl_table() . " SET TotalQty = ? WHERE EntryID = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$qty, $id]);
}

/**
 * Updates multiple fields — only fields passed will update.
 *
 * Example:
 *   pl_update($id, ['Location' => 'A1C', 'TotalQty' => 50])
 */
function pl_update(int $id, array $fields): void
{
    if (!$fields) return;

    $pdo  = db();
    $set  = [];
    $vals = [];

    foreach ($fields as $k => $v) {
        $set[]  = "`$k` = ?";
        $vals[] = $v;
    }
    $vals[] = $id;

    $sql  = "UPDATE " . pl_table() . " SET " . implode(', ', $set) . " WHERE EntryID = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
}

/**
 * Delete a row
 */
function pl_delete(int $id): void
{
    $pdo = db();
    $sql = "DELETE FROM " . pl_table() . " WHERE EntryID = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
}

/**
 * Find a single row by EntryID
 */
function pl_find(int $entryId, ?string $inventoryType = null): ?array
{
    $inventoryType = strtolower(trim((string)$inventoryType));

    if (str_contains($inventoryType, 'component')) {
        $table = 'componentlocation';
    } elseif (str_contains($inventoryType, 'raw')) {
        $table = 'rmlocation';
    } elseif (str_contains($inventoryType, 'product')) {
        $table = 'productlocation';
    } else {
        $table = trim(pl_table(), '`');

        if ($table === 'all') {
            return null;
        }
    }

    $sql = "SELECT * FROM {$table} WHERE EntryID = :id";
    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $entryId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
    