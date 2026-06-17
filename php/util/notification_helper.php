<?php
declare(strict_types=1);

function getUserNotifications(PDO $pdo, int $userId, string $role): array
{
    $role = strtolower(trim($role));

    if ($role === 'admin') {
        $stmt = $pdo->prepare("
            SELECT n.*
            FROM notifications n
            LEFT JOIN notification_reads r
                ON r.notification_id = n.id
               AND r.user_id = :read_user_id
            WHERE r.id IS NULL
            ORDER BY n.created_at DESC
            LIMIT 30
        ");

        $stmt->execute([
            ':read_user_id' => $userId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT n.*
        FROM notifications n
        LEFT JOIN notification_reads r
            ON r.notification_id = n.id
           AND r.user_id = :read_user_id
        WHERE r.id IS NULL
          AND (
                n.user_id = :target_user_id
                OR LOWER(n.role) = :target_role
              )
        ORDER BY n.created_at DESC
        LIMIT 20
    ");

    $stmt->execute([
        ':read_user_id' => $userId,
        ':target_user_id' => $userId,
        ':target_role' => $role
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createNotification(
    PDO $pdo,
    ?int $userId,
    ?string $role,
    string $type,
    string $title,
    string $message,
    string $link = '#'
): void {
    $role = $role !== null ? strtolower(trim($role)) : null;

    $check = $pdo->prepare("
        SELECT id
        FROM notifications
        WHERE COALESCE(user_id, 0) = COALESCE(:user_id, 0)
          AND COALESCE(role, '') = COALESCE(:role, '')
          AND type = :type
          AND title = :title
          AND message = :message
          AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        LIMIT 1
    ");

    $check->execute([
        ':user_id' => $userId,
        ':role' => $role,
        ':type' => $type,
        ':title' => $title,
        ':message' => $message
    ]);

    if ($check->fetch()) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications
            (user_id, role, type, title, message, link)
        VALUES
            (:user_id, :role, :type, :title, :message, :link)
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':role' => $role,
        ':type' => $type,
        ':title' => $title,
        ':message' => $message,
        ':link' => $link
    ]);
}

function createLowStockNotificationForSku(
    PDO $pdo,
    string $table,
    string $sku,
    int $threshold = 500
): void {
    $sku = trim($sku);

    if ($sku === '') {
        return;
    }

    $sources = [
        'productlocation' => [
            'role' => 'outwards',
            'title' => 'FG Low Stock',
            'link' => 'location.php'
        ],
        'componentlocation' => [
            'role' => 'inwards',
            'title' => 'Component Low Stock',
            'link' => 'location.php'
        ],
        'rmlocation' => [
            'role' => 'rawmat',
            'title' => 'Raw Material Low Stock',
            'link' => 'location.php'
        ]
    ];

    $table = strtolower(trim($table));

    if (!isset($sources[$table])) {
        return;
    }

    $source = $sources[$table];

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(TotalQty), 0) AS total_qty
        FROM {$table}
        WHERE SKU_Code = :sku
    ");

    $stmt->execute([
        ':sku' => $sku
    ]);

    $qty = (float)$stmt->fetchColumn();

    if ($qty <= 0 || $qty > $threshold) {
        return;
    }

    createNotification(
        $pdo,
        null,
        $source['role'],
        'low-stock',
        $source['title'],
        "{$sku} total stock is low. Current qty: {$qty}.",
        $source['link'] . '?q=' . urlencode($sku)
    );
}