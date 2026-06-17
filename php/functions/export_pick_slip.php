<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('Invalid order ID.');
}

$pdo = db();

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
$stmt->execute([':id' => $id]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
}

$stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC");
$stmt->execute([':id' => $id]);
$items = $stmt->fetchAll();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Picking List');

$sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(9);

/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/
$sheet->setCellValue('A1', 'Lanocorp New Zealand Ltd');
$sheet->setCellValue('B1', 'P O Box 86051, Rolleston 7658');
$sheet->setCellValue('C1', 'Corner Link & Hynds Drive');
$sheet->setCellValue('D1', 'Rolleston');
$sheet->getStyle('A1:D1')->getFont()->setName('Calibri')->setSize(11)->setBold(true);

$sheet->setCellValue('A2', 'PICKING LIST');
$sheet->getStyle('A2')->getFont()->setName('Calibri')->setSize(16)->setBold(true);

$sheet->setCellValue('B2', '');
$sheet->setCellValue('C2', '97-473-315');
$sheet->getStyle('C2')->getFont()->setName('Calibri')->setSize(11)->setBold(true);

$sheet->setCellValue('D2', 'Invoice Number:');
$sheet->getStyle('D2')->getFont()->setName('Calibri')->setSize(9)->setBold(true);

$sheet->setCellValue('E2', $order['invoice_no'] ?? '');
$sheet->getStyle('E2')->getFont()->setName('Calibri')->setSize(9);

$sheet->setCellValue('A3', $order['customer_code'] ?? '');
$sheet->getStyle('A3')->getFont()->setName('Calibri')->setSize(9)->setBold(true);

$sheet->setCellValue('B4', $order['customer_name'] ?? '');
$sheet->mergeCells('C4:D4');
$sheet->setCellValue('C4', $order['customer_address'] ?? '');

$sheet->getStyle('B4:D4')->getFont()->setName('Calibri')->setSize(9)->setBold(true);
$sheet->getStyle('B4:D4')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()
    ->setARGB('FFFFFF00');

$sheet->setCellValue('A5', 'Date');
$sheet->setCellValue('B5', 'Delivery');
$sheet->setCellValue('C5', 'Customer');
$sheet->setCellValue('D5', 'Order Number');
$sheet->setCellValue('E5', 'Packing Slip');
$sheet->setCellValue('F5', 'Internal Reference');
$sheet->setCellValue('G5', 'Sales Person');
$sheet->getStyle('A5:G5')->getFont()->setName('Calibri')->setSize(9)->setBold(true);

$sheet->setCellValue('A6', $order['order_date'] ?? '');
$sheet->setCellValue('B6', $order['delivery_date'] ?? '');
$sheet->setCellValue('C6', $order['customer_code'] ?? '');
$sheet->setCellValue('D6', $order['order_number'] ?? '');
$sheet->setCellValue('E6', $order['packing_slip'] ?? '');
$sheet->setCellValue('F6', $order['internal_reference'] ?? '');
$sheet->setCellValue('G6', $order['sales_person'] ?? '');

/*
|--------------------------------------------------------------------------
| Table
|--------------------------------------------------------------------------
*/
$startRow = 7;

$headers = [
    'Code',
    'BATCH EXPIRY',
    'Description',
    'Quantity',
    'TOTAL',
    'QTY SUPPLIED',
    'UNITS/CTN',
    'NO. FULL CTN',
    'CTN #',
    'LOCATION',
    'COMMENT'
];

$columns = range('A', 'K');

foreach ($headers as $index => $header) {
    $cell = $columns[$index] . $startRow;
    $sheet->setCellValue($cell, $header);
}

$sheet->getStyle("A{$startRow}:K{$startRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$startRow}:K{$startRow}")->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);

$row = $startRow + 1;

