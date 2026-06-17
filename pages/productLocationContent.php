<?php
declare(strict_types=1);

// Load helpers + repo
require_once __DIR__ . '/../php/conn/requestHelpers.php';
require_once __DIR__ . '/../php/util/product_location_repo.php';
require_once __DIR__ . '/../php/util/view_helpers.php';

// Fallback h() if not defined
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Get search query from ?q=...
$q = get_param_string('q', '');       // from requestHelpers.php
// Get messages from query (?error=...&success=...)
$error   = get_param_string('error', '');
$success = get_param_string('success', '');

$rows = pl_all($q);                   // backend search


if (!is_array($rows)) {
    $rows = [];
}

// Build query string for actions (?q=...)
$qs = ($q !== '') ? ('?q=' . urlencode($q)) : '';


?>

<div class="location product-location-page" data-mobile-fixed-page>
    <div class="location-mobile-toolbar" data-mobile-fixed-toolbar>
    <div class="searchBar">
        <form class="d-flex align-items-center" role="search" id="searchForm" method="GET" action="location.php">
            <div class="position-relative flex-grow-1">
                <input class="form-control pe-5" id="searchEntry" type="text" name="q" value="<?= h($q) ?>" aria-label="Search"  placeholder="Search (e.g. sku:ABC batch:B001)"/>
                 <button type="button" id="searchClearX" class="btn p-0 position-absolute end-0 top-50 translate-middle-y me-2 <?= $q === '' ? 'd-none' : '' ?>" aria-label="Clear search"><i class="fa-solid fa-x"></i></button>
            </div>
        </form>

        <a href="transactions.php">
            <i class="fa-solid fa-clock-rotate-left" style="color: #355188;"></i>
        </a>
    </div>

    <div class="buttons">
        <div class="functionBtn">
            <?php $role = strtolower(trim(currentUserRole())); ?>

            <?php if ($role === 'admin'): ?>
                <a href="addPalletLocation.php?inventory=products" class="btn btn-outline-secondary">Add Products</a>
                <a href="addPalletLocation.php?inventory=components" class="btn btn-outline-secondary">Add Components</a>
                <a href="addPalletLocation.php?inventory=rm" class="btn btn-outline-secondary">Add Raw Materials</a>
            <?php else: ?>
                <a href="addPalletLocation.php" class="btn btn-outline-secondary">Add Pallet</a>
            <?php endif; ?>

            <button type="button" id="btnSelectAllMobile" class="btn btn-outline-secondary mobile-select-all">
                Select All
            </button>

            <div class="functionBtn-1">
                <form id="printLabelsForm" action="php/functions/print_labels.php" method="POST">
                    <input type="hidden" id="labelInventoryType" name="InventoryType" value="">
                    <input type="hidden" id="labelMode" name="mode" value="saved">
                    <input type="hidden" id="labelIds"  name="ids" value="">
                    <input type="hidden" id="labelRows" name="rows" value="">
                    <button id="btnPrintLabels" type="button" class="btn btn-outline-secondary requires-selection" >Print</button>
                </form>


                <button type="button" id="editBtn" class="btn btn-outline-secondary requires-selection">Edit
                    <i class="fa-solid fa-pencil" style="color:#74C0FC;"></i>
                </button>

                <button type="button" id="btnAddQty" class="btn btn-outline-secondary requires-selection">Add Qty
                    <i class="fa-solid fa-plus" style="color:#63E6BE;"> </i>
                </button>

                <button type="button" id="btnDeduct" class="btn btn-outline-secondary requires-selection">Deduct Qty
                    <i class="fa-solid fa-minus" style="color:#63E6BE;"></i>
                </button>

                <button type="button" id="btnDelete" class="btn btn-outline-secondary requires-selection">Delete
                    <i class="fa-solid fa-trash" style="color:#e66565;"> </i>
                </button>

                <button type="button" id="btnMove" class="btn btn-outline-secondary requires-selection">Move
                    <i class="fa-solid fa-up-down-left-right" style="color: #1f4251;"></i>
                </button>
            </div>
        </div>

        <div class="csv">
            <button type="submit"
        form="exportSelectedForm"
        id="btnExportSelected"
        class="btn btn-outline-secondary">
  Export
