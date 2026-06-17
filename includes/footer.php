<!-- Global scripts -->
  <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/5c09730e7a.js" crossorigin="anonymous"></script>

    <!-- Bootstrap JS (for offcanvas, dropdowns, etc.) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"integrity="..." crossorigin="anonymous"></script>

    <script src="js/sharedHelpers.js"></script>
    <script src="js/core/helpers.js"></script>
    <script src="js/core/sidebar.js"></script>
    <script src="js/core/mobileFixedToolbar.js?v=<?= filemtime(__DIR__ . '/../js/core/mobileFixedToolbar.js') ?>"></script>
    <script src="js/inventory/selection.js"></script>
    <script src="js/inventory/footerTotals.js"></script>
    <script src="js/dialogs/editSheet.js"></script>
    <script src="js/dialogs/qtySheet.js"></script>
    <script src="js/dialogs/moveDialog.js"></script>
    <script src="js/dialogs/deleteDialog.js"></script>
    <script src="js/inventory/search.js"></script>
    <script src="js/inventory/selectAll.js?v=<?= filemtime(__DIR__ . '/../js/inventory/selectAll.js') ?>"></script>
    <script src="js/inventory/sorting.js"></script>
    <script src="js/inventory/expiryColors.js"></script>
    <script src="js/actions/exportFile.js"></script>
    <script src="js/actions/importFile.js"></script>
    <script src="js/core/sheetHelpers.js"></script>
    <script src="js/core/ajaxAdjust.js"></script>
    <script src="js/actions/printLabels.js"></script>
    <script src="js/dashboard.js"></script>

<!-- Optional page-specific script -->
<?php if (!empty($pageJS)): ?>
    <script src="<?= htmlspecialchars($pageJS, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
