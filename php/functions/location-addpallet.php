<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../conn/requestHelpers.php';
require_once __DIR__ . '/../util/transaction_repo.php';
require_once __DIR__ . '/../util/inventory_helper.php';

$table = inventoryTable();

$requestedInventory = strtolower(trim(
    (string)($_POST['inventory'] ?? $_GET['inventory'] ?? '')
));

if ($table === 'all') {
    if (in_array($requestedInventory, ['components', 'component', 'packaging'], true)) {
        $table = 'componentlocation';
        $inventoryType = 'packaging';
    } elseif (in_array($requestedInventory, ['rm', 'raw', 'rawmat', 'raw_materials'], true)) {
        $table = 'rmlocation';
        $inventoryType = 'rm';
    } else {
        $table = 'productlocation';
        $inventoryType = 'products';
    }
} else {
    $inventoryType = inventoryType();
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

/**
 * ✅ Silent print (NO browser tab, NO preview)
 * Calls print_labels.php via HTTP POST after DB commit.
 */
function trigger_silent_print_labels(array $ids, string $inventoryType): void {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($x) => $x > 0));
    if (!$ids) return;

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

    $url = $scheme . '://' . $host . $base . '/print_labels.php';

    $post = http_build_query([
        'mode'      => 'saved',
        'ids'       => json_encode($ids),
        'inventory' => $inventoryType
    ]);

    $cookieHeader = '';
    if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
        $cookieHeader = "Cookie: " . session_name() . "=" . session_id() . "\r\n";
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  =>
                "Content-Type: application/x-www-form-urlencoded\r\n" .
                $cookieHeader .
                "Connection: close\r\n",
            'content' => $post,
            'timeout' => 10,
        ]
    ]);

    $result = @file_get_contents($url, false, $ctx);

    error_log('Silent print URL: ' . $url);
    error_log('Silent print IDs: ' . json_encode($ids));
    error_log('Silent print inventory: ' . $inventoryType);
    error_log('Silent print result: ' . (string)$result);
}

/**
 * Get POST field as an array (supports single input or multi-row arrays).
 */
function post_array(string $key): array {
    if (!isset($_POST[$key])) return [];
    return is_array($_POST[$key]) ? $_POST[$key] : [$_POST[$key]];
}

function norm_str($v): string {
    return trim((string)$v);
}

