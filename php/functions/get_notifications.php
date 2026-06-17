<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../util/notification_helper.php';

try {
    $pdo = db();

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $role = strtolower(trim(currentUserRole()));

    $notifications = getUserNotifications($pdo, $userId, $role);

    echo json_encode([
        'success' => true,
        'count' => count($notifications),
        'notifications' => $notifications
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}