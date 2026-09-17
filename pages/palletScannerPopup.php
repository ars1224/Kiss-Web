<?php
$scannerPopupConfig = array_merge(
    [
        'eyebrow' => 'Order picking',
        'instruction' => 'Point the phone camera at a pallet QR code.',
        'placeholder' => 'PLT-P-0000000157',
        'submit_label' => 'Check',
    ],
    is_array($palletScannerPopup ?? null) ? $palletScannerPopup : []
);
?>
<div class="pallet-scanner-modal no-print" id="palletScannerModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="palletScannerTitle">
    <div class="pallet-scanner-card">
        <header class="pallet-scanner-header">
            <div>
                <span><?= htmlspecialchars((string)$scannerPopupConfig['eyebrow'], ENT_QUOTES, 'UTF-8') ?></span>
                <h3 id="palletScannerTitle">Scan Pallet</h3>
            </div>
            <button type="button" class="pallet-scanner-close" id="palletScannerClose" aria-label="Close scanner">&times;</button>
        </header>

        <div class="pallet-scanner-camera">
            <video id="palletScannerVideo" playsinline muted></video>
            <div class="pallet-scanner-frame" aria-hidden="true"></div>
            <button type="button" class="btn btn-light pallet-scanner-torch" id="palletScannerTorch" hidden>Toggle Torch</button>
        </div>

        <div class="pallet-scanner-result is-neutral" id="palletScannerResult" aria-live="assertive">
            <strong>Ready to scan</strong>
            <span><?= htmlspecialchars((string)$scannerPopupConfig['instruction'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <form class="pallet-scanner-manual" id="palletScanManualForm" autocomplete="off">
            <label for="palletScanManualInput">Or enter Pallet ID</label>
            <div>
                <input type="text" id="palletScanManualInput" inputmode="text" autocapitalize="characters" placeholder="<?= htmlspecialchars((string)$scannerPopupConfig['placeholder'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars((string)$scannerPopupConfig['submit_label'], ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>

        <button type="button" class="btn btn-outline-secondary pallet-scanner-cancel" id="palletScannerCancel">Close Scanner</button>
    </div>
</div>
<?php unset($scannerPopupConfig); ?>
