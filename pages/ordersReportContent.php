<div class="page-content orders-report-page">
    <div class="orders-report-hero">
        <div>
            <span>Orders</span>
            <h1>Orders Report</h1>
            <p>Review sent orders, short supply, and orders still waiting to be sent.</p>
        </div>

        <a href="orders_list.php" class="back-orders-btn">Back to Orders</a>
    </div>

    <div class="card orders-report-card-wrap">
        <div class="orders-report-toolbar">
            <div class="form-group">
                <label for="reportFromDate">From Date</label>
                <input type="date" id="reportFromDate">
            </div>

            <div class="form-group">
                <label for="reportToDate">To Date</label>
                <input type="date" id="reportToDate">
            </div>

            <button type="button" id="generateOrdersReportBtn" class="orders-report-btn report-generate-btn">
                Generate Report
            </button>

            <button type="button" id="exportOrdersReportPdfBtn" class="orders-report-btn report-pdf-btn">
                Download PDF
            </button>
        </div>

        <div class="orders-report-cards">
            <div class="orders-report-card">
                <span>Total Orders</span>
                <strong id="reportTotalOrders">0</strong>
            </div>

            <div class="orders-report-card sent">
                <span>Sent</span>
                <strong id="reportSentOrders">0</strong>
            </div>

            <div class="orders-report-card danger">
                <span>Not Sent</span>
                <strong id="reportNotSentOrders">0</strong>
            </div>

            <div class="orders-report-card">
                <span>Qty Ordered</span>
                <strong id="reportQtyOrdered">0</strong>
            </div>

            <div class="orders-report-card sent">
                <span>Qty Supplied</span>
                <strong id="reportQtySupplied">0</strong>
            </div>

            <div class="orders-report-card danger">
                <span>Qty Not Supplied</span>
                <strong id="reportQtyNotSupplied">0</strong>
            </div>
        </div>
    </div>

    <div class="card orders-report-table-card">
        <div class="orders-report-section-heading">
            <div>
                <h2>Products Not Supplied</h2>
                <p>Sent order lines where supplied quantity is lower than ordered.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="orders-report-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Order Date</th>
                        <th>Customer</th>
                        <th>SKU</th>
                        <th>Description</th>
                        <th>Qty Ordered</th>
                        <th>Qty Supplied</th>
                        <th>Not Supplied</th>
                        <th>Reason</th>
                    </tr>
                </thead>

                <tbody id="reportNotSuppliedBody">
                    <tr>
                        <td colspan="9" class="report-empty">Select a date range to generate report.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card orders-report-table-card">
        <div class="orders-report-section-heading">
            <div>
                <h2>Orders Still Not Sent</h2>
                <p>Orders that still need picking, booking, packing slip upload, or follow-up.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="orders-report-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Order Date</th>
                        <th>Delivery Date</th>
                        <th>Status</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody id="reportNotSentBody">
                    <tr>
                        <td colspan="6" class="report-empty">Select a date range to generate report.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="js/ordersReport.js?v=<?= filemtime(__DIR__ . '/../js/ordersReport.js') ?>"></script>
