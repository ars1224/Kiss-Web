<div class="page-content orders-page orders-list-page">
    <div class="orders-header">
        <div>
            <h1>Orders</h1>
            <p>Monitor picking, checking, courier booking, and sent orders.</p>
        </div>
    </div>

    <div class="card orders-form-card orders-list-card">
        <div class="orders-list-top">
            <div>
                <h2>All Orders</h2>
                <p id="ordersResultCount">Loading orders...</p>
            </div>

            <div class="orders-actions">
                <a href="ordersReport.php" class="view-report-btn btn btn-secondary">View Report</a>
                <a href="orders.php" class="btn btn-primary">Create Order</a>
            </div>
        </div>

        <div class="orders-status-summary" aria-label="Order status summary">
        <button type="button" class="status-count-card pending" data-status-filter="pending">
            <span>Pending</span>
            <strong id="countPending">0</strong>
        </button>

        <button type="button" class="status-count-card ongoing" data-status-filter="ongoing">
            <span>Ongoing</span>
            <strong id="countOngoing">0</strong>
        </button>

         <button type="button" class="status-count-card booking" data-status-filter="booking">
            <span>Booking</span>
            <strong id="countBooking">0</strong>
        </button>

        <button type="button" class="status-count-card waiting" data-status-filter="waiting_packing_slip">
            <span>Waiting Slip</span>
            <strong id="countWaiting">0</strong>
        </button>

        <button type="button" class="status-count-card sent" data-status-filter="sent">
            <span>Sent</span>
            <strong id="countSent">0</strong>
        </button>

        <button type="button" class="status-count-card status-not-sent" data-status-filter="not_sent">
            <span>Not Sent</span>
            <strong id="countNotSent">0</strong>
        </button>
    </div>

        <div class="orders-filter-grid">
            <div class="form-group">
                <label for="statusFilter">Filter Status</label>
                <select id="statusFilter">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="booking">Booking</option>
                    <option value="waiting_packing_slip">Waiting Slip</option>
                    <option value="sent">Sent</option>
                    <option value="not_sent">Not Sent</option>
                </select>
            </div>

            <div class="form-group">
                <label for="searchOrder">Search</label>
                <input type="text" id="searchOrder" placeholder="Invoice, customer, order no...">
            </div>
        </div>

        <div class="table-wrap orders-list-table-wrap">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Order No</th>
                        <th>Status</th>
                        <th>Packed By</th>
                        <th>Checked By</th>
                        <th>Courier</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="ordersListBody">
                    <tr class="empty-row">
                        <td colspan="9">Loading orders...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="js/orders_list.js?v=<?= filemtime(__DIR__ . '/../js/orders_list.js') ?>"></script>
