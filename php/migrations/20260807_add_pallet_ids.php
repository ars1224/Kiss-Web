<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/pallet_id_helper.php';

$pdo = db();
$schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$results = [];

function migrationColumnExists(PDO $pdo, string $schema, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$schema, $table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function migrationIndexExists(PDO $pdo, string $schema, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $stmt->execute([$schema, $table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pallet_id_aliases (
            AliasID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            InventoryType VARCHAR(20) NOT NULL,
            OldPalletID VARCHAR(32) NOT NULL,
            CurrentPalletID VARCHAR(32) NOT NULL,
            SourceEntryID BIGINT NULL,
            TargetEntryID BIGINT NULL,
            Reason VARCHAR(50) NOT NULL DEFAULT 'move-merge',
            CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (AliasID),
            UNIQUE KEY uq_pallet_alias_old (OldPalletID),
            KEY idx_pallet_alias_current (CurrentPalletID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pallet_scan_events (
            ScanID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            OrderID INT NULL,
            OrderItemID INT NULL,
            ScannedPalletID VARCHAR(32) NOT NULL,
            ResolvedPalletID VARCHAR(32) NULL,
            Result VARCHAR(30) NOT NULL,
            Message VARCHAR(255) NOT NULL,
            ScannedByUserID INT NULL,
            ScannedByName VARCHAR(100) NULL,
            ScannedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ScanID),
            KEY idx_pallet_scan_order (OrderID, ScannedAt),
            KEY idx_pallet_scan_pallet (ScannedPalletID, ScannedAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    foreach (palletInventoryTables() as $table => $config) {
        if (!migrationColumnExists($pdo, $schema, $table, 'PalletID')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN PalletID VARCHAR(32) NULL AFTER EntryID");
        }

        $prefix = $config['prefix'];
        $pdo->exec("
            UPDATE {$table}
            SET PalletID = CONCAT('PLT-', '{$prefix}', '-', LPAD(EntryID, 10, '0'))
            WHERE PalletID IS NULL OR PalletID = ''
        ");

        $indexName = 'uq_' . $table . '_pallet_id';
        if (!migrationIndexExists($pdo, $schema, $table, $indexName)) {
            $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY {$indexName} (PalletID)");
        }

        $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE PalletID IS NOT NULL AND PalletID <> ''")->fetchColumn();
        $missing = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE PalletID IS NULL OR PalletID = ''")->fetchColumn();
        $results[$table] = ['assigned' => $count, 'missing' => $missing];
    }

    echo json_encode(['success' => true, 'tables' => $results], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['success' => false, 'message' => $e->getMessage()]) . PHP_EOL);
    exit(1);
}
