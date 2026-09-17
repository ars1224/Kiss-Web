<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/pallet_id_helper.php';

function scanJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function scanSplitPipe(string $value): array
{
    if (trim($value) === '') return [];
    return array_map('trim', explode('|', $value));
}

function scanSplitBatchExpiry(string $value): array
{
    $value = trim($value);
    if (preg_match('/^(.*?)\s+(\d{2}\/\d{4})$/', $value, $match)) {
        return [trim($match[1]), trim($match[2])];
    }
    return [$value, ''];
}

function scanNumericValue(string $value): float
{
    if (preg_match('/-?\d+(?:\.\d+)?/', trim($value), $match)) {
        return (float)$match[0];
    }
    return 0.0;
}

function scanFormatNumber(float $value): string
{
    if ((float)(int)$value === $value) return number_format($value, 0, '.', '');
    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
}

function logPalletScan(
    PDO $pdo,
    int $orderId,
    ?int $orderItemId,
    string $scannedId,
    ?string $resolvedId,
    string $result,
    string $message
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO pallet_scan_events
                (OrderID, OrderItemID, ScannedPalletID, ResolvedPalletID, Result,
                 Message, ScannedByUserID, ScannedByName)
            VALUES
                (:order_id, :order_item_id, :scanned_id, :resolved_id, :result,
                 :message, :user_id, :user_name)
        ");
        $stmt->execute([
            ':order_id' => $orderId > 0 ? $orderId : null,
            ':order_item_id' => $orderItemId,
            ':scanned_id' => substr($scannedId !== '' ? $scannedId : 'INVALID', 0, 32),
            ':resolved_id' => $resolvedId,
            ':result' => substr($result, 0, 30),
            ':message' => substr($message, 0, 255),
            ':user_id' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ':user_name' => substr((string)(
                $_SESSION['full_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown'
            ), 0, 100),
        ]);
    } catch (Throwable $logError) {
        error_log('Pallet scan audit failed: ' . $logError->getMessage());
    }
}

if (!isLoggedIn()) {
    scanJson(['success' => false, 'code' => 'unauthorized', 'message' => 'Please sign in again.'], 401);
}

try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid scan request.');
    }

    $orderId = (int)($input['order_id'] ?? 0);
    $rawCode = trim((string)($input['pallet_id'] ?? ''));
    if ($orderId <= 0) throw new InvalidArgumentException('Invalid order ID.');
    if ($rawCode === '') throw new InvalidArgumentException('Scan or enter a pallet ID.');

    $pdo = db();
    $scannedId = normalizePalletScanCode($rawCode);

    $orderStmt = $pdo->prepare('SELECT id, status, invoice_no FROM orders WHERE id = :id LIMIT 1');
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Order not found.');

    if ((string)$order['status'] !== 'ongoing') {
        $message = 'Pallet scanning is available only while the order is being picked.';
        logPalletScan($pdo, $orderId, null, $scannedId, null, 'wrong_status', $message);
        scanJson(['success' => false, 'code' => 'wrong_status', 'message' => $message], 409);
    }

    if ($scannedId === '') {
        $message = 'This is not a KISS pallet barcode.';
        logPalletScan($pdo, $orderId, null, strtoupper(substr($rawCode, 0, 32)), null, 'invalid_code', $message);
        scanJson(['success' => false, 'code' => 'invalid_code', 'message' => $message], 422);
    }

    $pallet = resolvePalletScan($pdo, $scannedId);
    if (!$pallet) {
        $message = 'Pallet ID not found or the inventory row has already been consumed.';
        logPalletScan($pdo, $orderId, null, $scannedId, null, 'not_found', $message);
        scanJson(['success' => false, 'code' => 'not_found', 'message' => $message], 404);
    }

    $resolvedId = (string)$pallet['PalletID'];
    if ((string)$pallet['InventoryTable'] !== 'productlocation') {
        $message = 'This pallet belongs to ' . (string)$pallet['InventoryType'] . ', not finished products for this order.';
        logPalletScan($pdo, $orderId, null, $scannedId, $resolvedId, 'wrong_inventory', $message);
        scanJson([
            'success' => false,
            'code' => 'wrong_inventory',
            'message' => $message,
            'pallet' => $pallet,
        ], 409);
    }

    $itemsStmt = $pdo->prepare("
        SELECT id, sku_code, batch_no, location, qty_supplied_per_batch,
               qty_supplied, picked_done
        FROM order_items
        WHERE order_id = :order_id
        ORDER BY id
    ");
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $matchedFinishedItem = null;
    $sameSkuExists = false;

    foreach ($items as $item) {
        if (strcasecmp(trim((string)$item['sku_code']), trim((string)$pallet['SKU_Code'])) !== 0) {
            continue;
        }
        $sameSkuExists = true;

        $batchLines = scanSplitPipe((string)$item['batch_no']);
        $locationLines = scanSplitPipe((string)$item['location']);
        $qtyLines = scanSplitPipe((string)(
            ($item['qty_supplied_per_batch'] ?? '') ?: ($item['qty_supplied'] ?? '')
        ));
        $lineCount = max(count($batchLines), count($locationLines), count($qtyLines), 1);

        for ($index = 0; $index < $lineCount; $index++) {
            [$batchNo, $expiryDate] = scanSplitBatchExpiry((string)($batchLines[$index] ?? ''));
            $location = trim((string)($locationLines[$index] ?? ''));

            $matches = strcasecmp($location, trim((string)$pallet['Location'])) === 0
                && strcasecmp($batchNo, trim((string)$pallet['BatchNo'])) === 0
                && strcasecmp($expiryDate, trim((string)$pallet['ExpiryDate'])) === 0;

            if (!$matches) continue;

            if ((string)$item['picked_done'] === '1') {
                $matchedFinishedItem = (int)$item['id'];
                continue;
            }

            $expectedQty = scanNumericValue((string)($qtyLines[$index] ?? ''));
            $message = 'Correct pallet for this order.';
            if (!empty($pallet['WasAlias'])) {
                $message .= ' The older label was resolved to ' . $resolvedId . '.';
            }

            logPalletScan($pdo, $orderId, (int)$item['id'], $scannedId, $resolvedId, 'accepted', $message);
            scanJson([
                'success' => true,
                'code' => 'accepted',
                'message' => $message,
                'match' => [
                    'order_item_id' => (int)$item['id'],
                    'line_index' => $index,
                    'expected_qty' => scanFormatNumber($expectedQty),
                ],
                'pallet' => $pallet,
            ]);
        }
    }

    if ($matchedFinishedItem !== null) {
        $message = 'This pallet matches an order item that is already marked done.';
        logPalletScan($pdo, $orderId, $matchedFinishedItem, $scannedId, $resolvedId, 'already_done', $message);
        scanJson(['success' => false, 'code' => 'already_done', 'message' => $message, 'pallet' => $pallet], 409);
    }

    $message = $sameSkuExists
        ? 'The SKU is on this order, but this location, batch, or expiry is not allocated.'
        : 'This pallet SKU is not on this order.';
    logPalletScan($pdo, $orderId, null, $scannedId, $resolvedId, 'wrong_order', $message);
    scanJson([
        'success' => false,
        'code' => 'wrong_order',
        'message' => $message,
        'pallet' => $pallet,
    ], 409);
} catch (InvalidArgumentException $e) {
    scanJson(['success' => false, 'code' => 'invalid_request', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('validate_pallet_scan failed: ' . $e->getMessage());
    scanJson(['success' => false, 'code' => 'server_error', 'message' => 'Pallet validation failed. Please try again.'], 500);
}
