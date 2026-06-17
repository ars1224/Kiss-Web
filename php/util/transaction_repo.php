<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../conn/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../util/inventory_helper.php';

/**
 * Transaction logger (robust).
 * - Auto-detects the correct table name (ProductTransactions vs producttransactions)
 * - Auto-detects existing columns and only inserts those (prevents schema mismatch failures)
 * - Supports optional Actor
 */
function tx_log(array $data): void
{
    $pdo = db();

    static $cached = null;
    if ($cached === null) {
        // 1) Detect which table exists
        $dbName = defined('DB_NAME') ? DB_NAME : null;
        if (!$dbName) {
            throw new RuntimeException('DB_NAME is not defined (config.php).');
        }

        $candidates = ['ProductTransactions', 'producttransactions'];
        $table = null;

        $chk = $pdo->prepare("
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = :t
            LIMIT 1
        ");

        foreach ($candidates as $t) {
            $chk->execute(['db' => $dbName, 't' => $t]);
            if ($chk->fetchColumn()) {
                $table = $t;
                break;
            }
        }

        if ($table === null) {
            throw new RuntimeException('Transactions table not found. Expected ProductTransactions or producttransactions.');
        }

        // 2) Get columns for that table
        $colStmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = :t
        ");
        $colStmt->execute(['db' => $dbName, 't' => $table]);
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        $cached = [
            'table' => $table,
            'cols'  => array_flip($cols), // faster lookup
        ];
    }

    $table = $cached['table'];
    $has   = $cached['cols']; // associative set: col => true

    // Defaults (safe)
    $defaults = [
    'InventoryType'    => inventoryType(),
    'EntryID'         => null,
    'Action'          => null,
    'OldLocation'     => null,
    'NewLocation'     => null,
    'SKU_Code'        => null,
    'BatchNo'         => null,
    'ExpiryDate'      => null,
    'UnitType'        => null,
    'QtyPerCtn'       => null,
    'DeltaQty'        => 0,
    'TotalQty_Before' => 0,
    'TotalQty_After'  => 0,
    'Comments'        => '',
    'Actor'           => null,
];
    $row = array_merge($defaults, $data);

    if (empty($row['Actor'])) {
    $row['Actor'] = $_SESSION['name'] ?? 'Unknown';
}

    // Required fields (you can loosen this, but these are core)
    if (empty($row['EntryID'])) {
        throw new RuntimeException('tx_log missing EntryID');
    }
    if (empty($row['Action'])) {
        throw new RuntimeException('tx_log missing Action');
    }

    // Build column list dynamically based on actual table schema
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($row as $k => $v) {
        if ($k === 'CreatedAt') continue; // we handle CreatedAt below
        if (!isset($has[$k])) continue;   // skip columns that don’t exist
        $columns[] = "`$k`";
        $placeholders[] = ":$k";
        $params[$k] = $v;
    }

    // CreatedAt handling:
    // If table has CreatedAt column, set it to NOW() unless caller provided.
    if (isset($has['CreatedAt'])) {
        $columns[] = "`CreatedAt`";
        $placeholders[] = "NOW()";
    }

    if (!$columns) {
        throw new RuntimeException('tx_log: No matching columns found to insert (schema mismatch).');
    }

    $sql = "INSERT INTO `$table` (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
