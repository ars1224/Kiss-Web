<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';

$pdo = null;

try {
    $input = json_decode((string)file_get_contents('php://input'), true);

    if (!is_array($input)) {
        throw new Exception('Invalid request payload.');
    }

    $orderId = (int)($input['order_id'] ?? 0);
    $items = $input['items'] ?? [];
    $deletedItemIds = $input['deleted_item_ids'] ?? [];

    if ($orderId <= 0) {
        throw new Exception('Invalid order ID.');
    }

    if (!is_array($items) || !is_array($deletedItemIds)) {
        throw new Exception('Invalid order line data.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT status
        FROM orders
        WHERE id = :id
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    if (!in_array((string)$order['status'], ['pending', 'ongoing'], true)) {
        throw new Exception('This order cannot be changed in its current status.');
    }

    $findItem = $pdo->prepare("
        SELECT id, picked_done, stock_deducted_at
        FROM order_items
        WHERE id = :id
          AND order_id = :order_id
        LIMIT 1
        FOR UPDATE
    ");
    $deleteItem = $pdo->prepare("
        DELETE FROM order_items
        WHERE id = :id
          AND order_id = :order_id
    ");
    $updateItem = $pdo->prepare("
        UPDATE order_items
        SET batch_no = :batch_no,
            total_qty_supplied = :total_qty_supplied,
            qty_supplied = :qty_supplied,
            qty_supplied_per_batch = :qty_supplied_per_batch,
            units_per_ctn = :units_per_ctn,
            full_ctn = :full_ctn,
            ctn_no = :ctn_no,
            picked_ctn_no = CASE
                WHEN TRIM(COALESCE(picked_ctn_no, '')) <> '' THEN :picked_ctn_no
                ELSE picked_ctn_no
            END,
            location = :location,
            comment = :comment
        WHERE id = :id
          AND order_id = :order_id
    ");

    $deletedCount = 0;

    foreach (array_values(array_unique(array_map('intval', $deletedItemIds))) as $itemId) {
        if ($itemId <= 0) {
            throw new Exception('Invalid order line ID.');
        }

        $line = lockEditableOrderLine($findItem, $orderId, $itemId);
        assertOrderLineCanBeDeleted($line);

        $deleteItem->execute([
            ':id' => $itemId,
            ':order_id' => $orderId
        ]);
        $deletedCount += $deleteItem->rowCount();
    }

    $updatedCount = 0;
    $seenItemIds = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new Exception('Invalid order line data.');
        }

        $itemId = (int)($item['id'] ?? 0);

        if ($itemId <= 0 || isset($seenItemIds[$itemId])) {
            throw new Exception('Invalid or duplicate order line ID.');
        }

        $seenItemIds[$itemId] = true;
        lockEditableOrderLine($findItem, $orderId, $itemId);

        $totalQtySupplied = readLineText($item, 'total_qty_supplied');
        $ctnNo = readLineText($item, 'ctn_no');

        $updateItem->execute([
            ':batch_no' => readLineText($item, 'batch_no'),
            ':total_qty_supplied' => $totalQtySupplied,
            ':qty_supplied' => $totalQtySupplied,
            ':qty_supplied_per_batch' => readLineText($item, 'qty_supplied_per_batch'),
            ':units_per_ctn' => readLineText($item, 'units_per_ctn'),
            ':full_ctn' => readLineText($item, 'full_ctn'),
            ':ctn_no' => $ctnNo,
            ':picked_ctn_no' => $ctnNo,
            ':location' => readLineText($item, 'location'),
            ':comment' => readLineText($item, 'comment'),
            ':id' => $itemId,
            ':order_id' => $orderId
        ]);
        $updatedCount += $updateItem->rowCount();
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM order_items
        WHERE order_id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);

    if ((int)$stmt->fetchColumn() === 0) {
        throw new Exception('An order must keep at least one line.');
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'updated_count' => $updatedCount,
        'deleted_count' => $deletedCount
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function lockEditableOrderLine(PDOStatement $findItem, int $orderId, int $itemId): array
{
    $findItem->execute([
        ':id' => $itemId,
        ':order_id' => $orderId
    ]);
    $line = $findItem->fetch(PDO::FETCH_ASSOC);

    if (!$line) {
        throw new Exception('An order line could not be found. Refresh and try again.');
    }

    return $line;
}

function assertOrderLineCanBeDeleted(array $line): void
{
    if ((string)($line['picked_done'] ?? '') === '1' || !empty($line['stock_deducted_at'])) {
        throw new Exception('Completed lines cannot be deleted because their stock has already been processed.');
    }
}

function readLineText(array $item, string $field): string
{
    $value = $item[$field] ?? '';

    if (!is_scalar($value) && $value !== null) {
        throw new Exception('Invalid value for ' . $field . '.');
    }

    return trim((string)$value);
}
