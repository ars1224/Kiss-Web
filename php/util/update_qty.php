<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../conn/requestHelpers.php';
require_once __DIR__ . '/product_location_repo.php';
require_once __DIR__ . '/transaction_repo.php';
require_once __DIR__ . '/inventory_helper.php';
require_once __DIR__ . '/notification_helper.php';

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
    exit('Admin quantity update failed: missing or invalid InventoryType.');
}

/**
 * Redirect helper: back to Location.php with an error message.
 */
function redirect_with_error(string $msg): void {
    $q  = get_param_string('q', '');
    $params = ['error' => $msg];
    if ($q !== '') {
        $params['q'] = $q;
    }
    $qs = http_build_query($params);
    header('Location: ../../Location.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

/**
 * Redirect helper: back to Location.php with a success message.
 */
function redirect_with_success(string $msg): void {
    $q  = get_param_string('q', '');
    $params = ['success' => $msg];
    if ($q !== '') {
        $params['q'] = $q;
    }
    $qs = http_build_query($params);
    header('Location: ../../Location.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

// -------- Read + validate input --------

$entryId = (int)($_POST['EntryID'] ?? 0);
$mode    = strtolower(trim((string)($_POST['mode'] ?? '')));
$amount  = int_or_min($_POST['amount'] ?? 0, 0);  // ensure >= 0

if ($entryId <= 0) {
    redirect_with_error('Missing EntryID for quantity update.');
}

if ($amount <= 0) {
    redirect_with_error('Amount must be a positive number.');
}

if ($mode !== 'add' && $mode !== 'deduct') {
    redirect_with_error('Invalid mode for quantity update.');
}

// -------- Apply change (update or auto-delete) --------

try {
    $pdo = db();
    $pdo->beginTransaction();

    $select = $pdo->prepare("SELECT * FROM {$table} WHERE EntryID = ? FOR UPDATE");
    $select->execute([$entryId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        redirect_with_error('Row not found for quantity update.');
    }

    $currentQty = (int)$row['TotalQty'];

    if ($mode === 'add') {
        $newQty = $currentQty + $amount;
        $action = 'add';
    } else {
        $newQty = max(0, $currentQty - $amount);
        $action = 'deduct';
    }

    if ($newQty <= 0) {
        // Auto-delete when quantity hits 0
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE EntryID = ?");
        $stmt->execute([$entryId]);
        $deleted = true;
    } else {
        $stmt = $pdo->prepare("UPDATE {$table} SET TotalQty = ?, LastUpdated = NOW() WHERE EntryID = ?");
        $stmt->execute([$newQty, $entryId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException("No row updated. Table={$table}, EntryID={$entryId}, NewQty={$newQty}");
        }
        $deleted = false;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update_qty failed: ' . $e->getMessage());
    redirect_with_error('Quantity update failed: ' . $e->getMessage());
}

try {
    createLowStockNotificationForSku($pdo, $table, (string)$row['SKU_Code'], 500);
} catch (Throwable $notificationError) {
    error_log('Low-stock notification after quantity update failed: ' . $notificationError->getMessage());
}

// -------- Log transaction (non-blocking) --------

try {
    $delta = ($mode === 'add') ? $amount : -$amount;

    tx_log([
        'InventoryType' => $inventoryType,
        'EntryID'         => $entryId,
        'Action'          => $deleted ? 'qty-auto-delete' : $action,
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
    error_log('tx_log failed in update_qty.php: ' . $logErr->getMessage());
}

// -------- Redirect back with success message --------

if ($deleted) {
    redirect_with_success('Quantity updated to 0 and entry was automatically deleted.');
}

$verb = ($mode === 'add') ? 'added' : 'deducted';
redirect_with_success("Successfully {$verb} {$amount} unit(s). New total: {$newQty}.");
