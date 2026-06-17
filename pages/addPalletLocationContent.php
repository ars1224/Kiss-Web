<?php
require_once __DIR__ . '/../php/util/product_location_repo.php';
require_once __DIR__ . '/../php/util/view_helpers.php';

$rows = pl_all();
?>

<div class="main">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="m-0">Add Pallet/s</h4>
    <a class="btn btn-outline-secondary" href="Location.php">Back to Locations</a>
  </div>

  <div class="addForm p-2">
    <?php if (!empty($_SESSION['form_error'])): ?>
      <div class="alert alert-danger">
        <?= htmlspecialchars($_SESSION['form_error']) ?>
      </div>
      <?php unset($_SESSION['form_error']); ?>
    <?php endif; ?>

    <form id="multiForm" action="php/functions/location-addpallet.php" method="POST" autocomplete="off">
    <input
        type="hidden"
        name="inventory"
        value="<?= htmlspecialchars($_GET['inventory'] ?? '') ?>"
    >

    
      <div class="table-responsive">
        <table class="sheet" id="entryTable">
          <thead>
            <tr>
              <th class="select-col"><input type="checkbox" id="checkAll"></th>
              <th>EntryCode</th>
              <th>Location</th>
              <th>SKU_Code</th>
              <th>BatchNo</th>
              <th>ExpiryDate<br><small>(MM/YYYY)</small></th>
              <th>UnitType</th>
              <th>QtyPerCtn</th>
              <th>TotalQty</th>
              <th>Comments</th>
              <th>DateAdded</th>
            </tr>
          </thead>
          <tbody id="entryTbody"></tbody>
        </table>
      </div>
    </form>

      
  </div>
  <div class="actions">
        <div class="left-actions">
          <button type="button" class="btn btn-outline-primary" id="addRowBtn">Add Row</button>
          <button type="button" class="btn btn-outline-danger d-none" id="removeSelectedBtn">Remove</button>
          <button type="button" id="duplicateSelectedBtn" class="btn btn-warning d-none">Duplicate</button>
          <input type="number" id="duplicateCount" min="1" value="1" class="d-none action-qty-input">
          <button type="button" class="btn btn-outline-dark d-none" id="btnPrintLabels">Print Label</button>
          <input type="number" id="printCount" min="1" value="1" class="d-none action-qty-input">
        </div>

        <div class="right-actions">
          <button type="button" id="btnImportCSV" class="btn btn-outline-secondary">Import File</button>
          <button form = "multiForm" type="reset" class="btn btn-outline-secondary">Clear Form</button>
          <button form = "multiForm" type="submit" class="btn btn-primary">Save All</button>
        </div>
      </div>
    <!-- separate import form -->
    <form id="importForm"
      action="php/functions/import_file.php"
      method="POST"
      enctype="multipart/form-data"
      class="d-none">

      <input
          type="hidden"
          name="inventory"
          value="<?= htmlspecialchars($_GET['inventory'] ?? '') ?>"
      >

      <input type="file" id="importFile" name="file" accept=".csv,.xlsx">
    </form>

    <!-- separate print form -->
    <form id="printLabelsForm" action="php/functions/print_labels.php" method="POST" class="d-none">
      <input type="hidden" name="mode" id="labelMode" value="">
      <input type="hidden" name="ids"  id="labelIds" value="">
      <input type="hidden" name="rows" id="labelRows" value="">
    </form>
</div>