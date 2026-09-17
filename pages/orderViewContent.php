<div class="page-content orders-page order-view-page">

    <div class="orders-actions no-print order-top-actions">
        <a href="orders_list.php" class="btn btn-secondary">Back to Orders</a>

        <button type="button" class="btn btn-primary" id="startPickingBtn">
            Start Picking
        </button>

        <button type="button" class="btn btn-success" id="scanPalletBtn" data-scan-context="order" style="display:none;">
            Scan Pallet
        </button>

        <button type="button" class="btn btn-reopen" id="reopenOrderBtn" style="display:none;">
            Reopen &amp; Add Items
        </button>

        <a id="downloadPickSlipBtn" class="btn btn-primary" style="display:none;">
            Download Pick Slip
        </a>
    </div>

    <div id="orderViewBody">
        Loading order...
    </div>

    <div class="card order-view-card no-print">

        <div id="checkingPanel" class="checking-panel" style="display:none;">
            <h3>Checking</h3>
            <p>Confirm that all picked items are correct.</p>

            <div class="checking-row">
                <input type="text" id="checkerName" placeholder="Checker name">
                <button type="button" class="btn btn-success" id="checkedBtn">Checked</button>
            </div>
        </div>

        <div id="bookingPanel" class="booking-panel" style="display:none;">
            <h3>Courier Booking</h3>
            <p>Choose courier and enter the reference/code from the courier website.</p>

            <div class="booking-row">
                <select id="courierName">
                    <option value="">Select Courier</option>
                    <option value="Posthaste">Posthaste</option>
                    <option value="Mainstream">Mainstream</option>
                    <option value="NZ Courier">NZ Courier</option>
                    <option value="Other">Other / New Courier</option>
                </select>

                <input type="text" id="customCourierName" placeholder="New courier name" style="display:none;">
                <input type="text" id="courierReference" placeholder="Courier reference / code">

                <button type="button" class="btn btn-success" id="bookCourierBtn">Done Booking</button>
            </div>

            
        </div>

        <div id="packingSlipPanel" class="packing-slip-panel" style="display:none;">
            <h3>Upload Packing Slip</h3>
            <p>Drop the packing slip below to upload it and complete this order.</p>

            <div class="packing-slip-row">
                <input
                    type="file"
                    id="packingSlipFile"
                    class="packing-slip-file-input"
                    accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx"
                    aria-describedby="packingSlipStatus"
                >

                <label
                    for="packingSlipFile"
                    id="packingSlipDropZone"
                    class="packing-slip-drop-zone"
                    role="button"
                    tabindex="0"
                    aria-describedby="packingSlipStatus"
                >
                    <span class="packing-slip-drop-icon" aria-hidden="true">&#8681;</span>
                    <span class="packing-slip-drop-title">Drop packing slip here</span>
                    <span id="packingSlipStatus" class="packing-slip-drop-status" aria-live="polite">
                        PDF, JPG, PNG, XLS or XLSX &middot; click to browse
                    </span>
                </label>
            </div>
        </div>
        


    </div>

</div>

<?php
$palletScannerPopup = [
    'eyebrow' => 'Order picking',
    'instruction' => 'Point the phone camera at a pallet QR code.',
    'placeholder' => 'PLT-P-0000000157',
    'submit_label' => 'Check',
];
include __DIR__ . '/palletScannerPopup.php';
unset($palletScannerPopup);
?>

<link rel="stylesheet" href="css/pallet_scanner.css?v=<?= filemtime(__DIR__ . '/../css/pallet_scanner.css') ?>">
<script src="js/order_view.js?v=<?= filemtime(__DIR__ . '/../js/order_view.js') ?>"></script>
<script src="js/pallet_scanner.js?v=<?= filemtime(__DIR__ . '/../js/pallet_scanner.js') ?>"></script>
