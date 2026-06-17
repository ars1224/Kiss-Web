<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../util/order_repo.php';
require_once __DIR__ . '/../util/notification_helper.php';

$repo = null;

try {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        throw new Exception('Invalid request payload.');
    }

    $action = (string)($input['action'] ?? '');
    $header = $input['order_header'] ?? [];
    $lines = $input['order_lines'] ?? [];

    if (empty($header['invoice_no'])) {
        throw new Exception('Invoice number is required.');
    }

    if (empty($header['customer_name'])) {
        throw new Exception('Customer name is required.');
    }

    if (empty($header['order_date'])) {
        throw new Exception('Date today is required.');
    }

    if (!is_array($lines) || count($lines) === 0) {
        throw new Exception('At least one order line is required.');
    }

    $roundingEnabled = isset($header['rounding_mode']) && (string)$header['rounding_mode'] === '1';
    $minimumShelfLifeMonths = normalizeShelfLifeMonths(
        $header['min_shelf_life_months'] ?? 6
    );
    $header['min_shelf_life_months'] = $minimumShelfLifeMonths;

    $repo = new OrderRepository();

    $previewRows = buildPickingListRows($repo, $lines, $roundingEnabled, $header);
    $startCtn = 1;

    if (!empty($input['order_id'])) {
        $startCtn = getNextCartonNumber((int)$input['order_id']);
    }

    applySequentialCartonNumbers($previewRows, $startCtn);

    if ($action === 'preview') {
        echo json_encode([
            'success' => true,
            'preview_rows' => $previewRows,
            'can_print_labels' => true,
            'label_message' => 'Carton numbers populated.'
        ]);
        exit;
    }

    if ($action === 'save') {
        $invoiceNo = trim((string)($header['invoice_no'] ?? ''));
        $orderIdForEdit = (int)($input['order_id'] ?? 0);

        $pdo = db();

        $stmt = $pdo->prepare("
            SELECT id
            FROM orders
            WHERE invoice_no = :invoice_no
              AND id != :order_id
            LIMIT 1
        ");

        $stmt->execute([
            ':invoice_no' => $invoiceNo,
            ':order_id' => $orderIdForEdit
        ]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invoice number already exists.'
            ]);
            exit;
        }

        $repo->beginTransaction();

        if ($orderIdForEdit > 0) {
            $repo->updateOrder($orderIdForEdit, $header);
            $repo->deleteEditableOrderItems($orderIdForEdit);
            $orderId = $orderIdForEdit;
        } else {
            $orderId = $repo->createOrder($header);
        }

        foreach ($previewRows as $row) {
            $repo->insertOrderItem($orderId, $row);
        }

        $repo->commit();

        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'preview_rows' => $previewRows
        ]);
        exit;
    }

    throw new Exception('Unsupported action.');

} catch (Throwable $e) {
    if ($repo instanceof OrderRepository) {
        $repo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}

function buildPickingListRows(
    OrderRepository $repo,
    array $lines,
    bool $roundingEnabled,
    array $header
): array {
    $groupedLines = groupImportedLines($lines);
    $result = [];

    foreach ($groupedLines as $group) {
        $sku = $group['sku_code'];
        $description = $group['description'];
        $orderQtyParts = $group['order_qty_parts'];
        $requestedQty = (float)$group['requested_qty'];

        $stockRows = $repo->getAvailableStockBySku($sku);
        $customerName = trim((string)($header['customer_name'] ?? ''));
        $minimumShelfLifeMonths = normalizeShelfLifeMonths(
            $header['min_shelf_life_months'] ?? 6
        );

        $stockRows = array_values(array_filter($stockRows, function ($stock) use ($customerName) {
            $comments = trim((string)($stock['Comments'] ?? ''));

            if (preg_match('/^(.*?)\s+only$/i', $comments, $match)) {
                $reservedCustomer = trim($match[1]);
                return strcasecmp($reservedCustomer, $customerName) === 0;
            }

            return true;
        }));

        $stockRows = array_values(array_filter($stockRows, function ($stock) use ($minimumShelfLifeMonths) {

            $comments = strtoupper(trim((string)($stock['Comments'] ?? '')));

            // skip ON HOLD stock
            if (str_contains($comments, 'ON HOLD')) {
                return false;
            }

            return isExpiryAllowed(
                (string)($stock['ExpiryDate'] ?? ''),
                $minimumShelfLifeMonths
            );
        }));

        $batchLines = [];
        $qtySuppliedLines = [];
        $unitsPerCtnLines = [];
        $noFullCtnLines = [];
        $ctnLines = [];
        $locationLines = [];
        $commentLines = [];

        $displayTotalQty = $requestedQty;
        $displayQtySupplied = $requestedQty;

        if (empty($stockRows)) {
            $batchLines[] = 'NO STOCK';
            $qtySuppliedLines[] = 'NO STOCK';
            $unitsPerCtnLines[] = 'NO STOCK';
            $noFullCtnLines[] = 'NO STOCK';
            $ctnLines[] = 'NO STOCK';
            $locationLines[] = 'NO STOCK';
            $commentLines[] = $minimumShelfLifeMonths >= 18
                ? 'NO STOCK - REQUIRES 18+ MONTHS'
                : 'NO STOCK';
            $displayQtySupplied = 'NO STOCK';
        } else {
            $resolvedUnitsPerCtn = getResolvedUnitsPerCtn($stockRows);

            if ($roundingEnabled && $resolvedUnitsPerCtn > 0) {
                $cartonsRaw = $requestedQty / $resolvedUnitsPerCtn;
                $roundedCartons = max(
                    1,
                    (int) round($cartonsRaw, 0, PHP_ROUND_HALF_UP)
                );
                $displayQtySupplied = $resolvedUnitsPerCtn * $roundedCartons;
            }

            $remainingForDisplay = (float)$displayQtySupplied;

            foreach ($stockRows as $stock) {
                if ($remainingForDisplay <= 0) {
                    break;
                }

                $availableQty = (float)($stock['TotalQty'] ?? 0);
                if ($availableQty <= 0) {
                    continue;
                }

                $qtyPerCtn = (float)($stock['QtyPerCtn'] ?? 0);
                if ($qtyPerCtn <= 0) {
                    $qtyPerCtn = $resolvedUnitsPerCtn;
                }

                if ($roundingEnabled) {
                    if ($qtyPerCtn <= 0) {
                        continue;
                    }

                    $availableCartons = (int) floor($availableQty / $qtyPerCtn);
                    $neededCartons = (int) floor($remainingForDisplay / $qtyPerCtn);
                    $cartonsToTake = min($availableCartons, $neededCartons);

                    if ($cartonsToTake <= 0) {
                        continue;
                    }

                    $take = $cartonsToTake * $qtyPerCtn;
                    $fullCtn = $cartonsToTake;
                } else {
                    $take = min($remainingForDisplay, $availableQty);
                    $fullCtn = $qtyPerCtn > 0 ? (int) floor($take / $qtyPerCtn) : 0;
                }

                $batchNo = trim((string)($stock['BatchNo'] ?? ''));
                $expiryDate = trim((string)($stock['ExpiryDate'] ?? ''));
                $comments = trim((string)($stock['Comments'] ?? ''));
                $batchExpiry = trim($batchNo . ' ' . $expiryDate);

                $batchLines[] = $batchExpiry !== '' ? $batchExpiry : 'n/a';
                $qtySuppliedLines[] = formatNumber($take);
                $unitsPerCtnLines[] = $qtyPerCtn > 0 ? formatNumber($qtyPerCtn) : '';
                $noFullCtnLines[] = $fullCtn > 0 ? formatNumber((float)$fullCtn) : '';
                $ctnLines[] = '';
                $locationLines[] = (string)($stock['Location'] ?? '');
                $commentLines[] = $comments;

                $remainingForDisplay -= $take;
            }

            if (
                $roundingEnabled
                && empty($qtySuppliedLines)
                && $remainingForDisplay > 0
            ) {
                $batchLines[] = 'NO STOCK';
                $qtySuppliedLines[] = 'NO STOCK';
                $unitsPerCtnLines[] = 'NO STOCK';
                $noFullCtnLines[] = 'NO STOCK';
                $ctnLines[] = 'NO STOCK';
                $locationLines[] = 'NO STOCK';
                $commentLines[] = 'NO FULL CARTON';
                $displayQtySupplied = 'NO STOCK';
            } elseif ($remainingForDisplay > 0) {
                $displayQtySupplied = (float)$displayQtySupplied - $remainingForDisplay;

                if (count($commentLines) > 0) {
                    $commentLines[count($commentLines) - 1] = trim(
                        trim((string)$commentLines[count($commentLines) - 1]) . ' Short'
                    );
                } else {
                    $commentLines[] = 'Short';
                }


            }

            $lineCount = max(
                count($batchLines),
                count($qtySuppliedLines),
                count($unitsPerCtnLines),
                count($noFullCtnLines),
                count($ctnLines),
                count($locationLines),
                count($commentLines),
                1
            );

            $batchLines = padLines($batchLines, $lineCount);
            $qtySuppliedLines = padLines($qtySuppliedLines, $lineCount);
            $unitsPerCtnLines = padLines($unitsPerCtnLines, $lineCount);
            $noFullCtnLines = padLines($noFullCtnLines, $lineCount);
            $ctnLines = padLines($ctnLines, $lineCount);
            $locationLines = padLines($locationLines, $lineCount);
            $commentLines = padLines($commentLines, $lineCount);
        }

        $result[] = [
            'sku_code' => $sku,
            'description' => $description,

            'quantity_lines' => array_map('formatNumber', $orderQtyParts),
            'quantity' => implode(' | ', array_map('formatNumber', $orderQtyParts)),
            'total_qty' => formatNumber($displayTotalQty),

            'batch_expiry_lines' => $batchLines,
            'qty_supplied_lines' => $qtySuppliedLines,
            'total_qty_supplied' => is_numeric($displayQtySupplied)
                ? formatNumber((float)$displayQtySupplied)
                : (string)$displayQtySupplied,

            'qty_supplied_per_batch' => implode(' | ', $qtySuppliedLines),
            'units_per_ctn_lines' => $unitsPerCtnLines,
            'no_full_ctn_lines' => $noFullCtnLines,
            'ctn_no_lines' => $ctnLines,
            'location_lines' => $locationLines,
            'comment_lines' => $commentLines,

            'batch_expiry' => implode(' | ', $batchLines),
            'qty_supplied' => is_numeric($displayQtySupplied)
                ? formatNumber((float)$displayQtySupplied)
                : (string)$displayQtySupplied,
            'units_per_ctn' => implode(' | ', $unitsPerCtnLines),
            'no_full_ctn' => implode(' | ', $noFullCtnLines),
            'ctn_no' => implode(' | ', $ctnLines),
            'location' => implode(' | ', $locationLines),
            'comment' => implode(' | ', $commentLines),

            'order_qty' => $requestedQty,
            'batch_no' => '',
            'expiry_date' => ''
        ];
    }

    return $result;
}

function recalculateShortCartons(
    float $displayQtySupplied,
    float $resolvedUnitsPerCtn,
    array &$batchLines,
    array &$qtySuppliedLines,
    array &$unitsPerCtnLines,
    array &$noFullCtnLines,
    array &$ctnLines,
    array &$locationLines,
    array &$commentLines
): void {
    if ($resolvedUnitsPerCtn <= 0 || $displayQtySupplied <= 0) {
        return;
    }

    $sourceBatchLines = $batchLines;
    $sourceLocationLines = $locationLines;
    $sourceCommentLines = $commentLines;

    $batchLines = [];
    $qtySuppliedLines = [];
    $unitsPerCtnLines = [];
    $noFullCtnLines = [];
    $ctnLines = [];
    $locationLines = [];
    $commentLines = [];

    $remaining = $displayQtySupplied;

    foreach ($sourceBatchLines as $index => $batch) {
        if ($remaining <= 0) {
            break;
        }

        $location = $sourceLocationLines[$index] ?? '';
        $comment = $sourceCommentLines[$index] ?? '';

        $take = min($remaining, $displayQtySupplied);
        $fullCtn = (int) floor($take / $resolvedUnitsPerCtn);
        $fullQty = $fullCtn * $resolvedUnitsPerCtn;
        $partQty = $take - $fullQty;

        if ($fullQty > 0) {
            $batchLines[] = $batch;
            $qtySuppliedLines[] = formatNumber($fullQty);
            $unitsPerCtnLines[] = formatNumber($resolvedUnitsPerCtn);
            $noFullCtnLines[] = formatNumber((float)$fullCtn);
            $ctnLines[] = '';
            $locationLines[] = $location;
            $commentLines[] = $comment;
        }

        if ($partQty > 0) {
            $batchLines[] = $batch;
            $qtySuppliedLines[] = formatNumber($partQty);
            $unitsPerCtnLines[] = formatNumber($resolvedUnitsPerCtn);
            $noFullCtnLines[] = '';
            $ctnLines[] = '';
            $locationLines[] = $location;
            $commentLines[] = 'Short';
        }

        $remaining = 0;
    }
}

function applySequentialCartonNumbers(array &$previewRows, int $startCtn = 1): void
{
    $ctnCounter = $startCtn;

    foreach ($previewRows as &$row) {
        $noFullCtnLines = $row['no_full_ctn_lines'] ?? [];
        $qtySupplied = strtoupper(trim((string)($row['total_qty_supplied'] ?? '')));
        $ctnLines = [];

        if ($qtySupplied === 'NO STOCK') {
            $lineCount = max(count($noFullCtnLines), 1);
            $row['ctn_no_lines'] = array_fill(0, $lineCount, 'NO STOCK');
            $row['ctn_no'] = implode(' | ', $row['ctn_no_lines']);
            continue;
        }

        foreach ($noFullCtnLines as $fullCtn) {
            $fullCtn = trim((string)$fullCtn);

            if ($fullCtn === '' || strtoupper($fullCtn) === 'NO STOCK') {
                $ctnLines[] = '';
                continue;
            }

            $cartonCount = (int)$fullCtn;

            if ($cartonCount <= 0) {
                $ctnLines[] = '';
                continue;
            }

            $start = $ctnCounter;
            $end = $ctnCounter + $cartonCount - 1;

            $ctnLines[] = formatCtnRange($start, $end);
            $ctnCounter = $end + 1;
        }

        $row['ctn_no_lines'] = $ctnLines;
        $row['ctn_no'] = implode(' | ', $ctnLines);
    }
}

function getResolvedUnitsPerCtn(array $stockRows): float
{
    foreach ($stockRows as $stock) {
        $qtyPerCtn = (float)($stock['QtyPerCtn'] ?? 0);
        if ($qtyPerCtn > 0) {
            return $qtyPerCtn;
        }
    }

    return 0.0;
}

function groupImportedLines(array $lines): array
{
    $grouped = [];

    foreach ($lines as $line) {
        $sku = trim((string)($line['sku_code'] ?? ''));
        $description = trim((string)($line['description'] ?? ''));
        $qty = (float)($line['quantity'] ?? 0);

        if ($sku === '' || $qty <= 0) {
            continue;
        }

        $key = mb_strtoupper($sku . '|' . $description);

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'sku_code' => $sku,
                'description' => $description,
                'requested_qty' => 0.0,
                'order_qty_parts' => []
            ];
        }

        $grouped[$key]['requested_qty'] += $qty;
        $grouped[$key]['order_qty_parts'][] = $qty;
    }

    return array_values($grouped);
}

