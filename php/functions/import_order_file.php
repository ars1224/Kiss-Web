<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

try {
    if (!isset($_FILES['order_file'])) {
        throw new Exception('No file uploaded.');
    }

    $tmpPath = $_FILES['order_file']['tmp_name'];

    if (!is_uploaded_file($tmpPath)) {
        throw new Exception('Invalid uploaded file.');
    }

    $spreadsheet = IOFactory::load($tmpPath);
    $sheet = $spreadsheet->getActiveSheet();

    $header = [
        'invoice_no' => cleanCell($sheet->getCell('E2')->getFormattedValue()),
        'order_date' => normalizeExcelDate(
            $sheet->getCell('A6')->getValue(),
            $sheet->getCell('A6')->getFormattedValue()
        ),
        'delivery_date' => normalizeExcelDate(
            $sheet->getCell('B6')->getValue(),
            $sheet->getCell('B6')->getFormattedValue()
        ),
        'customer_code' => cleanCell($sheet->getCell('C6')->getFormattedValue()),
        'customer_name' => cleanCell($sheet->getCell('B4')->getFormattedValue()),
        'customer_address' => cleanAddress($sheet, ['C4', 'D4', 'E4']),
        'order_number' => cleanCell($sheet->getCell('D6')->getFormattedValue()),
        'packing_slip' => cleanCell($sheet->getCell('E6')->getFormattedValue()),
        'internal_reference' => cleanCell($sheet->getCell('F6')->getFormattedValue()),
        'purchase_number' => cleanCell($sheet->getCell('H6')->getFormattedValue()),
        'sales_person' => cleanCell($sheet->getCell('G6')->getFormattedValue()),
    ];

    if ($header['order_date'] === '') {
        $header['order_date'] = date('Y-m-d');
    }

    $orderLines = [];
    $highestRow = $sheet->getHighestRow();

    for ($row = 8; $row <= $highestRow; $row++) {
        $sku = cleanCell($sheet->getCell('A' . $row)->getFormattedValue());
        $description = cleanCell($sheet->getCell('C' . $row)->getFormattedValue());
        $qtyRaw = cleanCell($sheet->getCell('D' . $row)->getFormattedValue());

        if ($sku === '' && $description === '' && $qtyRaw === '') {
            continue;
        }

        if ($sku === '' || strtoupper($sku) === 'CODE') {
            continue;
        }

        $qty = parseQty($qtyRaw);

        if ($qty <= 0) {
            continue;
        }

        $orderLines[] = [
            'sku_code' => $sku,
            'description' => $description,
            'quantity' => $qty,
        ];
    }

    echo json_encode([
        'success' => true,
        'order_header' => $header,
        'order_lines' => $orderLines,
    ]);

    exit;

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);

    exit;
}

function cleanCell(string $value): string
{
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function cleanAddress($sheet, array $cells): string
{
    $parts = [];

    foreach ($cells as $cell) {
        $value = cleanCell($sheet->getCell($cell)->getFormattedValue());

        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(' ', $parts);
}

function parseQty(string $value): float
{
    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);

    if ($value === '' || $value === '-' || $value === '.') {
        return 0;
    }

    return (float)$value;
}

function normalizeExcelDate(mixed $rawValue, string $formattedValue): string
{
    if (is_numeric($rawValue)) {
        try {
            return ExcelDate::excelToDateTimeObject((float)$rawValue)->format('Y-m-d');
        } catch (Throwable $e) {
            // Continue to formatted fallback.
        }
    }

    $value = trim($formattedValue);

    if ($value === '') {
        return '';
    }

    $formats = [
        'd/m/Y',
        'd-m-Y',
        'Y-m-d',
        'm/d/Y',
        'd/m/y',
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);

        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);

    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
}