foreach ($items as $item) {
    $batchNo = (string)($item['batch_no'] ?? '');
    $location = (string)($item['location'] ?? '');
    $comment = (string)($item['comment'] ?? '');
    $unitsPerCtn = (string)($item['units_per_ctn'] ?? '');
    $fullCtn = (string)($item['full_ctn'] ?? '');
    $ctnNo = (string)($item['picked_ctn_no'] ?? $item['ctn_no'] ?? '');
    if (stripos($batchNo, 'NO STOCK') !== false) {
    $ctnNo = 'NO STOCK';
}

    $sheet->setCellValue("A{$row}", $item['sku_code'] ?? '');
    $sheet->setCellValue("B{$row}", richNoStock($batchNo));
    $sheet->setCellValue("C{$row}", $item['description'] ?? '');
    $sheet->setCellValue("D{$row}", $item['order_qty'] ?? '');
    $sheet->setCellValue("E{$row}", $item['total_qty'] ?? '');
    $sheet->setCellValue("F{$row}", richNoStock((string)($item['qty_supplied'] ?? '')));
    $sheet->setCellValue("G{$row}", richNoStock($unitsPerCtn));
    $sheet->setCellValue("H{$row}", richNoStock($fullCtn));
    $sheet->setCellValue("I{$row}", richNoStock($ctnNo));
    $sheet->setCellValue("J{$row}", richNoStock($location));
    $sheet->setCellValue("K{$row}", richNoStock($comment));

   $sheet->getStyle("D{$row}:K{$row}")
    ->getAlignment()
    ->setWrapText(true)
    ->setVertical(Alignment::VERTICAL_CENTER)
    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->getStyle("A{$row}:C{$row}")
    ->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
    ->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->getRowDimension($row)->setRowHeight(24);

   $isNoStock =
            stripos($batchNo, 'NO STOCK') !== false ||
            stripos($location, 'NO STOCK') !== false ||
            stripos($ctnNo, 'NO STOCK') !== false ||
            stripos($unitsPerCtn, 'NO STOCK') !== false ||
            stripos($comment, 'NO STOCK') !== false;

    $orderQty = toNumber($item['order_qty'] ?? '');
    $totalQty = toNumber($item['total_qty'] ?? '');
    $qtySupplied = toNumber($item['qty_supplied'] ?? '');

    $isQtyMismatch =
        $qtySupplied !== null &&
        (
            ($orderQty !== null && abs($qtySupplied - $orderQty) > 0.0001) ||
            ($totalQty !== null && abs($qtySupplied - $totalQty) > 0.0001)
        );

    if ($isNoStock || $isQtyMismatch) {
        $sheet->getStyle("D{$row}:F{$row}")
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFF3EFA7');
    }

    $row++;
}

$lastTableRow = $row - 1;

$sheet->getStyle("A{$startRow}:K{$lastTableRow}")
    ->getBorders()
    ->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN);

/*
|--------------------------------------------------------------------------
| Footer after table
|--------------------------------------------------------------------------
*/
$footerRow = $lastTableRow + 2;

$sheet->setCellValue("A{$footerRow}", 'Checked by:');
$sheet->setCellValue(
    "B{$footerRow}",
    trim(($order['checker_name'] ?? '') . ' ' . ($order['checked_at'] ?? ''))
);

$sheet->setCellValue("E{$footerRow}", 'Packed by:');
$sheet->mergeCells("F{$footerRow}:G{$footerRow}");
$sheet->setCellValue("F{$footerRow}", $order['picker_name'] ?? '');

$sheet->getStyle("A{$footerRow}:G{$footerRow}")->getFont()->setBold(true);

$courierRow = $footerRow + 2;

$sheet->setCellValue("A{$courierRow}", $order['courier_name'] ?? '');
$sheet->setCellValue("B{$courierRow}", $order['courier_reference'] ?? '');

$sheet->getStyle("A{$courierRow}:B{$courierRow}")->getFont()->setBold(true);

/*
|--------------------------------------------------------------------------
| Column widths
|--------------------------------------------------------------------------
*/
$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(37);
$sheet->getColumnDimension('C')->setWidth(65); // approx 395px
$sheet->getColumnDimension('D')->setWidth(16);
$sheet->getColumnDimension('E')->setWidth(12);
$sheet->getColumnDimension('F')->setWidth(15);
$sheet->getColumnDimension('G')->setWidth(12);
$sheet->getColumnDimension('H')->setWidth(12);
$sheet->getColumnDimension('I')->setWidth(12);
$sheet->getColumnDimension('J')->setWidth(12);
$sheet->getColumnDimension('K')->setWidth(20);

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/
$filename = 'pick_slip_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $order['invoice_no'] ?? (string)$id) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function multiline(string $value): string
{
    return str_replace(' | ', "\n", $value);
}

function richNoStock(string $value): RichText|string
{
    $value = multiline($value);

    if (stripos($value, 'NO STOCK') === false) {
        return $value;
    }

    $rich = new RichText();

    $lines = explode("\n", $value);
    $lastIndex = count($lines) - 1;

    foreach ($lines as $lineIndex => $line) {
        $parts = preg_split('/(NO STOCK)/i', $line, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $run = $rich->createTextRun($part);

            if (strcasecmp($part, 'NO STOCK') === 0) {
                $run->getFont()->setBold(true);
                $run->getFont()->getColor()->setARGB(Color::COLOR_RED);
            }
        }

        if ($lineIndex !== $lastIndex) {
            $rich->createText("\n");
        }
    }

    return $rich;
}

function toNumber(mixed $value): ?float
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
        return (float)$m[0];
    }

    return null;
}