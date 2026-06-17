<div class="page-content orders-page">
    <div class="orders-header">
    
        <a href="orders_list.php" class="btn btn-secondary">View All Orders</a>
    </div>

    <div class="card orders-import-card">
        <h2>Import Invoice File</h2>
        <p>Upload .xls, .xlsx, or .csv to auto-fill header details and SKU lines.</p>

        <div class="import-row">
            <input type="file" id="orderFile" accept=".xls,.xlsx,.csv">
            <button type="button" id="importOrderBtn" class="btn btn-primary">Import File</button>
        </div>
    </div>

    <div class="card orders-form-card">
           <div>
                <h1>Create Orders</h1>
            </div>
            
        <form id="ordersForm">
            <div class="orders-grid">
                <div class="form-group">
                    <label for="invoice_no">Invoice Number</label>
                    <input type="text" id="invoice_no" name="invoice_no" required>
                </div>

                <div class="form-group">
                    <label for="order_date">Date Today</label>
                    <input type="date" id="order_date" name="order_date" required>
                </div>

                <div class="form-group">
                    <label for="delivery_date">Delivery Date</label>
                    <input type="date" id="delivery_date" name="delivery_date">
                </div>

                <div class="form-group">
                    <label for="customer_code">Customer Code</label>
                    <input type="text" id="customer_code" name="customer_code">
                </div>

                <div class="form-group">
                    <label for="customer_name">Customer Name</label>
                    <input type="text" id="customer_name" name="customer_name" required>
                </div>

                <div class="form-group full-width">
                    <label for="customer_address">Customer Address</label>
                    <input type="text" id="customer_address" name="customer_address">
                </div>

                <div class="form-group">
                    <label for="order_number">Order Number</label>
                    <input type="text" id="order_number" name="order_number">
                </div>

                <div class="form-group">
                    <label for="packing_slip">Packing Slip</label>
                    <input type="text" id="packing_slip" name="packing_slip">
                </div>

                <div class="form-group">
                    <label for="internal_reference">Internal Reference</label>
                    <input type="text" id="internal_reference" name="internal_reference">
                </div>

                <div class="form-group">
                    <label for="purchase_number">Purchase Number</label>
                    <input type="text" id="purchase_number" name="purchase_number">
                </div>

                <div class="form-group">
                    <label for="sales_person">Sales Person</label>
                    <input type="text" id="sales_person" name="sales_person">
                </div>

                <div class="form-group">
                    <label for="rounding_mode">Rounding</label>
                    <select id="rounding_mode" name="rounding_mode">
                        <option value="1" selected>Enable Rounding</option>
                        <option value="0">Disable Rounding</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="min_shelf_life_months">Minimum Shelf Life</label>
                    <select id="min_shelf_life_months" name="min_shelf_life_months">
                        <option value="6" selected>6+ months before expiry</option>
                        <option value="18">18+ months before expiry</option>
                    </select>
                </div>
            </div>

            <hr class="section-divider">

            <div class="line-entry">
                <h2>Add / Review Order Lines</h2>

                <div class="line-entry-grid">
                    <div class="form-group">
                        <label for="line_sku">SKU Code</label>
                        <input type="text" id="line_sku" list="sku_list" autocomplete="off">
                        <datalist id="sku_list"></datalist>
                    </div>

                    <div class="form-group">
                        <label for="line_desc">Description</label>
                        <input type="text" id="line_desc">
                    </div>

                    <div class="form-group">
                        <label for="line_qty">Quantity</label>
                        <input type="number" id="line_qty" min="1" step="0.000001">
                    </div>

                    <div class="form-group action-group">
                        <label>&nbsp;</label>
                        <button type="button" id="addLineBtn" class="btn btn-primary">Add Line</button>
                    </div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="orders-table" id="manualLinesTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Description</th>
                            <th>Requested Qty</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="manualLinesBody">
                        <tr class="empty-row">
                            <td colspan="4">No order lines added yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="orders-actions">
                <button type="button" id="previewOrderBtn" class="btn btn-secondary">Preview Picking List</button>
                <button type="submit" id="saveOrderBtn" class="btn btn-success">Save Order</button>
            </div>
        </form>
    </div>

    <div id="previewSection" class="card preview-card" style="display:none;">
        <div class="preview-header">
            <h2>Picking List Preview</h2>
            <p>Allocates stock from productLocation using your picking rules.</p>
        </div>

        <div class="table-wrap">
            <table class="orders-table preview-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>BATCH EXPIRY</th>
                        <th>Description</th>
                        <th>Qty Ordered</th>
                        <th>TOTAL Qty Ordered</th>
                        <th>TOTAL QTY SUPPLIED</th>
                        <th>QTY SUPPLIED</th>
                        <th>UNITS/CTN</th>
                        <th class="full-ctn-cell">NO. FULL CTN</th>
                        <th class="ctn-number-cell">CTN #</th>
                        <th>LOCATION</th>
                        <th>COMMENT</th>
                    </tr>
                </thead>
                <tbody id="previewBody">
                    <tr class="empty-row">
                        <td colspan="11">No preview yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="js/orders.js?v=<?= filemtime(__DIR__ . '/../js/orders.js') ?>"></script>
