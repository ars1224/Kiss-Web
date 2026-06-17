// Split from original designScript.js

// ===== Select All (all if no query, visible-only if searched) =====
(function () {
  const table = document.querySelector('.product-table');
  const tbody = table?.querySelector('tbody');
  const headerBtn   = table?.querySelector('thead .select .btn');
  const externalBtn = document.getElementById('btnSelectAllMobile');
  if (!table || !tbody) return;

  const rowsAll = () => Array.from(tbody.querySelectorAll('tr'));

  const isVisible = (tr) => {
    if (tr.classList.contains('hidden')) return false;
    const cs = getComputedStyle(tr);
    return cs.display !== 'none' && cs.visibility !== 'hidden';
  };

  const qActive = () => (document.getElementById('searchEntry')?.value.trim().length ?? 0) > 0;

  function targetRows() {
    const all = rowsAll();
    return qActive() ? all.filter(isVisible) : all;
  }

  function allChecked(rows) {
    if (!rows.length) return false;
    return rows.every(tr => tr.querySelector('input[type="checkbox"]')?.checked);
  }

  function setChecked(rows, val) {
    for (const tr of rows) {
      const cb = tr.querySelector('input[type="checkbox"]');
      if (!cb) continue;
      cb.checked = !!val;
      tr.classList.toggle('selected', !!val);
    }
  }

  function refreshUI() {
    if (typeof updateActionVisibility === 'function') updateActionVisibility();
    if (typeof updateFooterTotals === 'function') updateFooterTotals();
  }

  function handleSelectAllClick() {
    const rows = targetRows();
    if (!rows.length) {
      alert('No rows in the current view.');
      return;
    }

    const check = !allChecked(rows);
    setChecked(rows, check);
    refreshUI();
  }

  headerBtn?.addEventListener('click', handleSelectAllClick);
  externalBtn?.addEventListener('click', handleSelectAllClick);
})();
