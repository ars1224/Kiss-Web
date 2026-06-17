<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

if (ob_get_level()) { ob_end_clean(); }
ob_start();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

function json_fail(int $code, string $message): void {
    http_response_code($code);
    if (ob_get_level()) { ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

function split_lines(string $value): array {
    $value = trim($value);
    if ($value === '') return [];

    return array_values(array_filter(array_map(
        'trim',
        preg_split('/[|,]/', $value)
    ), fn($v) => $v !== ''));
}

function expand_ctn_numbers(string $value): array {
    $parts = split_lines($value);
    $numbers = [];

    foreach ($parts as $part) {
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
            for ($i = (int)$m[1]; $i <= (int)$m[2]; $i++) {
                $numbers[] = $i;
            }
        } elseif (preg_match('/^\d+$/', $part)) {
            $numbers[] = (int)$part;
        }
    }

    return array_values(array_unique($numbers));
}

function pdf_center_fit(
    TCPDF $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    string $text,
    string $font,
    string $style,
    int $maxSize,
    int $minSize = 12
): void {
    $text = trim($text);
    if ($text === '') return;

    $size = $maxSize;

    while ($size > $minSize) {
        $pdf->SetFont($font, $style, $size);
        if ($pdf->GetStringWidth($text) <= ($w - 2)) break;
        $size--;
    }

    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, $text, 0, 1, 'C', false, '', 0, false, 'T', 'M');
}

$orderId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$itemId  = (int)($_GET['item_id'] ?? $_POST['item_id'] ?? 0);

if ($orderId <= 0 && $itemId <= 0) {
    json_fail(400, 'Invalid order ID.');
}

$pdo = db();

