    <?php
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../conn/db.php';
    require_once __DIR__ . '/../util/transaction_repo.php';
    require_once __DIR__ . '/../util/inventory_helper.php';
    require_once __DIR__ . '/../util/notification_helper.php';

    $table = inventoryTable();

    $requestedInventory = strtolower(trim(
    (string)($_POST['inventory'] ?? $_GET['inventory'] ?? '')
));

if ($table === 'all') {
    if (in_array($requestedInventory, ['components', 'component', 'packaging'], true)) {
        $table = 'componentlocation';
        $inventoryType = 'packaging';
    } elseif (in_array($requestedInventory, ['rm', 'raw', 'rawmat', 'raw_materials'], true)) {
        $table = 'rmlocation';
        $inventoryType = 'rm';
    } else {
        $table = 'productlocation';
        $inventoryType = 'products';
    }
} else {
    $inventoryType = inventoryType();
}

    use PhpOffice\PhpSpreadsheet\IOFactory;

    $REQUIRED_HEADER = ['Location','SKU_Code','BatchNo','ExpiryDate','UnitType','QtyPerCtn','TotalQty','Comments'];

    function s($v): string {
        return trim((string)($v ?? ''));
    }

    function n($v): int {
        $v = preg_replace('/[^\d-]/', '', (string)$v);
        $i = (int)$v;
        return max(0, $i);
    }

    function norm_expiry($v) {
        $t = s($v);
        if ($t === '') return null;
        if (!preg_match('/^(0?[1-9]|1[0-2])\/\d{4}$/', $t)) return false;

        return preg_replace_callback(
            '/^(\d{1,2})\/(\d{4})$/',
            fn($m) => sprintf('%02d/%s', (int)$m[1], $m[2]),
            $t
        );
    }

    function redirect_with_result(string $message, bool $isError = false): void {
        $key = $isError ? 'error' : 'success';
        header('Location: ../../Location.php?' . http_build_query([$key => $message]));
        exit;
    }

    function normalize_header(array $row): array {
        return array_map(fn($v) => trim((string)$v), $row);
    }

    function excel_date_to_mmyyyy($value): ?string {
        if ($value === null || $value === '') return null;

        $text = trim((string)$value);
        if (preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $text)) {
            return $text;
        }

        if (is_numeric($value)) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
                return $dt->format('m/Y');
            } catch (Throwable $e) {
                return null;
            }
        }

        $ts = strtotime($text);
        if ($ts !== false) {
            return date('m/Y', $ts);
        }

        return $text;
    }

    if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        redirect_with_result('No file uploaded.', true);
    }

    $name = $_FILES['file']['name'] ?? '';
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') {
        redirect_with_result('Please upload an .xlsx file.', true);
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);
    } catch (Throwable $e) {
        redirect_with_result('Cannot open Excel file: ' . $e->getMessage(), true);
    }

    if (!$allRows || !isset($allRows[0])) {
        redirect_with_result('Empty Excel file.', true);
    }

    $headerNorm = normalize_header($allRows[0]);

    if ($headerNorm !== $REQUIRED_HEADER) {
        redirect_with_result(
            'Header mismatch. Expected: ' . implode(', ', $REQUIRED_HEADER),
            true
        );
    }

    $rows = [];
    for ($r = 1; $r < count($allRows); $r++) {
        $row = $allRows[$r];

        for ($i = count($row); $i < 8; $i++) {
            $row[$i] = '';
        }

        $rows[] = [
            'Location'   => $row[0] ?? '',
            'SKU_Code'   => $row[1] ?? '',
            'BatchNo'    => $row[2] ?? '',
            'ExpiryDate' => excel_date_to_mmyyyy($row[3] ?? ''),
            'UnitType'   => $row[4] ?? '',
            'QtyPerCtn'  => $row[5] ?? '',
            'TotalQty'   => $row[6] ?? '',
            'Comments'   => $row[7] ?? '',
        ];
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $findExact = $pdo->prepare("
            SELECT EntryID
            FROM {$table}
            WHERE Location = ?
            AND SKU_Code = ?
            AND (BatchNo <=> ?)
            AND (ExpiryDate <=> ?)
            AND QtyPerCtn = ?
            AND TotalQty = ?
            AND COALESCE(Comments, '') = ?
            LIMIT 1
        ");

        $findMerge = $pdo->prepare("
            SELECT EntryID, TotalQty
            FROM {$table}
            WHERE Location = ?
            AND SKU_Code = ?
            AND (BatchNo <=> ?)
            AND (ExpiryDate <=> ?)
            AND (QtyPerCtn <=> ?)
            AND COALESCE(Comments, '') = ?
            LIMIT 1
        ");

        $insert = $pdo->prepare("
            INSERT INTO {$table}
            (Location, SKU_Code, BatchNo, ExpiryDate, UnitType, QtyPerCtn, TotalQty, Comments, LastUpdated)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $update = $pdo->prepare("
            UPDATE {$table}
            SET TotalQty = ?,
                UnitType = COALESCE(?, UnitType),
                LastUpdated = NOW()
            WHERE EntryID = ?
        ");

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($rows as $r) {
            $loc = s($r['Location']);
            $sku = s($r['SKU_Code']);
            $bat = s($r['BatchNo']);
            $exp = norm_expiry($r['ExpiryDate']);
            $unt = s($r['UnitType']);
            $qpc = n($r['QtyPerCtn']);
            $tot = n($r['TotalQty']);
            $com = trim((string)($r['Comments'] ?? ''));

            if ($loc === '' && $sku === '' && $bat === '' && $exp === null && $tot === 0) {
                continue;
            }

            if ($loc === '' || $sku === '') {
                throw new RuntimeException('Location and SKU_Code are required in all import rows.');
            }

            if ($exp === false) {
                throw new RuntimeException('Invalid ExpiryDate format detected. Use MM/YYYY or leave blank.');
            }

            $bat = $bat === '' ? '' : $bat;
            $unt = ($unt === '') ? null : $unt;

            $findExact->execute([$loc, $sku, $bat, $exp, $qpc, $tot, $com]);
            $exact = $findExact->fetch(PDO::FETCH_ASSOC);

            if ($exact) {
                $skipped++;
                continue;
            }

            $findMerge->execute([$loc, $sku, $bat, $exp, $qpc, $com]);
            $existing = $findMerge->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $entryId = (int)$existing['EntryID'];
                $before  = (int)$existing['TotalQty'];

                $update->execute([$tot, $unt, $entryId]);
                $updated++;

                tx_log([
                    'InventoryType' => $inventoryType,
                    'EntryID'         => $entryId,
                    'Action'          => 'Import',
                    'OldLocation'     => $loc,
                    'NewLocation'     => $loc,
                    'SKU_Code'        => $sku,
                    'BatchNo'         => $bat,
                    'ExpiryDate'      => $exp,
                    'UnitType'        => $unt,
                    'QtyPerCtn'       => $qpc,
                    'DeltaQty'        => $tot - $before,
                    'TotalQty_Before' => $before,
                    'TotalQty_After'  => $tot,
                    'Comments'        => 'Import XLSX updated existing row',
                ]);

                continue;
            }

            try {
                $insert->execute([$loc, $sku, $bat, $exp, $unt, $qpc, $tot, $com]);
                $eid = (int)$pdo->lastInsertId();
                $inserted++;

                tx_log([
                    'InventoryType' => $inventoryType,
                    'EntryID'         => $eid,
                    'Action'          => 'Import',
                    'OldLocation'     => null,
                    'NewLocation'     => $loc,
                    'SKU_Code'        => $sku,
                    'BatchNo'         => $bat,
                    'ExpiryDate'      => $exp,
                    'UnitType'        => $unt,
                    'QtyPerCtn'       => $qpc,
                    'DeltaQty'        => $tot,
                    'TotalQty_Before' => 0,
                    'TotalQty_After'  => $tot,
                    'Comments'        => 'Import XLSX new row',
                ]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) == 1062) {
                    $findExact->execute([$loc, $sku, $bat, $exp, $qpc, $tot, $com]);
                    $exact = $findExact->fetch(PDO::FETCH_ASSOC);

                    if ($exact) {
                        $skipped++;
                        continue;
                    }

                    $findMerge->execute([$loc, $sku, $bat, $exp, $qpc, $com]);
                    $existing = $findMerge->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $entryId = (int)$existing['EntryID'];
                        $before  = (int)$existing['TotalQty'];

                        if ($before === $tot) {
                            $skipped++;
                            continue;
                        }

                        $update->execute([$tot, $unt, $entryId]);
                        $updated++;

                        tx_log([
                            'EntryID'         => $entryId,
                            'Action'          => 'Import',
                            'OldLocation'     => $loc,
                            'NewLocation'     => $loc,
                            'SKU_Code'        => $sku,
                            'BatchNo'         => $bat,
                            'ExpiryDate'      => $exp,
                            'UnitType'        => $unt,
                            'QtyPerCtn'       => $qpc,
                            'DeltaQty'        => $tot - $before,
                            'TotalQty_Before' => $before,
                            'TotalQty_After'  => $tot,
                            'Comments'        => 'Import XLSX resolved duplicate by updating existing row',
                        ]);

                        continue;
                    }
                }

                throw $e;
            }
        }

        $pdo->commit();

        createLowStockNotificationForSku(
            $pdo,
            'productlocation',
            $sku,
            500
        );

        redirect_with_result("Import complete. New: {$inserted}, updated existing: {$updated}, skipped exact duplicates: {$skipped}");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('import_file failed: ' . $e->getMessage());
        redirect_with_result('Import failed: ' . $e->getMessage(), true);
    }