<?php
declare(strict_types=1);

// --- Clean download: no stray output ---
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);

// ✅ Correct for: /php/functions/export_file.php  -> /vendor/autoload.php
require_once __DIR__ . '/../../vendor/autoload.php';

// ✅ Correct for: /php/conn/db.php
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/inventory_helper.php';

$table = inventoryTable();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;




// ---- DB (your existing helper) ----

$pdo = db();
if (!($pdo instanceof PDO)) { http_response_code(500); exit('Database unavailable.'); }

// ---- Collect IDs robustly (array OR CSV string OR JSON) ----
$ids = [];

// 1) Standard array of checkboxes: EntryID[]
if (isset($_POST['EntryID']) && is_array($_POST['EntryID'])) {
  $ids = array_merge($ids, $_POST['EntryID']);
}
// 2) Single CSV field to bypass max_input_vars
if (!empty($_POST['ids_csv']) && is_string($_POST['ids_csv'])) {
  $ids = array_merge($ids, explode(',', $_POST['ids_csv']));
}
// 3) JSON body: {"ids":[...]}
if (empty($ids)) {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['ids']) && is_array($j['ids'])) {
      $ids = array_merge($ids, $j['ids']);
    }
  }
}

$ids = array_values(array_unique(array_map('intval', (array)$ids)));
$ids = array_filter($ids, fn($v) => $v > 0);
if (!$ids) { http_response_code(400); exit('No rows selected.'); }

// ---- Query (same columns you used) ----
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "
  SELECT
    SKU_Code      AS SKU_Code,
    TotalQty      AS TotalQty,
    BatchNo       AS BatchNo,
    ExpiryDate    AS ExpiryDate,
    UnitType      AS UnitType,
    QtyPerCtn     AS QtyPerCtn,
    Location      AS Location,
    Comments      AS Comments
  FROM `productlocation`
  WHERE EntryID IN ($placeholders)
  ORDER BY EntryID ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($ids);

// ---- Build Excel workbook ----
$ss = new Spreadsheet();
$sheet = $ss->getActiveSheet();


$coord = fn(int $col, int $row) => Coordinate::stringFromColumnIndex($col) . $row;

$setText = function(int $col, int $row, $value) use ($sheet, $coord) {
  $sheet->setCellValueExplicit(
    $coord($col, $row),
    (string)($value ?? ''),
    DataType::TYPE_STRING
  );
};

$setDateMMYY = function(int $col, int $row, $value) use ($sheet, $coord) {
  if ($value === null || $value === '') {
    $sheet->setCellValueExplicit($coord($col, $row), '', DataType::TYPE_STRING);
    return;
  }
  $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
  if ($ts === false) {
    $sheet->setCellValueExplicit($coord($col, $row), (string)$value, DataType::TYPE_STRING);
    return;
  }
  $excel = ExcelDate::PHPToExcel($ts);
  $cell  = $coord($col, $row);
  $sheet->setCellValue($cell, $excel);
  $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('MM/YY');
};
// Top rows like your CSV
$row = 1;
$setText(1, $row++, 'Kiss-Web Report');
$setText(1, $row++, date('Y-m-d H:i:s'));
$row++; // spacer

// Headers
$headers = ['SKU_Code','TotalQty','BatchNo','ExpiryDate','UnitType','QtyPerCtn','Location','Comments'];
$headerRow = $row;
foreach ($headers as $i => $h) {
  $setText($i + 1, $row, $h);
}
$sheet->getStyle('A'.$row.':H'.$row)->getFont()->setBold(true);
$row++;

// Data rows
while ($rec = $stmt->fetch(PDO::FETCH_ASSOC)) {
  foreach ($rec as $k => $v) if ($v === null) $rec[$k] = '';

  $col = 1;
  $setText($col++, $row, $rec['SKU_Code']);
  $setText($col++, $row, $rec['TotalQty']);
  $setText($col++, $row, $rec['BatchNo']);
  $setDateMMYY($col++, $row, $rec['ExpiryDate']); // MM/YY
  $setText($col++, $row, $rec['UnitType']);
  $setText($col++, $row, $rec['QtyPerCtn']);
  $setText($col++, $row, $rec['Location']);
  $setText($col++, $row, $rec['Comments']);
  $row++;
}

// Autosize columns A..H
foreach (range('A','H') as $col) {
  $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Freeze header (first data row)
$sheet->freezePane("A".($headerRow+1));

// Optional: AutoFilter on the header row
$sheet->setAutoFilter("A{$headerRow}:H".($row-1));

// ---- Output to browser ----
// Clear any previous output buffers to avoid corrupting the file
if (function_exists('ob_get_level')) {
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
}

// File name that the user will see in the download dialog
$fname = 'WHL ' . date('d-m-Y') . '.xlsx';

// Headers to tell the browser to download the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

// Output Excel to the browser (no server-side file saved)
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
