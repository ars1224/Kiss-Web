<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/inventory_helper.php';
require_once __DIR__ . '/../util/pallet_id_helper.php';

function locationScanJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) {
    locationScanJson([
        'success' => false,
        'code' => 'unauthorized',
        'message' => 'Please sign in again.',
    ], 401);
}

try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid scan request.');
    }

    $rawCode = trim((string)($input['pallet_id'] ?? ''));
    if ($rawCode === '') {
        throw new InvalidArgumentException('Scan or enter a Pallet ID.');
    }

    $normalizedId = normalizePalletScanCode($rawCode);
    if ($normalizedId === '') {
        locationScanJson([
            'success' => false,
            'code' => 'invalid_code',
            'message' => 'This is not a KISS pallet QR code.',
        ], 422);
    }

    $pallet = resolvePalletScan(db(), $normalizedId);
    if (!$pallet) {
        locationScanJson([
            'success' => false,
            'code' => 'not_found',
            'message' => 'Pallet ID not found or the inventory row has already been consumed.',
        ], 404);
    }

    $visibleTable = inventoryTable();
    if ($visibleTable !== 'all' && $visibleTable !== (string)$pallet['InventoryTable']) {
        locationScanJson([
            'success' => false,
            'code' => 'wrong_inventory',
            'message' => 'This pallet belongs to ' . (string)$pallet['InventoryType'] . ' inventory.',
        ], 403);
    }

    locationScanJson([
        'success' => true,
        'code' => 'found',
        'message' => !empty($pallet['WasAlias'])
            ? 'Older pallet label resolved to the current inventory row.'
            : 'Pallet found.',
        'pallet' => $pallet,
    ]);
} catch (InvalidArgumentException $e) {
    locationScanJson([
        'success' => false,
        'code' => 'invalid_request',
        'message' => $e->getMessage(),
    ], 422);
} catch (Throwable $e) {
    error_log('find_pallet_location failed: ' . $e->getMessage());
    locationScanJson([
        'success' => false,
        'code' => 'server_error',
        'message' => 'Pallet lookup failed. Please try again.',
    ], 500);
}
