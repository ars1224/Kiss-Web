<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/conn/db.php';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('Invalid order ID.');
}

$pdo = db();

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
$stmt->execute([':id' => $id]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
}

$stmt = $pdo->prepare("
    SELECT picked_ctn_no, ctn_no
    FROM order_items
    WHERE order_id = :id
");
$stmt->execute([':id' => $id]);
$items = $stmt->fetchAll();

$highestCarton = 0;

foreach ($items as $item) {
    $ctnText = (string)($item['picked_ctn_no'] ?: $item['ctn_no'] ?: '');
    $highestCarton = max($highestCarton, getHighestCartonNumber($ctnText));
}

if ($highestCarton <= 0) {
    die('No carton numbers found.');
}

function getHighestCartonNumber(string $text): int
{
    $max = 0;

    // Supports: 1-10, 12-14, 15-16, 5
    preg_match_all('/\d+(?:\s*-\s*\d+)?/', $text, $matches);

    foreach ($matches[0] as $match) {
        $match = trim($match);

        if (str_contains($match, '-')) {
            [$start, $end] = array_map('intval', preg_split('/\s*-\s*/', $match));
            $max = max($max, $start, $end);
        } else {
            $max = max($max, (int)$match);
        }
    }

    return $max;
}
?>

<div class="labels-page">
    <div class="no-print label-actions">
        <a href="order_view.php?id=<?= htmlspecialchars((string)$id) ?>" class="btn btn-secondary">Back</a>
        <button class="btn btn-primary" onclick="window.print()">Print Labels</button>
    </div>

    <?php for ($carton = 1; $carton <= $highestCarton; $carton++): ?>
        <div class="carton-label">
            <div class="label-left">
                <div class="label-section">
                    <div class="label-title">From:</div>
                    <div class="from-company">Lanocorp Pacific Ltd</div>
                    <div>2 HYNDS DRIVE</div>
                    <div>ROLLESTON</div>
                    <div>CHRISTCHURCH</div>
                    <div>NEW ZEALAND</div>
                </div>

                <div class="label-section to-section">
                    <div class="label-title">To:</div>
                    <div class="to-company">
                        <?= htmlspecialchars($order['customer_name'] ?? '') ?>
                    </div>

                    <?php
                    $address = trim((string)($order['customer_address'] ?? ''));
                    $addressParts = preg_split('/,\s*/', $address);
                    ?>

                    <?php foreach ($addressParts as $part): ?>
                        <div><?= htmlspecialchars(strtoupper($part)) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="label-right">
                <div class="po-number">
                    PO <?= htmlspecialchars($order['order_number'] ?? '') ?>
                </div>

                <div class="ps-number">
                    PS <?= htmlspecialchars($order['packing_slip'] ?: $order['invoice_no']) ?>
                </div>

                <div class="carton-title">Carton #</div>
                <div class="carton-number"><?= $carton ?></div>
            </div>
        </div>
    <?php endfor; ?>
</div>

