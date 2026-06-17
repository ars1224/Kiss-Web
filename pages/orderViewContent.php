<div class="page-content orders-page">

    <div class="orders-actions no-print order-top-actions">
        <a href="orders_list.php" class="btn btn-secondary">Back</a>

        <button type="button" class="btn btn-primary" id="startPickingBtn">
            Start Picking
        </button>

        <button type="button" class="btn btn-success" id="savePickingBtn">
            Save Picking
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
            <p>Upload the packing slip to complete this order.</p>

            <div class="packing-slip-row">
                <input type="file" id="packingSlipFile" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx">

                <button type="button" class="btn btn-success" id="uploadPackingSlipBtn">
                    Upload & Mark Sent
                </button>
            </div>
        </div>
        


    </div>

</div>

<script src="js/order_view.js?v=<?= filemtime(__DIR__ . '/../js/order_view.js') ?>"></script>