</button>


            <form id="exportSelectedForm"
                action="php/functions/export_file.php"
                method="post"
                class="d-none"></form>
        </div>

    </div>

    <?php if ($q !== ''): ?>
                <div class="searchResultInfo">
                    Showing results for: <strong><?= h($q) ?></strong>
                </div>
            <?php endif; ?>

    <?php if ($error !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= h($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div id="printMsg" class="alert d-none" role="alert"></div>
    </div>

    <div class="table product-location-data" data-mobile-scroll-data>
        <table class="product-table table-striped table-hover">
            <thead>
            <tr>
                <th class="select">
                    <button type="button" id="btnSelectAll" class="btn btn-outline-secondary">Select</button>
                </th>
                <th data-type="text">Location</th>
                <th data-type="text">SKU / Code</th>
                <th data-type="text">Batch No.</th>
                <th data-type="date">Expiry Date</th>
                <th data-type="text">Unit Type</th>
                <th data-type="number">Qty / Ctn</th>
                <th data-type="number">Total Qty</th>
                <th data-type="text">Comments</th>
            </tr>
            </thead>

            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted">
                        No entries found<?= $q !== '' ? ' for "' . h($q) . '"' : '' ?>.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r):
                    $eid = (int)($r['EntryID'] ?? 0);
                    // expiry_parts should come from view_helpers.php; if not, just show raw date
                    if (function_exists('expiry_parts')) {
                        $exp = expiry_parts($r['ExpiryDate'] ?? '');
                        $expIso     = $exp['iso']     ?? ($r['ExpiryDate'] ?? '');
                        $expDisplay = $exp['display'] ?? ($r['ExpiryDate'] ?? '');
                    } else {
                        $expIso = $expDisplay = (string)($r['ExpiryDate'] ?? '');
                    }
                    ?>
                   <tr
    data-id="<?= $eid ?>"
    data-inventory-type="<?= h($r['InventoryType'] ?? inventoryType()) ?>"
>

                    <td class="selectCheckBox" data-label="Select">
                        <div class="input-group-text">
                            <input
                                class="form-check-input mt-0 row-check"
                                type="checkbox"
                                value="<?= $eid ?>"
                                aria-label="Select row"
                                data-entryid="<?= (int)$r['EntryID'] ?>">
                        </div>
                    </td>

                    <td data-label="Location">
                        <?= h($r['Location'] ?? '') ?>
                    </td>

                    <td data-label="SKU / Code">
                        <?= h($r['SKU_Code'] ?? '') ?>
                    </td>

                    <td data-label="Batch No.">
                        <?= h($r['BatchNo'] ?? '') ?>
                    </td>

                    <td
                        data-label="Expiry Date"
                        data-full-date="<?= h($expIso) ?>">
                        <?= h($expDisplay) ?>
                    </td>

                    <td data-label="Unit Type">
                        <?= h($r['UnitType'] ?? '') ?>
                    </td>

                    <td data-label="Qty / Ctn">
                        <?= number_format((int)($r['QtyPerCtn'] ?? 0)) ?>
                    </td>

                    <td data-label="Total Qty">
                        <?= number_format((int)($r['TotalQty'] ?? 0)) ?>
                    </td>

                    <td data-label="Comments">
                        <?= h($r['Comments'] ?? '') ?>
                    </td>

                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="footer">
    <div class="units"><!-- JS fills: Selected: X | Units: ... --></div>
    <div class="small mt-1 text-muted" id="searchMeta"></div>
</div>

<!-- Edit Pallet bottom sheet -->
<div class="editPallet" id="editSheet">
    <div class="sheetHeader">
        <div class="sheetHandle"></div>
        <h5 class="m-0">Edit Pallet</h5>
        <button type="button" class="btn-close" id="editClose" aria-label="Close"></button>
    </div>

    <form id="editForm" action="php/functions/update_entry.php" method="POST" autocomplete="off">
    <input type="hidden" name="EntryID" id="edit_EntryID">
<input type="hidden" name="InventoryType" id="edit_InventoryType">
<input type="hidden" name="q" value="<?= h($q) ?>">

        <div class="sheetBody">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <label class="form-label">Location</label>
                    <input class="form-control" name="Location" id="edit_Location" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">SKU / Code</label>
                    <input class="form-control" name="SKU_Code" id="edit_SKU" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Batch No.</label>
                    <input class="form-control" name="BatchNo" id="edit_Batch">
                </div>
                <?php
$role = strtolower(trim(currentUserRole()));

$expiryRequired =
    in_array(inventoryType(), ['products', 'rm'], true)
    && $role !== 'admin';
?>  

<div class="col-6 col-md-3">
    <label class="form-label">
        Expiry Date (MM/YYYY)
        <?= $expiryRequired ? '<span class="text-danger">*</span>' : '<small class="text-muted">(optional)</small>' ?>
    </label>

    <input
        class="form-control"
        name="ExpiryDate"
        id="edit_Expiry"
        placeholder="MM/YYYY"
        inputmode="numeric"
        <?= $expiryRequired ? 'required pattern="^(0[1-9]|1[0-2])\/[0-9]{4}$"' : '' ?>
    >
</div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Unit Type</label>
                    <select class="form-select" name="UnitType" id="edit_Unit">
                        <option value="pcs">pcs</option>
                        <option value="pale">pale</option>
                        <option value="kg">kg</option>
                        <option value="gal">gal</option>
                        <option value="other">Other…</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Qty / Ctn</label>
                    <input class="form-control" type="number" name="QtyPerCtn" id="edit_QtyCtn" min="0" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Total Qty</label>
                    <input class="form-control" type="number" name="TotalQty" id="edit_Total" min="0" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Comments</label>
                    <input class="form-control" name="Comments" id="edit_Comments">
                </div>
            </div>
        </div>

        <div class="sheetFooter">
            <button type="button" class="btn btn-outline-secondary" id="editCancel">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>

<!-- Deduct Quantity bottom sheet -->
<!-- Add / Deduct Quantity bottom sheet (unified) -->
<div class="editPallet" id="qtySheet" aria-hidden="true">
    <div class="sheetHeader">
        <div class="sheetHandle"></div>
        <h5 class="m-0" id="qtyTitle">Adjust Quantity</h5>
        <button type="button" class="btn-close" id="qtyClose" aria-label="Close"></button>
    </div>

    <form id="qtyForm"
      action="php/util/update_qty.php"
      method="POST"
      autocomplete="off">

        <input type="hidden" name="EntryID" id="qty_EntryID">
        <input type="hidden" name="InventoryType" id="qty_InventoryType">
        <input type="hidden" id="qty_Mode" name="mode" value="add">
            <!-- keep current search filter -->
        <input type="hidden" name="q" value="<?= h($q) ?>">

        <div class="sheetBody">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <label class="form-label">Location</label>
                    <input class="form-control" id="qty_Location" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">SKU / Code</label>
                    <input class="form-control" id="qty_SKU" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Batch No.</label>
                    <input class="form-control" id="qty_Batch" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Expiry (MM/YYYY)</label>
                    <input class="form-control" id="qty_Expiry" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Unit Type</label>
                    <input class="form-control" id="qty_Unit" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Qty / Ctn</label>
                    <input class="form-control" id="qty_QtyCtn" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Current Total</label>
                    <input class="form-control" id="qty_Current" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Adjust by<span style="color:red;">*</span></label>
                    <input class="form-control"
                           type="number"
                           min="0"
                           step="1"
                           name="amount"
                           id="qty_Amount"
                           placeholder="0"
                           required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">New Total</label>
                    <input class="form-control" id="qty_NewTotal" readonly>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Comments</label>
                    <input class="form-control" id="qty_Comments" readonly>
                </div>
            </div>
        </div>

        <div class="sheetFooter">
            <button type="button" class="btn btn-outline-secondary" id="qtyCancel">Cancel</button>
            <button type="submit" class="btn btn-primary" id="qtySubmit">Save</button>
        </div>
    </form>
</div>




<!-- Move Entries dialog -->
<div class="moveDialog" id="moveDialog" aria-hidden="true">
    <div class="moveCard">
        <div class="moveHeader">
            <h5 class="m-0">Move to New Location <small id="moveCount" class="text-muted"></small></h5>
            <button type="button" class="btn-close" id="moveClose" aria-label="Close"></button>
        </div>

     <form id="moveForm"
      action="php/functions/move_entries.php"
      method="POST"
      autocomplete="off">
    <div id="moveIds"></div>

    <!-- keep current search filter -->
     <input type="hidden" name="InventoryType" id="move_InventoryType">
    <input type="hidden" name="q" value="<?= h($q) ?>">

    <div class="moveBody">
        <div class="row g-2 mb-2" id="movePreview">
            <div class="col-6 col-md-3">
                <label class="form-label">Current Location</label>
                <input class="form-control" id="move_CurrentLoc" readonly>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">SKU</label>
                <input class="form-control" id="move_SKU" readonly>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Batch</label>
                <input class="form-control" id="move_Batch" readonly>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Expiry</label>
                <input class="form-control" id="move_Expiry" readonly>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Qty / Ctn</label>
                <input class="form-control" id="move_QtyCtn" readonly>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Total Qty</label>
                <input class="form-control" id="move_Total" readonly>
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label">New Location</label>
            <input class="form-control" name="NewLocation" id="move_NewLocation" placeholder="e.g., A-1-2" required>
        </div>

        <small class="text-muted">
            If a row already exists in the new location with the same
            <b>SKU, Batch, Expiry, Qty/Ctn</b>, its quantity will be increased and the moved row removed.
            Otherwise the row’s Location will simply change.
        </small>
    </div>

    <div class="moveFooter">
        <button type="button" class="btn btn-outline-secondary" id="moveCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">Move</button>
    </div>
</form>

    </div>
</div>

<!-- Delete confirm dialog -->
<div class="moveDialog" id="deleteDialog" aria-hidden="true">
    <div class="moveCard">
        <div class="moveHeader">
            <h5 class="m-0">Delete Rows <small id="delCount" class="text-muted"></small></h5>
            <button type="button" class="btn-close" id="delClose"></button>
        </div>

        <form id="delForm"
      action="php/functions/delete_entries.php"
      method="POST"
      autocomplete="off">
    <!-- where JS will inject EntryID[]: -->
    <div id="delIds"></div>

    <!-- keep current search filter -->
     <input type="hidden" name="InventoryType" id="delete_InventoryType">
    <input type="hidden" name="q" value="<?= h($q) ?>">

    <div class="moveBody">
        <p class="mb-2">
            Are you sure you want to delete the selected row(s)? This cannot be undone.
        </p>
    </div>

    <div class="moveFooter">
        <button type="button" class="btn btn-outline-secondary" id="delCancel">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
    </div>
</form>

    </div>
</div>
