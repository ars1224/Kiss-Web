<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../util/notification_helper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $orderId = (int)($input['id'] ?? 0);
    $checkerName = trim((string)($input['checker_name'] ?? ''));
    $packedBy = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown';

    if ($orderId <= 0) {
        throw new Exception('Invalid order ID.');
    }

    if ($checkerName === '') {
        throw new Exception('Checker name is required.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
        FOR UPDATE
    ");
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('No order items found.');
    }

    $allNoStock = true;

    foreach ($items as $item) {
        $qtySupplied = strtoupper(trim((string)($item['qty_supplied'] ?? '')));

        if ($qtySupplied !== 'NO STOCK') {
            $allNoStock = false;
            break;
        }
    }

    $newStatus = $allNoStock ? 'not_sent' : 'booking';

    if (!$allNoStock) {
        deductOrderStock($pdo, $items, $order, $packedBy);
    }

    $stmt = $pdo->prepare("
        UPDATE orders
        SET 
            status = :status,
            checker_name = :checker_name,
            packed_by = :packed_by,
            checked_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        ':status' => $newStatus,
        ':checker_name' => $checkerName,
        ':packed_by' => $packedBy,
        ':id' => $orderId
    ]);

    $notificationTitle = $newStatus === 'not_sent'
        ? 'Order Not Sent'
        : 'Order Checked';

    $notificationMessage = $newStatus === 'not_sent'
        ? "Order #{$order['invoice_no']} has been checked but cannot be sent because all items are no stock."
        : "Order #{$order['invoice_no']} has been checked and is ready for courier booking.";

    createNotification(
        $pdo,
        null,
        'outwards',
        'checked',
        $notificationTitle,
        $notificationMessage,
        'orders_list.php'
    );

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'status' => $newStatus
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function deductOrderStock(PDO $pdo, array $items, array $order, string $packedBy): void
{
    foreach ($items as $item) {

        if (
            !empty($item['stock_deducted_at']) ||
            (string)($item['picked_done'] ?? '') === '1'
        ) {
            continue;
        }

        $itemId = (int)($item['id'] ?? 0);
        $sku = trim((string)($item['sku_code'] ?? ''));

        if ($itemId <= 0 || $sku === '') {
            continue;
        }

        $qtySuppliedRaw = trim((string)($item['qty_supplied'] ?? ''));

        if (strtoupper($qtySuppliedRaw) === 'NO STOCK') {
            continue;
        }

        $batchLines = splitPipe((string)($item['batch_no'] ?? ''));
        $locationLines = splitPipe((string)($item['location'] ?? ''));
        $unitsLines = splitPipe((string)($item['units_per_ctn'] ?? ''));
        $fullCtnLines = splitPipe((string)($item['full_ctn'] ?? ''));
        $qtyPerBatchLines = splitPipe((string)($item['qty_supplied_per_batch'] ?? ''));

        $lineCount = max(
            count($locationLines),
            count($batchLines),
            count($qtyPerBatchLines),
            1
        );

        $deductedSomething = false;

        for ($i = 0; $i < $lineCount; $i++) {
            $location = trim($locationLines[$i] ?? '');
            $batchExpiry = trim($batchLines[$i] ?? '');

            if ($location === '' || strtoupper($location) === 'NO STOCK') {
                continue;
            }

            if ($batchExpiry === '' || strtoupper($batchExpiry) === 'NO STOCK') {
                continue;
            }

            [$batchNo, $expiryDate] = splitBatchExpiry($batchExpiry);

            $deductQty = numericValue($qtyPerBatchLines[$i] ?? '');

            if ($deductQty <= 0) {
                $unitsPerCtn = numericValue($unitsLines[$i] ?? '');
                $fullCtn = numericValue($fullCtnLines[$i] ?? '');

                if ($unitsPerCtn > 0 && $fullCtn > 0) {
                    $deductQty = $unitsPerCtn * $fullCtn;
                }
            }

            if ($deductQty <= 0 && $lineCount === 1) {
                $deductQty = numericValue($qtySuppliedRaw);
            }

            if ($deductQty <= 0) {
                continue;
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM productlocation
                WHERE Location = :location
                  AND SKU_Code = :sku
                  AND BatchNo = :batch_no
                  AND ExpiryDate = :expiry_date
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                ':location' => $location,
                ':sku' => $sku,
                ':batch_no' => $batchNo,
                ':expiry_date' => $expiryDate
            ]);

            $stock = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stock) {
                continue;
            }

            $beforeQty = (float)$stock['TotalQty'];
            $actualDeduct = min($deductQty, $beforeQty);
            $afterQty = max($beforeQty - $actualDeduct, 0);

            if ($actualDeduct <= 0) {
                continue;
            }

            if ($afterQty <= 0) {
                $stmt = $pdo->prepare("
                    DELETE FROM productlocation
                    WHERE EntryID = :entry_id
                ");

                $stmt->execute([
                    ':entry_id' => $stock['EntryID']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE productlocation
                    SET TotalQty = :after_qty
                    WHERE EntryID = :entry_id
                ");

                $stmt->execute([
                    ':after_qty' => $afterQty,
                    ':entry_id' => $stock['EntryID']
                ]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO producttransactions (
                    EntryID,
                    Action,
                    OldLocation,
                    NewLocation,
                    SKU_Code,
                    BatchNo,
                    ExpiryDate,
                    UnitType,
                    QtyPerCtn,
                    DeltaQty,
                    TotalQty_Before,
                    TotalQty_After,
                    Notes,
                    Actor
                ) VALUES (
                    :entry_id,
                    'deduct',
                    :old_location,
                    :new_location,
                    :sku,
                    :batch_no,
                    :expiry_date,
                    :unit_type,
                    :qty_per_ctn,
                    :delta_qty,
                    :before_qty,
                    :after_qty,
                    :notes,
                    :actor
                )
            ");

            $stmt->execute([
                ':entry_id' => $stock['EntryID'],
                ':old_location' => $location,
                ':new_location' => $location,
                ':sku' => $sku,
                ':batch_no' => $batchNo,
                ':expiry_date' => $expiryDate,
                ':unit_type' => $stock['UnitType'] ?? null,
                ':qty_per_ctn' => $stock['QtyPerCtn'] ?? null,
                ':delta_qty' => -$actualDeduct,
                ':before_qty' => $beforeQty,
                ':after_qty' => $afterQty,
                ':notes' => 'Order invoice ' . ($order['invoice_no'] ?? '') . ' checked and picked',
                ':actor' => $packedBy
            ]);

            createLowStockNotificationForSku(
                $pdo,
                'productlocation',
                $sku,
                500
            );

            $deductedSomething = true;
        }

        if ($deductedSomething) {
            $stmt = $pdo->prepare("
                UPDATE order_items
                SET stock_deducted_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':id' => $itemId
            ]);
        }
    }
}

function splitPipe(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    return array_values(array_map('trim', explode('|', $value)));
}

function numericValue(string $value): float
{
    $value = trim($value);

    if ($value === '' || strtoupper($value) === 'NO STOCK') {
        return 0;
    }

    if (preg_match('/-?\d+(?:\.\d+)?/', $value, $match)) {
        return (float)$match[0];
    }

    return 0;
}

function splitBatchExpiry(string $value): array
{
    $value = trim($value);

    if (preg_match('/^(.*?)\s+(\d{2}\/\d{4})$/', $value, $match)) {
        return [
            trim($match[1]),
            trim($match[2])
        ];
    }

    return [
        $value,
        ''
    ];
}