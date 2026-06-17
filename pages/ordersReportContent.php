<div class="page-content orders-report-page">
    <div class="orders-header">
        <div>
            <h1>Orders Report</h1>
            <p>View sent orders, not supplied products, and orders still not sent by date.</p>
        </div>

        <a href="orders_list.php" class="back-orders-btn">Back to Orders</a>
    </div>

    <div class="card orders-report-card-wrap">
        <div class="orders-report-filters">
            <div class="form-group">
                <label for="reportFromDate">From Date</label>
                <input type="date" id="reportFromDate">
            </div>

            <div class="form-group">
                <label for="reportToDate">To Date</label>
                <input type="date" id="reportToDate">
            </div>

            <button type="button" id="generateOrdersReportBtn">
                Generate Report
            </button>

            <button type="button" id="exportOrdersReportPdfBtn">
                Export PDF
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
        <h2>Products Not Supplied</h2>

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
        <h2>Orders Still Not Sent</h2>

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
