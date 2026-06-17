<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';

requireLogin();

if (strtolower(trim(currentUserRole())) !== 'admin') {
    header('Location: ../../index.php');
    exit;
}

function redirect_products(string $message, bool $error = false): void
{
    header('Location: ../../Products.php?' . http_build_query([
        $error ? 'error' : 'success' => $message
    ]));
    exit;
}

if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    redirect_products('No file uploaded.', true);
}

$ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));

if ($ext !== 'csv') {
    redirect_products('Please upload CSV only.', true);
}

try {
    $handle = fopen($_FILES['file']['tmp_name'], 'r');

    if (!$handle) {
        redirect_products('Cannot open CSV file.', true);
    }

    $header = fgetcsv($handle);

    if (!$header) {
        redirect_products('CSV file is empty.', true);
    }

    $header = array_map(fn($v) => trim((string)$v), $header);

    $skuIndex = array_search('SKU_Code', $header, true);
    $descIndex = array_search('ProductDescription', $header, true);
    $statusIndex = array_search('Status', $header, true);

    if ($skuIndex === false || $descIndex === false) {
        redirect_products('Header must include SKU_Code and ProductDescription.', true);
    }

    $pdo = db();

    $productStmt = $pdo->prepare("
        INSERT INTO products (SKU_Code, ProductDescription)
        VALUES (:sku, :description)
        ON DUPLICATE KEY UPDATE
            ProductDescription = VALUES(ProductDescription)
    ");

    $statusStmt = $pdo->prepare("
        INSERT INTO product_status (SKU_Code, status, updated_at)
        VALUES (:sku, :status, NOW())
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            updated_at = NOW()
    ");

    $count = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $sku = trim((string)($row[$skuIndex] ?? ''));
        $desc = trim((string)($row[$descIndex] ?? ''));
        $status = $statusIndex !== false ? trim((string)($row[$statusIndex] ?? '')) : '';

        if ($sku === '') {
            continue;
        }

        $productStmt->execute([
            'sku' => $sku,
            'description' => $desc,
        ]);

        if ($status !== '') {
            $statusStmt->execute([
                'sku' => $sku,
                'status' => $status,
            ]);
        }

        $count++;
    }

    fclose($handle);

    redirect_products("Products import complete. Rows processed: {$count}");

} catch (Throwable $e) {
    redirect_products('Import failed: ' . $e->getMessage(), true);
}