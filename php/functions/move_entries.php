<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../conn/requestHelpers.php';
require_once __DIR__ . '/../util/product_location_repo.php';
require_once __DIR__ . '/../util/transaction_repo.php';
require_once __DIR__ . '/../util/inventory_helper.php';
require_once __DIR__ . '/../util/notification_helper.php';

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
    exit('Admin move failed: missing or invalid InventoryType.');
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

// Selected rows: EntryID[]
$ids = require_ids('EntryID');
$newLoc = trim(get_param_string('NewLocation', ''));

if ($newLoc === '') {
    redirect_with_error('New Location is required.');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $selRow = $pdo->prepare("
        SELECT *
        FROM " . $table . "
        WHERE EntryID = ?
        FOR UPDATE
    ");

    $selMerge = $pdo->prepare("
        SELECT *
        FROM " . $table . "
        WHERE Location   = ?
          AND SKU_Code   = ?
          AND (BatchNo <=> ?)
          AND (ExpiryDate <=> ?)
          AND QtyPerCtn  = ?
          AND EntryID   <> ?
        LIMIT 1
        FOR UPDATE
    ");

    $updLoc = $pdo->prepare("
        UPDATE " . $table . "
        SET Location = ?, LastUpdated = NOW()
        WHERE EntryID = ?
    ");

    $updQty = $pdo->prepare("
        UPDATE " . $table . "
        SET TotalQty = TotalQty + ?, LastUpdated = NOW()
        WHERE EntryID = ?
    ");

    $delRow = $pdo->prepare("
        DELETE FROM " . $table . "
        WHERE EntryID = ?
    ");

    $affectedSkus = [];
    $movedCount = 0;

    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;

        $selRow->execute([$id]);
        $row = $selRow->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;

        $oldLoc = $row['Location'];
        $sku    = $row['SKU_Code'];
        $batch  = $row['BatchNo'];      // can be null
        $exp    = $row['ExpiryDate'];
        $qpc    = (int)$row['QtyPerCtn'];
        $qty    = (int)$row['TotalQty'];

        if ($oldLoc === $newLoc) {
            continue;
        }

        $affectedSkus[$sku] = true;

        // Look for merge target in new location
        $selMerge->execute([$newLoc, $sku, $batch, $exp, $qpc, $id]);
        $target = $selMerge->fetch(PDO::FETCH_ASSOC);

        if ($target) {
            // Merge
            $updQty->execute([$qty, (int)$target['EntryID']]);
            $delRow->execute([$id]);

            try {
                tx_log([
                    'InventoryType' => $inventoryType,
                    'EntryID'         => $id,
                    'Action'          => 'move-merge',
                    'OldLocation'     => $oldLoc,
                    'NewLocation'     => $newLoc,
                    'SKU_Code'        => $sku,
                    'BatchNo'         => $batch,
                    'ExpiryDate'      => $exp,
                    'UnitType'        => $row['UnitType'],
                    'QtyPerCtn'       => $qpc,
                    'DeltaQty'        => -$qty,
                    'TotalQty_Before' => $qty,
                    'TotalQty_After'  => 0,
                ]);
            } catch (Throwable $logErr) {
                error_log('tx_log(move-merge) failed: ' . $logErr->getMessage());
            }
        } else {
            // Just move
            $updLoc->execute([$newLoc, $id]);

            try {
                tx_log([
                    'InventoryType' => $inventoryType,
                    'EntryID'         => $id,
                    'Action'          => 'move',
                    'OldLocation'     => $oldLoc,
                    'NewLocation'     => $newLoc,
                    'SKU_Code'        => $sku,
                    'BatchNo'         => $batch,
                    'ExpiryDate'      => $exp,
                    'UnitType'        => $row['UnitType'],
                    'QtyPerCtn'       => $qpc,
                    'DeltaQty'        => 0,
                    'TotalQty_Before' => $qty,
                    'TotalQty_After'  => $qty,
                ]);
            } catch (Throwable $logErr) {
                error_log('tx_log(move) failed: ' . $logErr->getMessage());
            }
        }

        $movedCount++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('move_entries failed: ' . $e->getMessage());
    redirect_with_error('Move failed: ' . $e->getMessage());
}

foreach (array_keys($affectedSkus ?? []) as $sku) {
    try {
        createLowStockNotificationForSku($pdo, $table, (string)$sku, 500);
    } catch (Throwable $notificationError) {
        error_log('Low-stock notification after move failed: ' . $notificationError->getMessage());
    }
}

if (($movedCount ?? 0) === 0) {
    redirect_with_success('No entries needed to be moved.');
}

redirect_with_success($movedCount . ' entr' . ($movedCount === 1 ? 'y' : 'ies') . ' moved to ' . $newLoc . '.');
