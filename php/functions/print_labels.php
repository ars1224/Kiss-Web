<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

if (ob_get_level()) { ob_end_clean(); }
ob_start();

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // TCPDF
require_once __DIR__ . '/../util/inventory_helper.php';

$table = inventoryTable();

$requestedInventory = strtolower(trim(
    (string)($_GET['inventory'] ?? $_POST['inventory'] ?? '')
));

if ($table === 'all') {

    if (in_array($requestedInventory, ['components', 'component', 'packaging'], true)) {

        $table = 'componentlocation';

    } elseif (in_array($requestedInventory, ['rm', 'raw', 'rawmat', 'raw_materials'], true)) {

        $table = 'rmlocation';

    } else {

        $table = 'productlocation';
    }
}

// -------------------- Helpers --------------------
function json_fail(int $code, string $message): void {
  http_response_code($code);
  if (ob_get_level()) { ob_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'message' => $message]);
  exit;
}

function post_str(string $k, string $default=''): string {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
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
  int $minSize = 15
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

/**
 * Left aligned text that auto-shrinks to fit width.
 */
function pdf_right_fit(
  TCPDF $pdf,
  float $x,
  float $y,
  float $w,
  float $h,
  string $text,
  string $font,
  string $style,
  int $maxSize,
  int $minSize = 9
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
  $pdf->Cell($w, $h, $text, 0, 1, 'R', false, '', 0, false, 'T', 'M');
}

// -------------------- Input --------------------
$mode = post_str('mode', 'saved'); // saved | draft
$rows = [];

if ($mode === 'saved') {
  $raw = post_str('ids');
  if ($raw === '') json_fail(400, 'No selected IDs.');

  $ids = json_decode($raw, true);
  if (!is_array($ids)) json_fail(400, 'Invalid ids JSON.');

  $ids = array_values(array_unique(array_map('intval', $ids)));
  $ids = array_filter($ids, fn($x) => $x > 0);
  if (!$ids) json_fail(400, 'No valid IDs.');

  $pdo = db();
  $in  = implode(',', array_fill(0, count($ids), '?'));

  $sql = "
    SELECT EntryID, Location, SKU_Code, BatchNo, ExpiryDate, UnitType, QtyPerCtn, TotalQty, Comments, LastUpdated
    FROM {$table}
    WHERE EntryID IN ($in)
    ORDER BY FIELD(EntryID, " . implode(',', $ids) . ")
  ";

  $st = $pdo->prepare($sql);
  $st->execute($ids);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (!$rows) json_fail(404, 'No rows found.');

} elseif ($mode === 'draft') {
  $raw = post_str('rows');
  if ($raw === '') json_fail(400, 'No rows data.');

  $tmp = json_decode($raw, true);
  if (!is_array($tmp) || !$tmp) json_fail(400, 'Invalid rows JSON.');

  foreach ($tmp as $r) {
    if (!is_array($r)) continue;
    $rows[] = [
      'EntryID'    => null,
      'Location'   => (string)($r['Location'] ?? ''),
      'SKU_Code'   => (string)($r['SKU_Code'] ?? ''),
      'BatchNo'    => (string)($r['BatchNo'] ?? ''),
      'ExpiryDate' => (string)($r['ExpiryDate'] ?? ''),
      'UnitType'   => (string)($r['UnitType'] ?? ''),
      'QtyPerCtn'  => (int)($r['QtyPerCtn'] ?? 0),
      'TotalQty'   => (int)($r['TotalQty'] ?? 0),
      'Comments'   => (string)($r['Comments'] ?? ''),
      'LastUpdated'=> (string)($r['LastUpdated'] ?? ''),
    ];
  }

  if (!$rows) json_fail(400, 'No usable rows.');

} else {
  json_fail(400, 'Invalid mode.');
}

// --------- Label size: 149mm x 100mm (landscape) ----------
$pageW = 210.0;
$pageH = 152.0;

// IMPORTANT: SHIFT LEFT to compensate printer dead-zone
// Start with -6.0 (common). If still clipped left, use -7 or -8.
// If too far left, reduce to -5 or -4.
$SHIFT_X = 0.0;   // <<< TUNE THIS
$SHIFT_Y = 0.0;

$PAD_L = 0.0;
$PAD_R = 0.0;
$PAD_T = 0.0;
$PAD_B = 0.0;
// Build PDF
$pdf = new TCPDF('l', 'mm', [$pageW, $pageH], true, 'UTF-8', false);
$pdf->SetCreator('KISS-Web');
$pdf->SetTitle('Product Label');
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false, 0);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

$printX = $SHIFT_X;   // can be negative
$printY = $SHIFT_Y;
$printW = $pageW;     // DO NOT add offset here
$printH = $pageH;

foreach ($rows as $r) {
  // Build printable content box (use your PAD values!)
$boxX = $PAD_L;
$boxY = $PAD_T;
$boxW = $pageW - ($PAD_L + $PAD_R);
$boxH = $pageH - ($PAD_T + $PAD_B);

  $pdf->AddPage();

  $location = trim((string)($r['Location'] ?? ''));
  $sku      = trim((string)($r['SKU_Code'] ?? ''));
  $batch    = trim((string)($r['BatchNo'] ?? ''));
  $exp      = trim((string)($r['ExpiryDate'] ?? ''));
  $qty      = (int)($r['TotalQty'] ?? 0);
  $note     = trim((string)($r['Comments'] ?? ''));

  $bigTop = $sku !== '' ? $sku : '-';
  $mid    = trim(($batch !== '' ? $batch : '') . ($exp !== '' ? "  EXP $exp" : ''));
  $bigQty = number_format($qty);
  $loc    = $location;
  $notes = $note ;

$updateRaw = trim((string)($r['LastUpdated'] ?? ''));
  $update    = ($updateRaw === '' || str_starts_with($updateRaw, '0000-00-00')) ? '' : $updateRaw;

  // === OPTIONAL DEBUG BOX (uncomment to see content area) ===
   //$pdf->Rect($boxX, $boxY, $boxW, $boxH);

  // TOP SKU (big)
  pdf_center_fit($pdf, $boxX, 15, $boxW, 20, $bigTop, 'helvetica', 'B', 105, 65);

  // MID batch + exp
  pdf_center_fit($pdf, $boxX, 60, $boxW, 20, $mid, 'helvetica', 'B', 20, 16);
  
  // BIG QTY
  pdf_center_fit($pdf, $boxX, 75, $boxW, 20, $bigQty, 'helvetica', 'B', 90, 50);

  // BOTTOM location
   $loc = trim($location . ($update !== '' ? "  •  Updated: $update" : ''));
  pdf_center_fit($pdf, $boxX, 105, $boxW, 20, $loc, 'helvetica', 'B', 25, 14);

  pdf_right_fit($pdf, $boxX, 2, $boxW, 20, $note, 'helvetica', 'B', 15, 12);
}


// -------------------- Print silently (TEMP file, then delete) --------------------
$pdfBytes = $pdf->Output('', 'S');

$tmpPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'label_' . uniqid('', true) . '.pdf';
if (@file_put_contents($tmpPdf, $pdfBytes) === false) {
  json_fail(500, 'Failed to write temporary PDF.');
}

$cmd = 'C:\\print\\print_pdf.cmd ' . escapeshellarg($tmpPdf) . ' 2>&1';
$out = shell_exec($cmd);

@unlink($tmpPdf);

// -------------------- Return JSON ALWAYS --------------------
if (ob_get_level()) { ob_clean(); }
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
  'ok'      => true,
  'message' => 'Printed',
  'debug'   => $out
]);
exit;
