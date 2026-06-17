<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../conn/requestHelpers.php';
require_once __DIR__ . '/../util/transaction_repo.php';
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
    exit('Admin update failed: missing or invalid InventoryType.');
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

// ---------- Read + validate input ----------

$id       = (int)($_POST['EntryID'] ?? 0);
$loc      = trim((string)($_POST['Location']   ?? ''));
$sku      = trim((string)($_POST['SKU_Code']   ?? ''));
$batch    = trim((string)($_POST['BatchNo']    ?? ''));
$expiryRequired = in_array($inventoryType, ['products', 'rm'], true)
    && $role !== 'admin';

$expiry = validate_expiry($_POST['ExpiryDate'] ?? null, !$expiryRequired);
$unit     = trim((string)($_POST['UnitType']   ?? ''));
$qtyctn   = int_or_min($_POST['QtyPerCtn'] ?? 0, 0);
$total    = int_or_min($_POST['TotalQty']  ?? 0, 0);
$comments = trim((string)($_POST['Comments']   ?? ''));

if ($id <= 0) {
    redirect_with_error('Missing EntryID.');
}

// validate_expiry already throws on invalid; double-check format for safety
if ($expiry !== null && !preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $expiry)) {
    redirect_with_error('Invalid ExpiryDate. Use MM/YYYY.');
}

$pdo = db();
$pdo->beginTransaction();

try {
    // Lock current row
    $sel = $pdo->prepare("SELECT * FROM {$table} WHERE `EntryID`=? FOR UPDATE");
    $sel->execute([$id]);
    $old = $sel->fetch();
    if (!$old) {
        $pdo->rollBack();
        redirect_with_error('Row not found.');
    }

    // Normalize optionals
    $batch    = ($batch === '') ? null : $batch;
    $comments = ($comments === '') ? null : $comments;
    $unit     = ($unit === '') ? null : $unit;

    // If TotalQty <= 0 → delete instead of update
    if ($total <= 0) {
        $del = $pdo->prepare("DELETE FROM {$table} WHERE `EntryID`=?");
        $del->execute([$id]);
        $new = null; // deleted
    } else {
        // Main update
        $upd = $pdo->prepare("
            UPDATE {$table}
            SET `Location`    = ?,
                `SKU_Code`    = ?,
                `BatchNo`     = ?,
                `ExpiryDate`  = ?,
                `UnitType`    = ?,
                `QtyPerCtn`   = ?,
                `TotalQty`    = ?,
                `Comments`    = ?,
                `LastUpdated` = NOW()
            WHERE `EntryID`   = ?
        ");
        $upd->execute([$loc, $sku, $batch, $expiry, $unit, $qtyctn, $total, $comments, $id]);

        // Fetch new row (for logging)
        $sel2 = $pdo->prepare("SELECT * FROM {$table} WHERE `EntryID`=?");
        $sel2->execute([$id]);
        $new = $sel2->fetch();
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update_entry failed: ' . $e->getMessage());
   // redirect_with_error('Update failed due to a server error.');
    redirect_with_error('Update failed: ' . $e->getMessage());
}

// ---- Logging (does not break UX if it fails) ----
try {
    if (isset($old, $new) && $old && $new) {
        // Normal update
        tx_log([
            'InventoryType' => $inventoryType,
            'EntryID'         => $id,
            'Action'          => 'edit',
            'OldLocation'     => $old['Location'],
            'NewLocation'     => $new['Location'],
            'Comments'        => $new['Comments'],     // ✅ add this
            'SKU_Code'        => $new['SKU_Code'],
            'BatchNo'         => $new['BatchNo'],
            'ExpiryDate'      => $new['ExpiryDate'],
            'UnitType'        => $new['UnitType'],
            'QtyPerCtn'       => (int)$new['QtyPerCtn'],
            'DeltaQty'        => (int)$new['TotalQty'] - (int)$old['TotalQty'],
            'TotalQty_Before' => (int)$old['TotalQty'],
            'TotalQty_After'  => (int)$new['TotalQty'],
            
        ]);
    } elseif (isset($old) && $old && $total <= 0) {
        // Deleted via setting qty <= 0 (in case you allow that)
        tx_log([
            'InventoryType' => $inventoryType,
            'EntryID'         => $id,
            'Action'          => 'delete-zero',
            'OldLocation'     => $old['Location'],
            'NewLocation'     => null,
            'Comments'        => $old['Comments'],     // ✅ add this
            'SKU_Code'        => $old['SKU_Code'],
            'BatchNo'         => $old['BatchNo'],
            'ExpiryDate'      => $old['ExpiryDate'],
            'UnitType'        => $old['UnitType'],
            'QtyPerCtn'       => (int)$old['QtyPerCtn'],
            'DeltaQty'        => -(int)$old['TotalQty'],
            'TotalQty_Before' => (int)$old['TotalQty'],
            'TotalQty_After'  => 0,
        ]);
    }
} catch (Throwable $logErr) {
    error_log('tx_log failed in update_entry.php: ' . $logErr->getMessage());
}

// Back to page with success banner
redirect_with_success('Entry updated successfully.');