function norm_nullable_str($v): ?string {
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function norm_int($v, int $min = 0): int {
    $n = (int)($v ?? 0);
    return ($n < $min) ? $min : $n;
}

// Collect rows (single or multi)
$locs      = post_array('Location');
$skus      = post_array('SKU_Code');
$batches   = post_array('BatchNo');
$expiries  = post_array('ExpiryDate');
$units     = post_array('UnitType');
$qtyctns   = post_array('QtyPerCtn');
$totals    = post_array('TotalQty');
$commentsA = post_array('Comments');

$rowCount = max(
    count($locs),
    count($skus),
    count($batches),
    count($expiries),
    count($units),
    count($qtyctns),
    count($totals),
    count($commentsA),
);

if ($rowCount === 0) {
    redirect_with_error('No data submitted.');
}

$pdo = db();

$created = 0;
$merged  = 0;

// ✅ Collect saved/merged EntryIDs to print AFTER commit
$printIds = [];
   $role = strtolower(trim(currentUserRole()));
try {
    $pdo->beginTransaction();

    $find = $pdo->prepare("
        SELECT EntryID, TotalQty
        FROM {$table}
        WHERE Location = ?
          AND SKU_Code = ?
          AND (BatchNo <=> ?)
          AND (ExpiryDate <=> ?)
          AND (QtyPerCtn <=> ?)
          AND (Comments <=> ?)
        LIMIT 1
    ");

    $ins = $pdo->prepare("
        INSERT INTO {$table}
        (Location, SKU_Code, BatchNo, ExpiryDate, UnitType, QtyPerCtn, TotalQty, Comments, LastUpdated)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $upd = $pdo->prepare("
        UPDATE {$table}
        SET TotalQty = TotalQty + ?,
            UnitType = COALESCE(?, UnitType),
            LastUpdated = NOW()
        WHERE EntryID = ?
    ");

 

    $expiryRequired = in_array($inventoryType, ['products', 'rm'], true)
    && $role !== 'admin';

    for ($i = 0; $i < $rowCount; $i++) {

        $loc      = norm_str($locs[$i] ?? '');
        $sku      = norm_str($skus[$i] ?? '');
        $batch = norm_str($batches[$i] ?? '');
        $expiry = validate_expiry($expiries[$i] ?? null, !$expiryRequired);
        $unit     = norm_nullable_str($units[$i] ?? '');
        $qtyctn   = norm_int($qtyctns[$i] ?? 0, 0);
        $totalAdd = norm_int($totals[$i] ?? 0, 0);
        $comments = norm_nullable_str($commentsA[$i] ?? '');

        // Skip fully blank rows (common with "Add Row")
        if ($loc === '' && $sku === '' && $totalAdd === 0 && $expiry === null) {
            continue;
        }

        if ($loc === '' || $sku === '') {
            throw new RuntimeException("Row " . ($i+1) . ": Location and SKU are required.");
        }
        if ($expiryRequired && $expiry === null) {
            throw new RuntimeException("Row " . ($i+1) . ": Expiry is required.");
        }   
        if ($totalAdd <= 0) {
            throw new RuntimeException("Row " . ($i+1) . ": TotalQty must be greater than 0.");
        }

        // Check for existing row (same Location + SKU + Batch + Expiry + QtyPerCtn)
        $find->execute([$loc, $sku, $batch, $expiry, $qtyctn, $comments]);
        $existing = $find->fetch();

        if ($existing) {
            $entryId = (int)$existing['EntryID'];
            $before  = (int)$existing['TotalQty'];
            $after   = $before + $totalAdd;

           $upd->execute([$totalAdd, $unit, $entryId]);

            // ✅ Queue for silent printing
            $printIds[] = $entryId;

            // Log merge/add
            try {
                tx_log([
                    'InventoryType' => $inventoryType,
                    'EntryID'         => $entryId,
                    'Action'          => 'add', // merged into existing
                    'OldLocation'     => $loc,
                    'NewLocation'     => $loc,
                    'Comments'        => $comments,     // ✅ add this
                    'SKU_Code'        => $sku,
                    'BatchNo'         => $batch,
                    'ExpiryDate'      => $expiry,
                    'UnitType'        => $unit,
                    'QtyPerCtn'       => $qtyctn,
                    'DeltaQty'        => $totalAdd,
                    'TotalQty_Before' => $before,
                    'TotalQty_After'  => $after,
                ]);
            } catch (Throwable $logErr) {
                error_log('tx_log(add) failed: ' . $logErr->getMessage());
            }

            $merged++;
        } else {
            $ins->execute([$loc, $sku, $batch, $expiry, $unit, $qtyctn, $totalAdd, $comments]);
            $entryId = (int)$pdo->lastInsertId();

            // ✅ Queue for silent printing
            $printIds[] = $entryId;

            // Log creation
            try {
                tx_log([
                    'InventoryType' => $inventoryType,
                    'EntryID'         => $entryId,
                    'Action'          => 'create',
                    'OldLocation'     => null,
                    'NewLocation'     => $loc,
                    'Comments'        => $comments,     // ✅ add this
                    'SKU_Code'        => $sku,
                    'BatchNo'         => $batch,
                    'ExpiryDate'      => $expiry,
                    'UnitType'        => $unit,
                    'QtyPerCtn'       => $qtyctn,
                    'DeltaQty'        => $totalAdd,
                    'TotalQty_Before' => 0,
                    'TotalQty_After'  => $totalAdd,
                ]);
            } catch (Throwable $logErr) {
                error_log('tx_log(create) failed: ' . $logErr->getMessage());
            }

            $created++;
        }
    }

    $pdo->commit();

    // ✅ AFTER COMMIT: silently print the labels (no browser, no tab)
    // If printing fails, we still let the save succeed.
    try {
        trigger_silent_print_labels($printIds, $inventoryType);
    } catch (Throwable $printErr) {
        error_log('silent print failed: ' . $printErr->getMessage());
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('location-addpallet failed: ' . $e->getMessage());
    redirect_with_error($e->getMessage());
}

redirect_with_success("Saved. New: {$created}, merged: {$merged}.");
