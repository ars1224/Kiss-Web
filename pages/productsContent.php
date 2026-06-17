<?php
require_once __DIR__ . '/../php/conn/db.php';
require_once __DIR__ . '/../php/auth/session.php';
require_once __DIR__ . '/../php/util/products_summary_repo.php';


requireLogin();


$pdo = db();
$products = getProductsSummary($pdo);

$totalProducts = count($products);
$totalUnits = array_sum(array_map(fn($p) => (float)$p['TotalUnitQty'], $products));

$isAdmin = strtolower(trim(currentUserRole())) === 'admin';
?>

<div class="products-page products-search-page" data-mobile-fixed-page>
    <div class="products-fixed-header" data-mobile-fixed-toolbar>
    <div class="products-topbar">
        <div>
            <h1>Products Summary</h1>
            <p>Total unit quantity per SKU based on current product locations.</p>
        </div>

        <a href="/kiss-web/Location.php" class="back-btn">Back to Locations</a>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <span>Products</span>
            <strong><?= number_format($totalProducts) ?></strong>
            <small>Total SKUs</small>
        </div>

        <div class="summary-card wide">
            <span>Total Units</span>
            <strong><?= number_format($totalUnits) ?></strong>
            <small>Based on current product locations</small>
        </div>
    </div>

    <div class="search-card products-mobile-search">
        <label>Search</label>

        <div class="search-row">
            <input
                type="text"
                id="productsSearch"
                placeholder="Try: AMBN502 or BN05/V4"
                autocomplete="off"
            >

            <button type="button" id="clearProductsSearch" class="clear-btn">
                Clear
            </button>
        </div>
        
    </div>
    </div>

    

    <div class="products-table-card products-data" data-mobile-scroll-data>
        <table class="products-table" id="productsTable">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Description</th>
                    <th>Total Unit Qty</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="4" class="empty-row">No products found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <?php
                            $status = $product['Status'] ?? 'Continue';
                            $statusClass = strtolower(str_replace([' ', '/'], '-', $status));
                        ?>
                       <tr
                            class="product-row"
                            data-sku="<?= htmlspecialchars($product['SKU_Code']) ?>"
                            data-inventory-type="<?= htmlspecialchars($product['InventoryType'] ?? inventoryType()) ?>"
                        >
                            
                        <td class="sku-cell" data-label="SKU">
                            <?= htmlspecialchars($product['SKU_Code']) ?>
                        </td>

    <td data-label="Description">
        <?= htmlspecialchars($product['ProductDescription'] ?? '') ?>
    </td>

    <td class="qty-cell" data-label="Total Qty">
        <?= number_format((float)$product['TotalUnitQty']) ?>
    </td>

    <td data-label="Status">
        <span class="status-badge status-<?= htmlspecialchars($statusClass) ?>">
            <?= htmlspecialchars($status) ?>
        </span>
    </td>
</tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div id="productPopupOverlay" class="product-popup-overlay">
    <div class="product-popup">
        <div class="product-popup-header">
            <div>
                <h3 id="popupSku">SKU</h3>
                <p id="popupDesc">Description</p>
            </div>
            <button type="button" id="closeProductPopup">&times;</button>
        </div>

        <div class="product-popup-summary">
            <div>
                <span>Total Qty</span>
                <strong id="popupTotal">0</strong>
            </div>
            <div>
                <span>Qty/Ctn</span>
                <strong id="popupQtyCtn">0</strong>
            </div>
        </div>

        <?php if ($isAdmin): ?>
<div class="product-popup-edit">
    <div class="popup-field">
        <label>Description</label>
        <input type="text" id="popupDescriptionInput">
    </div>

    <div class="popup-field">
        <label>Status</label>
        <select id="popupStatusInput">
            <option value="Continue">Continue</option>
            <option value="Keep Producing but No PMs again">Keep Producing but No PMs again</option>
            <option value="Keep selling until OOS">Keep selling until OOS</option>
            <option value="Discontinued">Discontinued</option>
        </select>
    </div>

    <button type="button" id="saveProductInfoBtn">
        Save Changes
    </button>
</div>
<?php endif; ?>

        <div class="product-popup-table-wrap">
            <table class="product-popup-table">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th>Qty</th>
                        <th>Qty/Ctn</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody id="popupLocationsBody">
                    <tr>
                        <td colspan="6">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
    </div>
</div>

<script src="/kiss-web/js/products.js?v=<?= filemtime(__DIR__ . '/../js/products.js') ?>"></script>
