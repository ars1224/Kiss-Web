<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../conn/requestHelpers.php';
require_once __DIR__ . '/../util/product_location_repo.php';
require_once __DIR__ . '/../util/transaction_repo.php'; // if you use tx_log
require_once __DIR__ . '/../util/inventory_helper.php';

$postedType = strtolower(trim((string)($_POST['InventoryType'] ?? '')));
$role = strtolower(trim(currentUserRole()));

if ($role !== 'admin') {
    $table = inventoryTable();
    $inventoryType = inventoryType();
} elseif (str_contains($postedType, 'component')) {
    $table = 'componentlocation';
    $inventoryType = 'packaging';
} elseif (str_contains($postedType, 'raw')) {
    $table = 'rmlocation';
    $inventoryType = 'rm';
} elseif (str_contains($postedType, 'product')) {
    $table = 'productlocation';
    $inventoryType = 'products';
} else {
    http_response_code(400);
    exit('Admin delete failed: missing or invalid InventoryType.');
}

function redirect_with_error(string $msg): void {
    $q  = get_param_string('q', '');
    $params = ['error' => $msg];
    if ($q !== '') $params['q'] = $q;
    $qs = http_build_query($params);
    header('Location: ../../Location.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

function redirect_with_success(string $msg): void {
    $q  = get_param_string('q', '');
    $params = ['success' => $msg];
    if ($q !== '') $params['q'] = $q;
    $qs = http_build_query($params);
    header('Location: ../../Location.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

// IDs of rows to delete
$ids = require_ids('EntryID');   // shared helper; returns array of positive ints

$pdo = db();
$pdo->beginTransaction();

try {
    // Fetch rows for logging first
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sel = $pdo->prepare("
        SELECT *
        FROM {$table}
        WHERE `EntryID` IN ($placeholders)
        FOR UPDATE
    ");
    $sel->execute($ids);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    // Delete
    $del = $pdo->prepare("DELETE FROM {$table} WHERE EntryID = ?");

foreach ($ids as $id) {
    $del->execute([(int)$id]);
}

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete_entries failed: ' . $e->getMessage());
    redirect_with_error('Delete failed: ' . $e->getMessage());
}

// Optional logging (does not affect redirect)
try {
    if (!empty($rows)) {
        foreach ($rows as $row) {
            tx_log([
                'InventoryType' => $inventoryType,
                'EntryID'         => (int)$row['EntryID'],
                'Action'          => 'delete',
                'OldLocation'     => $row['Location'],
                'NewLocation'     => null,
                'Comments'        => $row['Comments'],     // ✅ add this
                'SKU_Code'        => $row['SKU_Code'],
                'BatchNo'         => $row['BatchNo'],
                'ExpiryDate'      => $row['ExpiryDate'],
                'UnitType'        => $row['UnitType'],
                'QtyPerCtn'       => (int)$row['QtyPerCtn'],
                'DeltaQty'        => -(int)$row['TotalQty'],
                'TotalQty_Before' => (int)$row['TotalQty'],
                'TotalQty_After'  => 0,
            ]);
        }
    }
} catch (Throwable $logErr) {
    error_log('tx_log failed in delete_entries.php: ' . $logErr->getMessage());
}

redirect_with_success('Selected entries deleted.');
