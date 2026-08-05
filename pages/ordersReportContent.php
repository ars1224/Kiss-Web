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

    <div class="orders-report-graphs">
        <section class="orders-report-graph-card">
            <div class="orders-report-section-heading">
                <div>
                    <h2>Best Seller SKUs</h2>
                    <p>Top supplied units for the selected completed date range.</p>
                </div>
            </div>
            <div id="reportBestSellerSkuChart" class="report-bar-chart report-chart-sellers">
                <div class="report-chart-empty">Select a date range to generate graph.</div>
            </div>
        </section>

        <section class="orders-report-graph-card">
            <div class="orders-report-section-heading">
                <div>
                    <h2>Most Ordered SKUs</h2>
                    <p>Top ordered units for the selected completed date range.</p>
                </div>
            </div>
            <div id="reportMostOrderedSkuChart" class="report-bar-chart report-chart-ordered">
                <div class="report-chart-empty">Select a date range to generate graph.</div>
            </div>
        </section>

        <section class="orders-report-graph-card wide">
            <div class="orders-report-section-heading">
                <div>
                    <h2>Monthly Units Out</h2>
                    <p>Supplied units grouped by completion month.</p>
                </div>
            </div>
            <div id="reportMonthlyUnitsOutChart" class="report-month-chart">
                <div class="report-chart-empty">Select a date range to generate graph.</div>
            </div>
        </section>
    </div>

    <div id="finalNotSentSection" class="card orders-report-table-card" hidden>
        <div class="orders-report-section-heading">
            <div>
                <h2>Not Sent Orders</h2>
                <p>Completed orders that did not ship because of no stock or another recorded reason.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table class="orders-report-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Report Date</th>
                        <th>Customer</th>
                        <th>Order Date</th>
                        <th>Delivery Date</th>
                        <th>Reason</th>
                    </tr>
                </thead>

                <tbody id="reportFinalNotSentBody"></tbody>
            </table>
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

    <div id="ordersStillNotSentSection" class="card orders-report-table-card" hidden>
        <div class="orders-report-section-heading">
            <div>
                <h2>Orders Still Not Sent (Pending)</h2>
                <p>Pending or in-progress orders that still need picking, booking, packing slip upload, or follow-up.</p>
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
                        <td colspan="6" class="report-empty">No orders are currently still not sent.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="js/ordersReport.js?v=<?= filemtime(__DIR__ . '/../js/ordersReport.js') ?>"></script>
