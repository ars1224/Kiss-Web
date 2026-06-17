<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';

try {
    $pdo = db();

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $role = strtolower(trim(currentUserRole()));

    if ($userId <= 0) {
        throw new Exception('Invalid user session.');
    }

    if ($role === 'admin') {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO notification_reads
                (notification_id, user_id)
            SELECT n.id, :user_id
            FROM notifications n
        ");

        $stmt->execute([
            ':user_id' => $userId
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO notification_reads
                (notification_id, user_id)
            SELECT n.id, :read_user_id
            FROM notifications n
            WHERE n.user_id = :target_user_id
               OR LOWER(n.role) = LOWER(:target_role)
        ");

        $stmt->execute([
            ':read_user_id' => $userId,
            ':target_user_id' => $userId,
            ':target_role' => $role
        ]);
    }

    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'role' => $role,
        'inserted' => $stmt->rowCount()
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}