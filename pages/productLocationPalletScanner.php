<?php
$palletScannerPopup = [
    'eyebrow' => 'Product location',
    'instruction' => 'Point the phone camera at a pallet QR code.',
    'placeholder' => 'PLT-P-0000132403',
    'submit_label' => 'Find',
];
include __DIR__ . '/palletScannerPopup.php';
unset($palletScannerPopup);
?>

<link rel="stylesheet" href="css/pallet_scanner.css?v=<?= filemtime(__DIR__ . '/../css/pallet_scanner.css') ?>">
<script src="js/pallet_scanner.js?v=<?= filemtime(__DIR__ . '/../js/pallet_scanner.js') ?>"></script>