function padLines(array $lines, int $targetCount): array
{
    while (count($lines) < $targetCount) {
        $lines[] = '';
    }

    return $lines;
}

function formatNumber(float $value): string
{
    if ((float)(int)$value === $value) {
        return number_format($value, 0, '.', '');
    }

    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
}

function formatCtnRange(int $start, int $end): string
{
    if ($start <= 0 || $end <= 0) {
        return '';
    }

    return $start === $end
        ? (string)$start
        : $start . '-' . $end;
}

function normalizeShelfLifeMonths(mixed $value): int
{
    return (int)$value === 18 ? 18 : 6;
}

function isExpiryAllowed(string $expiryDate, int $minimumMonths = 6): bool
{
    $expiryDate = trim($expiryDate);

    if ($expiryDate === '') {
        return false;
    }

    if (!preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $expiryDate)) {
        return false;
    }

    [$month, $year] = explode('/', $expiryDate);

    $expiry = DateTime::createFromFormat('Y-m-d', $year . '-' . $month . '-01');
    if (!$expiry) {
        return false;
    }

    $expiry->modify('last day of this month');

    $minimumAllowed = new DateTime();
    $minimumAllowed->modify('+' . normalizeShelfLifeMonths($minimumMonths) . ' months');

    return $expiry >= $minimumAllowed;
}

function getNextCartonNumber(int $orderId): int
{
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT ctn_no, picked_ctn_no
        FROM order_items
        WHERE order_id = :order_id
          AND COALESCE(picked_done, '0') = '1'
    ");

    $stmt->execute([
        ':order_id' => $orderId
    ]);

    $max = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)(($row['picked_ctn_no'] ?? '') ?: ($row['ctn_no'] ?? '')));

        preg_match_all('/\d+/', $value, $matches);

        foreach ($matches[0] ?? [] as $num) {
            $max = max($max, (int)$num);
        }
    }

    return $max + 1;
}
