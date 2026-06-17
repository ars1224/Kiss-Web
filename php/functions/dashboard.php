<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../util/inventory_helper.php';
require_once __DIR__ . '/../util/dashboard_repo.php';

$pdo = db();

$role = strtolower(trim(currentUserRole()));
$inventoryType = inventoryType();

$totalLocations = 0;
$totalPallets = 0;
$lowStockAlerts = 0;
$todaysMovements = 0;

$totalOrders = 0;
$pendingOrders = 0;
$ongoingOrders = 0;
$bookingOrders = 0;
$waitingOrders = 0;
$sentOrders = 0;
$notSentOrders = 0;

$recentActivities = [];

$lowStockList = getLowStockList($pdo, $role);
$lowStockAlerts = count($lowStockList);

$userId = (int)($_SESSION['user_id'] ?? 0);



if ($role === 'admin') {
    $locationTable = '
        (
            SELECT Location, SKU_Code, TotalQty FROM productlocation
            UNION ALL
            SELECT Location, SKU_Code, TotalQty FROM componentlocation
            UNION ALL
            SELECT Location, SKU_Code, TotalQty FROM rmlocation
        ) AS all_locations
    ';

    $inventoryTypeFilter = '';
} else {
    $locationTable = inventoryTable();
    $inventoryTypeFilter = "WHERE InventoryType = " . $pdo->quote($inventoryType);
}

try {
    $totalLocations = (int)$pdo->query("
        SELECT COUNT(DISTINCT Location)
        FROM {$locationTable}
        WHERE Location IS NOT NULL
          AND Location <> ''
    ")->fetchColumn();

    $totalPallets = (int)$pdo->query("
        SELECT COUNT(*)
        FROM {$locationTable}
        WHERE TotalQty > 0
    ")->fetchColumn();

} catch (Throwable $e) {
    error_log('Dashboard inventory stats error: ' . $e->getMessage());
}

// ORDER STATS
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(CASE 
                WHEN LOWER(TRIM(COALESCE(status, 'pending'))) IN (
                    'pending',
                    'ongoing',
                    'booking',
                    'waiting_packing_slip',
                    'waiting packing slip',
                    'waiting slip',
                    'waiting for packing slip'
                )
                THEN 1 
            END) AS total_orders,

            COUNT(CASE 
                WHEN LOWER(TRIM(COALESCE(status, 'pending'))) = 'pending'
                THEN 1 
            END) AS pending_orders,

            COUNT(CASE 
                WHEN LOWER(TRIM(COALESCE(status, ''))) = 'ongoing'
                THEN 1 
            END) AS ongoing_orders,

            COUNT(CASE 
                WHEN LOWER(TRIM(COALESCE(status, ''))) = 'booking'
                THEN 1 
            END) AS booking_orders,

            COUNT(CASE 
                WHEN LOWER(TRIM(COALESCE(status, ''))) IN (
                    'waiting_packing_slip',
                    'waiting packing slip',
                    'waiting slip',
                    'waiting for packing slip'
                )
                THEN 1 
            END) AS waiting_orders,

            COUNT(CASE 
                WHEN LOWER(TRIM(COALESCE(status, ''))) = 'sent'
                THEN 1 
            END) AS sent_orders,

            COUNT(CASE 
                WHEN LOWER(TRIM(COALESCE(status, ''))) IN ('not_sent', 'not sent')
                THEN 1 
            END) AS not_sent_orders
        FROM orders
    ");

    $orderStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalOrders   = (int)($orderStats['total_orders'] ?? 0);
    $pendingOrders = (int)($orderStats['pending_orders'] ?? 0);
    $ongoingOrders = (int)($orderStats['ongoing_orders'] ?? 0);
    $bookingOrders = (int)($orderStats['booking_orders'] ?? 0);
    $waitingOrders = (int)($orderStats['waiting_orders'] ?? 0);
    $sentOrders    = (int)($orderStats['sent_orders'] ?? 0);
    $notSentOrders = (int)($orderStats['not_sent_orders'] ?? 0);

} catch (Throwable $e) {
    error_log('Dashboard order stats error: ' . $e->getMessage());
}
// TODAY MOVEMENTS + RECENT TRANSACTIONS
try {
    if ($role === 'admin') {
        $todaysMovements = (int)$pdo->query("
            SELECT COUNT(*)
            FROM producttransactions
            WHERE DATE(CreatedAt) = CURDATE()
        ")->fetchColumn();

        $stmt = $pdo->query("
            SELECT 
                CreatedAt,
                SKU_Code,
                Action,
                DeltaQty,
                OldLocation,
                NewLocation
            FROM producttransactions
            ORDER BY CreatedAt DESC, TxID DESC
            LIMIT 5
        ");

        $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM producttransactions
            WHERE DATE(CreatedAt) = CURDATE()
              AND InventoryType = :inventory_type
        ");
        $stmt->execute(['inventory_type' => $inventoryType]);
        $todaysMovements = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT 
                CreatedAt,
                SKU_Code,
                Action,
                DeltaQty,
                OldLocation,
                NewLocation
            FROM producttransactions
            WHERE InventoryType = :inventory_type
            ORDER BY CreatedAt DESC, TxID DESC
            LIMIT 5
        ");
        $stmt->execute(['inventory_type' => $inventoryType]);

        $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Throwable $e) {
    error_log('Dashboard movements error: ' . $e->getMessage());
}
?>