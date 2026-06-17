<?php 

require_once __DIR__ . '/../php/functions/dashboard.php';

?>

<!-- === DASHBOARD CONTENT === -->
<main class="dashboard">
    <div class="container-fluid py-4">

        <!-- INVENTORY STATS -->
        <div class="row g-3">

            <div class="col-12 col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Locations</h6>
                                <h3 class="mb-0"><?= number_format($totalLocations) ?></h3>
                            </div>
                            <i class="fa-solid fa-location-dot fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Total Pallets</h6>
                                <h3 class="mb-0"><?= number_format($totalPallets) ?></h3>
                            </div>
                            <i class="fa-solid fa-boxes-stacked fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>

         <div class="col-12 col-md-6 col-xl-3">
    <div class="card h-100 stat-clickable" id="lowStockCard">

        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">

                <div>
                    <h6>LOW STOCK ALERTS</h6>
                    <h3><?= $lowStockAlerts ?></h3>
                </div>

                <i class="fa-solid fa-triangle-exclamation fa-2x"></i>

            </div>
        </div>

    </div>
</div>
            <a class="col-12 col-md-6 col-xl-3" href="\kiss-web\transactions.php" style="text-decoration:none;">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Today's Movements</h6>
                                <h3 class="mb-0"><?= number_format($todaysMovements) ?></h3>
                            </div>
                            <i class="fa-solid fa-right-left fa-2x"></i>
                        </div>
                    </div>
                </div>
</a>

            <div id="lowStockModal" class="modal-overlay d-none">
    <div class="low-stock-modal">
        <div class="modal-header">
            <h3>Low Stock Alerts</h3>
            <button type="button" id="closeLowStockModal">&times;</button>
        </div>

        <div class="modal-body">
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Description</th>
                        <th>Qty Per Ctn</th>
                        <th>Total Qty</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($lowStockList)): ?>
                        <?php foreach ($lowStockList as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['SKU_Code']) ?></td>
                                <td><?= htmlspecialchars($item['ProductDescription'] ?? '') ?></td>
                                <td><?= htmlspecialchars((string)$item['QtyPerCtn']) ?></td>
                                <td><?= htmlspecialchars((string)$item['TotalQty']) ?></td>
                                <td><?= htmlspecialchars($item['InventoryType'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4">No low stock items found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

        </div>

        <!-- ORDER STATS -->
         <?php $role = strtolower(trim(currentUserRole())); ?>

<?php if (!in_array($role, ['inwards', 'rawmat'], true)): ?>
        <div class="row g-3 mt-1">

            <div class="col-12 col-md-6 col-xl-1">
                <div class="card h-100 border-primary">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Current Orders</h6>
                        <h3 class="mb-0"><?= number_format($totalOrders) ?></h3>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-2">
                <div class="card h-100 border-warning">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Pending</h6>
                        <h3 class="mb-0"><?= number_format($pendingOrders) ?></h3>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-2">
                <div class="card h-100 border-info">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Ongoing</h6>
                        <h3 class="mb-0"><?= number_format($ongoingOrders) ?></h3>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-2">
                <div class="card h-100 border-dark">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Booking</h6>
                        <h3 class="mb-0"><?= number_format($bookingOrders) ?></h3>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-2">
                <div class="card h-100 border-secondary">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Waiting Slip</h6>
                        <h3 class="mb-0"><?= number_format($waitingOrders) ?></h3>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-2">
                <div class="card h-100 border-success">
                    <div class="card-body">
                        <h6 class="text-muted mb-1"> Total Sent Orders</h6>
                        <h3 class="mb-0"><?= number_format($sentOrders) ?></h3>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-1">
                <div class="card h-100 border-danger">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Not Sent</h6>
                        <h3 class="mb-0"><?= number_format($notSentOrders) ?></h3>
                    </div>
                </div>
            </div>

        </div>

<?php endif; ?>

        <!-- RECENT ACTIVITY + QUICK ACTIONS -->
        <div class="row g-3 mt-3">

            <div class="col-12 col-lg-8">
                <div class="card h-100">

                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Recent Activity</h5>

                        <a href="transactions.php" class="small text-decoration-none">
                            View all
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </div>

                    <div class="card-body">

                        <?php if (empty($recentActivities)): ?>

                            <p class="text-muted mb-0">
                                No recent activity found.
                            </p>

                        <?php else: ?>

                            <div class="table-responsive">
                                <table class="table align-middle">

                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>SKU</th>
                                            <th>Action</th>
                                            <th>Qty</th>
                                            <th>Location</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($recentActivities as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['CreatedAt'] ?? '') ?></td>
<td><?= htmlspecialchars($row['SKU_Code'] ?? '') ?></td>
<td><?= htmlspecialchars($row['Action'] ?? '') ?></td>
<td><?= htmlspecialchars((string)($row['DeltaQty'] ?? '')) ?></td>
<td>
    <?= htmlspecialchars($row['OldLocation'] ?? '') ?>
    <?= !empty($row['NewLocation']) ? ' → ' . htmlspecialchars($row['NewLocation']) : '' ?>
</td>
                                        </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>
                            </div>

                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="col-12 col-lg-4">
                <div class="card h-100">

                    <div class="card-header">
                        <h5 class="mb-0">Quick Actions</h5>
                    </div>

                    <div class="card-body d-grid gap-2">

    <a href="Location.php" class="btn btn-outline-primary w-100">
        <i class="fa-solid fa-location-dot me-2"></i>
        Go to Locations
    </a>

    <?php if ($role === 'admin'): ?>

    <a href="addPalletLocation.php?inventory=products"
       class="btn btn-outline-success w-100">
        <i class="fa-solid fa-box me-2"></i>
        Add Products
    </a>

    <a href="addPalletLocation.php?inventory=components"
       class="btn btn-outline-success w-100">
        <i class="fa-solid fa-gears me-2"></i>
        Add Components
    </a>

    <a href="addPalletLocation.php?inventory=rm"
       class="btn btn-outline-success w-100">
        <i class="fa-solid fa-flask me-2"></i>
        Add Raw Materials
    </a>

<?php else: ?>

    <a href="addPalletLocation.php"
       class="btn btn-outline-success w-100">
        <i class="fa-solid fa-pallet me-2"></i>
        Add Pallet/s
    </a>

<?php endif; ?>

    <a href="products.php" class="btn btn-outline-dark w-100">
        <i class="fa-solid fa-box-open me-2"></i>
        Products Summary
    </a>
<?php $role = strtolower(trim(currentUserRole())); ?>

<?php if (!in_array($role, ['inwards', 'rawmat'], true)): ?>
    <a href="orders_list.php" class="btn btn-outline-dark w-100">
        <i class="fa-solid fa-cart-shopping me-2"></i>
        Orders
    </a>
<?php endif; ?>
    <a href="transactions.php" class="btn btn-outline-secondary w-100">
        <i class="fa-solid fa-clock-rotate-left me-2"></i>
        View Transactions
    </a>

</div>
                </div>
            </div>

        </div>

    </div>
</main>