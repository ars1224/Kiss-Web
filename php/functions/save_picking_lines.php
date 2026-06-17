<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $orderId = (int)($input['order_id'] ?? 0);
    $items = $input['items'] ?? [];
    $pickerName =
        $_SESSION['full_name']
        ?? $_SESSION['name']
        ?? $_SESSION['username']
        ?? 'Unknown';

    if ($orderId <= 0) {
        throw new Exception('Invalid order ID.');
    }

    if (!is_array($items)) {
        throw new Exception('Invalid items.');
    }

    $pdo = db();
    $pdo->beginTransaction();

$stmt = $pdo->prepare("
    UPDATE order_items
    SET picked_ctn_no = :picked_ctn_no,
        picked_done = :picked_done
    WHERE id = :id
      AND order_id = :order_id
");

$stmtCheck = $pdo->prepare("
    SELECT picked_done
    FROM order_items
    WHERE id = :id
      AND order_id = :order_id
    LIMIT 1
");

foreach ($items as $item) {
    $itemId = (int)($item['id'] ?? 0);
    $pickedDone = (string)($item['picked_done'] ?? '');

    if ($itemId <= 0) {
        continue;
    }

    $stmtCheck->execute([
        ':id' => $itemId,
        ':order_id' => $orderId
    ]);

    $current = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($current && (string)$current['picked_done'] === '1') {
        continue;
    }

    $stmt->execute([
        ':picked_ctn_no' => (string)($item['picked_ctn_no'] ?? ''),
        ':picked_done' => $pickedDone,
        ':id' => $itemId,
        ':order_id' => $orderId
    ]);

    if ($pickedDone === '1') {
        deductPickedItemStock($pdo, $orderId, $itemId, $pickerName);
    }
}

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function deductPickedItemStock(PDO $pdo, int $orderId, int $itemId, string $pickerName): void
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE id = :id
          AND order_id = :order_id
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        ':id' => $itemId,
        ':order_id' => $orderId
    ]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        return;
    }

    if (!empty($item['stock_deducted_at'])) {
        return;
    }

    $qtySuppliedRaw = strtoupper(trim((string)($item['qty_supplied'] ?? '')));

    if ($qtySuppliedRaw === 'NO STOCK') {
        return;
    }

    $sku = trim((string)($item['sku_code'] ?? ''));

    if ($sku === '') {
        return;
    }

    $batchLines = splitPipe((string)($item['batch_no'] ?? ''));
    $locationLines = splitPipe((string)($item['location'] ?? ''));
    $qtySuppliedLines = splitPipe((string)(($item['qty_supplied_per_batch'] ?? '') ?: ($item['qty_supplied'] ?? '')));

    $lineCount = max(count($batchLines), count($locationLines), count($qtySuppliedLines), 1);

    $deductedTotal = 0.0;

    for ($i = 0; $i < $lineCount; $i++) {
        $batchExpiry = trim($batchLines[$i] ?? '');
        $location = trim($locationLines[$i] ?? '');
        $deductQty = numericValue($qtySuppliedLines[$i] ?? '');

        if ($deductQty <= 0) {
            continue;
        }

        if ($location === '' || strtoupper($location) === 'NO STOCK') {
            continue;
        }

        if ($batchExpiry === '' || strtoupper($batchExpiry) === 'NO STOCK') {
            continue;
        }

        [$batchNo, $expiryDate] = splitBatchExpiry($batchExpiry);

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
            ':notes' => 'Deducted during picking for order ID ' . $orderId,
            ':actor' => $pickerName
        ]);

        $deductedTotal += $actualDeduct;
    }

    $orderedQty = numericValue((string)($item['total_qty'] ?? $item['order_qty'] ?? ''));
    $remainingShort = max($orderedQty - $deductedTotal, 0);

    $stmt = $pdo->prepare("
        UPDATE order_items
        SET stock_deducted_at = NOW()
        WHERE id = :id
          AND order_id = :order_id
    ");
    $stmt->execute([
        ':id' => $itemId,
        ':order_id' => $orderId
    ]);

    if (
        $remainingShort > 0
        && (int)($item['short_recreated'] ?? 0) === 0
        && shouldCreateShortOrderLine($pdo, $orderId, $remainingShort, $item)
    ) {
        createShortOrderLine($pdo, $orderId, $item, $remainingShort);

        $stmt = $pdo->prepare("
            UPDATE order_items
            SET short_recreated = 1
            WHERE id = :id
              AND order_id = :order_id
        ");
        $stmt->execute([
            ':id' => $itemId,
            ':order_id' => $orderId
        ]);
    }
}

function shouldCreateShortOrderLine(PDO $pdo, int $orderId, float $remainingShort, array $item): bool
{
    if (!isOrderRoundingEnabled($pdo, $orderId)) {
        return true;
    }

    $qtyPerCtn = getItemQtyPerCtn($item);

    if ($qtyPerCtn <= 0) {
        return true;
    }

    return $remainingShort > ($qtyPerCtn / 2);
}

function isOrderRoundingEnabled(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare("
        SELECT rounding_mode
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([':id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (string)($row['rounding_mode'] ?? '0') === '1';
}

function getItemQtyPerCtn(array $item): float
{
    $unitLines = splitPipe((string)($item['units_per_ctn'] ?? ''));

    foreach ($unitLines as $unitLine) {
        $qtyPerCtn = numericValue($unitLine);

        if ($qtyPerCtn > 0) {
            return $qtyPerCtn;
        }
    }

    return numericValue((string)($item['units_per_ctn'] ?? ''));
}

function createShortOrderLine(PDO $pdo, int $orderId, array $item, float $shortQty): void
{
    $stmt = $pdo->prepare("
        INSERT INTO order_items (
            order_id,
            sku_code,
            description,
            order_qty,
            total_qty,
            qty_supplied,
            qty_supplied_per_batch,
            units_per_ctn,
            full_ctn,
            ctn_no,
            batch_no,
            expiry_date,
            location,
            comment,
            picked_ctn_no,
            picked_done
        ) VALUES (
            :order_id,
            :sku_code,
            :description,
            :order_qty,
            :total_qty,
            'NO STOCK',
            'NO STOCK',
            'NO STOCK',
            'NO STOCK',
            'NO STOCK',
            'NO STOCK',
            '',
            'NO STOCK',
            'Short - remaining qty',
            '',
            '0'
        )
    ");

    $stmt->execute([
        ':order_id' => $orderId,
        ':sku_code' => $item['sku_code'] ?? '',
        ':description' => $item['description'] ?? '',
        ':order_qty' => formatNumber($shortQty),
        ':total_qty' => formatNumber($shortQty)
    ]);
}

function splitPipe(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    return array_map('trim', explode('|', $value));
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

function formatNumber(float $value): string
{
    if ((float)(int)$value === $value) {
        return number_format($value, 0, '.', '');
    }

    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
}
