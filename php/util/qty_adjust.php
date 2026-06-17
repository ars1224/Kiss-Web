<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../conn/requestHelpers.php';
require_once __DIR__ . '/product_location_repo.php';
require_once __DIR__ . '/transaction_repo.php';
require_once __DIR__ . '/inventory_helper.php';

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

// Expected POST:
//  - EntryID (int)
//  - delta  (can be positive or negative)
$entryId = (int)($_POST['EntryID'] ?? 0);
$delta   = (int)($_POST['delta']   ?? 0);
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
    redirect_with_error('Admin quantity adjustment failed: missing or invalid InventoryType.');
}

if ($entryId <= 0) {
    redirect_with_error('Missing EntryID for quantity adjust.');
}
if ($delta === 0) {
    redirect_with_error('No quantity change specified.');
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $select = $pdo->prepare("SELECT * FROM {$table} WHERE EntryID = ? FOR UPDATE");
    $select->execute([$entryId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        redirect_with_error('Row not found for quantity adjust.');
    }

    $currentQty = (int)$row['TotalQty'];
    $newQty = max(0, $currentQty + $delta);

    if ($newQty <= 0) {
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE EntryID = ?");
        $delete->execute([$entryId]);
        $deleted = true;
    } else {
        $update = $pdo->prepare("
            UPDATE {$table}
            SET TotalQty = ?, LastUpdated = NOW()
            WHERE EntryID = ?
        ");
        $update->execute([$newQty, $entryId]);
        $deleted = false;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('qty_adjust failed: ' . $e->getMessage());
    redirect_with_error('Quantity adjustment failed due to a server error.');
}

// Log (non-blocking)
try {
    tx_log([
        'InventoryType' => $inventoryType,
        'EntryID'         => $entryId,
        'Action'          => $deleted ? 'qty-auto-delete' : 'adjust',
        'OldLocation'     => $row['Location'],
        'NewLocation'     => $deleted ? null : $row['Location'],
        'Comments'        => $row['Comments'],     // ✅ add this
        'SKU_Code'        => $row['SKU_Code'],
        'BatchNo'         => $row['BatchNo'],
        'ExpiryDate'      => $row['ExpiryDate'],
        'UnitType'        => $row['UnitType'],
        'QtyPerCtn'       => (int)$row['QtyPerCtn'],
        'DeltaQty'        => $delta,
        'TotalQty_Before' => $currentQty,
        'TotalQty_After'  => $deleted ? 0 : $newQty,
    ]);
} catch (Throwable $logErr) {
    error_log('tx_log failed in qty_adjust.php: ' . $logErr->getMessage());
}

// Success
if ($deleted) {
    redirect_with_success('Quantity adjusted to 0 and entry was automatically deleted.');
}

$deltaAbs = abs($delta);
$verb = ($delta > 0) ? 'added' : 'deducted';
redirect_with_success("Successfully {$verb} {$deltaAbs} unit(s). New total: {$newQty}.");
