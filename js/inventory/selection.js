// Split from original designScript.js

// ====== Row Selection & Button Visibility ======
const table = document.querySelector('.product-table');
const tbody = table?.querySelector('tbody');
const actionGroup = document.querySelector('.functionBtn-1');

function findBtnWithIcon(sel, textFallback) {
  const byIcon = actionGroup?.querySelector(sel);
  if (byIcon) return byIcon;
  return [...(actionGroup?.querySelectorAll('button') ?? [])]
    .find(b => b.textContent.trim().toLowerCase().includes(textFallback));
}

const moveButton   = findBtnWithIcon('button:has(i.fa-up-down-left-right)', 'move');
const deleteButton = findBtnWithIcon('button:has(i.fa-trash)',             'delete');

const printButton = document.getElementById('btnPrintLabels');

const otherButtons = actionGroup
  ? [...actionGroup.querySelectorAll('button')]
      .filter(b => b !== moveButton && b !== deleteButton && b !== printButton)
  : [];


function syncRowSelectedClass(row) {
  const cb = row.querySelector('input[type="checkbox"]');
  row.classList.toggle('selected', !!(cb && cb.checked));
}

function updateActionVisibility() {
  if (!tbody || !actionGroup) return;
  const checkedCount = tbody.querySelectorAll('input[type="checkbox"]:checked').length;
  // === Export button visibility ===
  const exportBtn = document.getElementById('btnExportSelected');
  if (exportBtn) {
    exportBtn.style.display = checkedCount > 0 ? 'block' : 'none';
  }
  if (checkedCount === 0) {
    actionGroup.style.display = 'none';
    return;
  }

  actionGroup.style.display = 'flex';

  if (checkedCount > 1) {
    if (moveButton)   moveButton.style.display   = 'flex';
    if (deleteButton) deleteButton.style.display = 'flex';
    otherButtons.forEach(btn => btn.style.display = 'none');
  } else {
    otherButtons.forEach(btn => btn.style.display = 'flex');
    if (moveButton)   moveButton.style.display   = 'flex';
    if (deleteButton) deleteButton.style.display = 'flex';
  }
   if (printButton) {
    printButton.style.display = checkedCount > 0 ? 'flex' : 'none';
  }
}

if (tbody) {
  tbody.addEventListener('click', (e) => {
    const row = e.target.closest('tr');
    if (!row) return;
    const cb = row.querySelector('input[type="checkbox"]');
    if (!cb) return;
    if (e.target !== cb) cb.checked = !cb.checked;
    syncRowSelectedClass(row);
    updateActionVisibility();
  });

  tbody.addEventListener('change', (e) => {
    if (e.target.matches('input[type="checkbox"]')) {
      const row = e.target.closest('tr');
      if (row) {
        syncRowSelectedClass(row);
        updateActionVisibility();
      }
    }
  });
}

updateActionVisibility();

