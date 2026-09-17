<?php
declare(strict_types=1);

/**
 * Persistent pallet identifiers for consolidated inventory rows.
 *
 * A pallet ID belongs to the inventory row. Quantity-only merges therefore
 * keep the existing ID. When a move merges one row into another, an alias is
 * recorded so an older printed label resolves to the surviving row.
 */

function palletInventoryTables(): array
{
    return [
        'productlocation' => ['prefix' => 'P', 'type' => 'products'],
        'componentlocation' => ['prefix' => 'C', 'type' => 'packaging'],
        'rmlocation' => ['prefix' => 'R', 'type' => 'rm'],
    ];
}

function palletTableConfig(string $table): array
{
    $tables = palletInventoryTables();

    if (!isset($tables[$table])) {
        throw new InvalidArgumentException('Unsupported inventory table for pallet ID.');
    }

    return $tables[$table];
}

function formatPalletId(string $table, int $entryId): string
{
    if ($entryId <= 0) {
        throw new InvalidArgumentException('A positive EntryID is required.');
    }

    $config = palletTableConfig($table);
    return sprintf('PLT-%s-%010d', $config['prefix'], $entryId);
}

function ensurePalletId(PDO $pdo, string $table, int $entryId): string
{
    palletTableConfig($table);
    $palletId = formatPalletId($table, $entryId);

    $update = $pdo->prepare("
        UPDATE {$table}
        SET PalletID = :pallet_id
        WHERE EntryID = :entry_id
          AND (PalletID IS NULL OR PalletID = '')
    ");
    $update->execute([
        ':pallet_id' => $palletId,
        ':entry_id' => $entryId,
    ]);

    $select = $pdo->prepare("SELECT PalletID FROM {$table} WHERE EntryID = :entry_id LIMIT 1");
    $select->execute([':entry_id' => $entryId]);
    $stored = trim((string)$select->fetchColumn());

    if ($stored === '') {
        throw new RuntimeException('Unable to assign a pallet ID.');
    }

    return $stored;
}

function normalizePalletScanCode(string $raw): string
{
    $value = strtoupper(trim($raw));

    if (str_starts_with($value, 'KISS:PALLET:')) {
        $value = substr($value, strlen('KISS:PALLET:'));
    }

    if (preg_match('/(PLT-[PCR]-\d{10})/', $value, $match)) {
        return $match[1];
    }

    return '';
}

function recordPalletMergeAlias(
    PDO $pdo,
    string $inventoryType,
    string $sourcePalletId,
    string $targetPalletId,
    ?int $sourceEntryId = null,
    ?int $targetEntryId = null
): void {
    $sourcePalletId = normalizePalletScanCode($sourcePalletId);
    $targetPalletId = normalizePalletScanCode($targetPalletId);

    if ($sourcePalletId === '' || $targetPalletId === '' || $sourcePalletId === $targetPalletId) {
        return;
    }

    $redirectAliases = $pdo->prepare("
        UPDATE pallet_id_aliases
        SET CurrentPalletID = :target_id,
            TargetEntryID = :target_entry_id,
            UpdatedAt = NOW()
        WHERE CurrentPalletID = :source_id
    ");
    $redirectAliases->execute([
        ':target_id' => $targetPalletId,
        ':target_entry_id' => $targetEntryId,
        ':source_id' => $sourcePalletId,
    ]);

    $insert = $pdo->prepare("
        INSERT INTO pallet_id_aliases
            (InventoryType, OldPalletID, CurrentPalletID, SourceEntryID, TargetEntryID, Reason)
        VALUES
            (:inventory_type, :old_id, :current_id, :source_entry_id, :target_entry_id, 'move-merge')
        ON DUPLICATE KEY UPDATE
            CurrentPalletID = VALUES(CurrentPalletID),
            TargetEntryID = VALUES(TargetEntryID),
            Reason = VALUES(Reason),
            UpdatedAt = NOW()
    ");
    $insert->execute([
        ':inventory_type' => $inventoryType,
        ':old_id' => $sourcePalletId,
        ':current_id' => $targetPalletId,
        ':source_entry_id' => $sourceEntryId,
        ':target_entry_id' => $targetEntryId,
    ]);
}

function resolvePalletScan(PDO $pdo, string $rawCode): ?array
{
    $scannedId = normalizePalletScanCode($rawCode);
    if ($scannedId === '') {
        return null;
    }

    $currentId = $scannedId;
    $wasAlias = false;

    $alias = $pdo->prepare("
        SELECT CurrentPalletID
        FROM pallet_id_aliases
        WHERE OldPalletID = :pallet_id
        LIMIT 1
    ");
    $alias->execute([':pallet_id' => $scannedId]);
    $aliasedId = normalizePalletScanCode((string)$alias->fetchColumn());
    if ($aliasedId !== '') {
        $currentId = $aliasedId;
        $wasAlias = true;
    }

    foreach (palletInventoryTables() as $table => $config) {
        $select = $pdo->prepare("
            SELECT EntryID, PalletID, Location, SKU_Code, BatchNo, ExpiryDate,
                   UnitType, QtyPerCtn, TotalQty, Comments
            FROM {$table}
            WHERE PalletID = :pallet_id
            LIMIT 1
        ");
        $select->execute([':pallet_id' => $currentId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['InventoryType'] = $config['type'];
            $row['InventoryTable'] = $table;
            $row['ScannedPalletID'] = $scannedId;
            $row['WasAlias'] = $wasAlias;
            return $row;
        }
    }

    return null;
}
