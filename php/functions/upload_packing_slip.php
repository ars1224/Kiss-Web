<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../util/notification_helper.php';

requireLogin();

try {
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        throw new Exception('Invalid order ID.');
    }

    if (!isset($_FILES['packing_slip'])) {
        throw new Exception('No file uploaded.');
    }

    if (!is_uploaded_file($_FILES['packing_slip']['tmp_name'])) {
        throw new Exception('Invalid uploaded file.');
    }

    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];
    $originalName = $_FILES['packing_slip']['name'] ?? '';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed, true)) {
        throw new Exception('Invalid file type.');
    }

    $uploadDir = __DIR__ . '/../../uploads/packing_slips/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $safeName = 'packing_slip_order_' . $orderId . '_' . date('Ymd_His') . '.' . $extension;
    $targetPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES['packing_slip']['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save uploaded file.');
    }

    $relativePath = 'uploads/packing_slips/' . $safeName;

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT invoice_no
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    $invoiceNo = trim((string)($order['invoice_no'] ?? $orderId));

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'sent',
            packing_slip_file = :file,
            completed_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        ':file' => $relativePath,
        ':id' => $orderId
    ]);

    try {
        createNotification(
            $pdo,
            null,
            'outwards',
            'uploaded',
            'Packing Slip Uploaded',
            "Packing slip for #{$invoiceNo} was uploaded and order has been marked as sent.",
            'orders_list.php?status=sent&search=' . urlencode($invoiceNo)
        );
    } catch (Throwable $notificationError) {
        error_log(
            'Packing-slip notification failed: ' . $notificationError->getMessage()
        );
    }

    echo json_encode([
        'success' => true,
        'file' => $relativePath,
        'status' => 'sent'
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
