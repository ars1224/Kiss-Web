// Split from original designScript.js

// ===== Footer: show total of selected rows by unit type =====
const footerUnitsEl = document.querySelector('.footer .units');
const UNIT_TYPE_COL_INDEX = 5;  // "Unit Type" column (0-based)
const TOTAL_QTY_COL_INDEX = 7;  // "Total Qty" column (0-based)

function formatNum(n) {
  return new Intl.NumberFormat().format(n);
}

function updateFooterTotals() {
  if (!footerUnitsEl) return;
  const checked = document.querySelectorAll('.product-table tbody input[type="checkbox"]:checked');

  const totalsByUnit = {};
  checked.forEach(cb => {
    const row = cb.closest('tr');
    const unitCell = row?.children[UNIT_TYPE_COL_INDEX];
    const qtyCell  = row?.children[TOTAL_QTY_COL_INDEX];
    const unit = (unitCell?.innerText || '').trim().toLowerCase();
    const qty  = parseNum(qtyCell?.innerText);
    if (!unit) return;
    totalsByUnit[unit] = (totalsByUnit[unit] || 0) + qty;
  });

  const selectedCount = checked.length;
  const parts = Object.entries(totalsByUnit).map(([unit, qty]) => `${formatNum(qty)} ${unit}`);
  const unitString = parts.length ? parts.join(' + ') : '0';
  footerUnitsEl.textContent = `Selected: ${selectedCount} | Units: ${unitString}`;
}

(function wireFooterTotals() {
  const table = document.querySelector('.product-table');
  const tbody = table?.querySelector('tbody');
  const selectHeaderBtn = table?.querySelector('thead .select .btn');

  if (tbody) {
    tbody.addEventListener('change', (e) => {
      if (e.target.matches('input[type="checkbox"]')) updateFooterTotals();
    });
    tbody.addEventListener('click', (e) => {
      if (e.target.closest('tr')) setTimeout(updateFooterTotals, 0);
    });
  }
  if (selectHeaderBtn) {
    selectHeaderBtn.addEventListener('click', () => setTimeout(updateFooterTotals, 0));
  }
  updateFooterTotals();
})();