/*
|--------------------------------------------------------------------------
| Load item first if printing only one SKU
|--------------------------------------------------------------------------
*/
if ($itemId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE id = :item_id
        LIMIT 1
    ");
    $stmt->execute([':item_id' => $itemId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        json_fail(404, 'Order item not found.');
    }

    $orderId = (int)$items[0]['order_id'];
} else {
    $stmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ");
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$items) {
    json_fail(404, 'No order items found.');
}

/*
|--------------------------------------------------------------------------
| Load order after orderId is known
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT *
    FROM orders
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    json_fail(404, 'Order not found.');
}

$labels = [];

foreach ($items as $item) {
    $sku = trim((string)($item['sku_code'] ?? ''));
    $description = trim((string)($item['description'] ?? ''));

    $ctnRaw = trim((string)(($item['picked_ctn_no'] ?? '') ?: ($item['ctn_no'] ?? '')));

    if ($ctnRaw === '' || strtoupper($ctnRaw) === 'NO STOCK') {
        continue;
    }

    $ctnNumbers = expand_ctn_numbers($ctnRaw);

    if (!$ctnNumbers) {
        continue;
    }

    $locationText = implode(' | ', split_lines((string)($item['location'] ?? '')));
    $batchText = implode(' | ', split_lines((string)($item['batch_no'] ?? '')));

    foreach ($ctnNumbers as $ctnNo) {
        $labels[] = [
            'ctn_no' => $ctnNo,
            'sku' => $sku,
            'description' => $description,
            'location' => $locationText,
            'batch' => $batchText,
        ];
    }
}

if (!$labels) {
    json_fail(404, 'No carton labels to print.');
}

usort($labels, fn($a, $b) => $b['ctn_no'] <=> $a['ctn_no']);

$totalCartons = max(array_column($labels, 'ctn_no'));

$pageW = 210.0;
$pageH = 152.0;

$pdf = new TCPDF('l', 'mm', [$pageW, $pageH], true, 'UTF-8', false);
$pdf->SetCreator('KISS-Web');
$pdf->SetTitle('Carton Labels');
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false, 0);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

foreach ($labels as $label) {
    $pdf->AddPage();

    $customer = trim((string)($order['customer_name'] ?? ''));
    $address  = trim((string)($order['customer_address'] ?? ''));
    $orderNo  = trim((string)($order['order_number'] ?? ''));
    $packing  = trim((string)($order['invoice_no'] ?? ''));
    $ctnNo    = (string)$label['ctn_no'];


    // FROM
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(6, 1);
    $pdf->Cell(80, 6, 'From:', 0, 1);

    $pdf->SetFont('helvetica', 'B', 25);
    $pdf->SetXY(6, 5);
    $pdf->MultiCell(90, 8, "Lanocorp Pacific Ltd", 0, 'L');

    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetXY(6, 15);
    $pdf->MultiCell(80, 5, "2 HYNDS DRIVE\nROLLESTON\nCHRISTCHURCH\nNEW ZEALAND", 0, 'L');

        // ORDER / PACKING
    $pdf->SetFont('helvetica', 'B', 30);

    $pdf->SetXY(110, 1);
    $pdf->Cell(90, 8,'PO  ' . ($orderNo !== '' ? $orderNo : '<Order Number>'),0,1,'C');

    $pdf->SetXY(110, 15);
    $pdf->Cell(90, 8, 'PS  ' . ($packing !== '' ? $packing : '<Packing Slip Number>'), 0, 1, 'C');

    // SKU / QTY CTN
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetXY(38, 57);

    $pdf->SetXY(38, 57);
    $pdf->Cell(120,8,'SKU: ' . $label['sku'],0,1,'L');

    // CARTON BIG
    $pdf->SetFont('helvetica', 'B', 50);
    $pdf->SetXY(112, 30);
    $pdf->Cell(85, 20, 'Carton #', 0, 1, 'C');

    $pdf->SetFont('helvetica', 'B', 65);
    $pdf->SetXY(112, 50);
    $pdf->Cell(85, 25, $ctnNo, 0, 1, 'C');

    // TO
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetXY(6, 65);
    $pdf->Cell(80, 6, 'To:', 0, 1);

    $pdf->SetFont('helvetica', 'B', 25);
    $pdf->SetXY(6, 72);
    $pdf->MultiCell(200, 15, strtoupper($customer), 0, 'L');

    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetXY(6, 85);
    $pdf->MultiCell(180, 15, strtoupper($address), 0, 'L');

    // City fallback
    $city = '';
    if (stripos($address, 'AUCKLAND') !== false) $city = 'AUCKLAND';
    elseif (stripos($address, 'CHRISTCHURCH') !== false) $city = 'CHRISTCHURCH';
    elseif (stripos($address, 'WELLINGTON') !== false) $city = 'WELLINGTON';
    elseif (stripos($address, 'QUEENSTOWN') !== false) $city = 'QUEENSTOWN';
    elseif (stripos($address, 'TARRAS') !== false) $city = 'TARRAS';
    elseif (stripos($address, 'HANMER SPRINGS') !== false) $city = 'HANMER SPRINGS';
    elseif (stripos($address, 'LAKE TEKAPO') !== false) $city = 'LAKE TEKAPO';
    elseif (stripos($address, 'USA') !== false) $city = 'USA';

    $pdf->SetFont('helvetica', 'B', 50);
    $pdf->SetXY(6, 100);
    $pdf->Cell(120, 12, $city, 0, 1, 'L');
}

$pdfBytes = $pdf->Output('', 'S');

$tmpPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carton_labels_' . uniqid('', true) . '.pdf';

if (@file_put_contents($tmpPdf, $pdfBytes) === false) {
    json_fail(500, 'Failed to write temporary PDF.');
}

$cmd = 'C:\\print\\print_pdf.cmd ' . escapeshellarg($tmpPdf) . ' 2>&1';
$out = shell_exec($cmd);

@unlink($tmpPdf);

if (ob_get_level()) { ob_clean(); }
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'message' => 'Carton labels printed',
    'count' => count($labels),
    'debug' => $out
]);
exit;