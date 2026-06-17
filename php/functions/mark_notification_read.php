<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';

try {
    $id = (int)($_GET['id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('Invalid notification ID.');
    }

    if ($userId <= 0) {
        throw new Exception('Invalid user session.');
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notification_reads
            (notification_id, user_id)
        VALUES
            (:notification_id, :user_id)
    ");

    $stmt->execute([
        ':notification_id' => $id,
        ':user_id' => $userId
    ]);

    echo json_encode([
        'success' => true,
        'notification_id' => $id,
        'user_id' => $userId,
        'inserted' => $stmt->rowCount()
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}