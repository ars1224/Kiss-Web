// Split from original designScript.js

// ===== Move (single OR multiple rows) =====
(function(){
  const btn = document.getElementById('btnMove');
  if (!btn) return;

  const dlg     = document.getElementById('moveDialog');
  const overlay = document.querySelector('.overlay');
  const open  = () => { dlg?.classList.add('open'); overlay?.classList.add('show'); };
  const close = () => { dlg?.classList.remove('open'); overlay?.classList.remove('show'); };

  document.getElementById('moveClose')?.addEventListener('click', close);
  document.getElementById('moveCancel')?.addEventListener('click', close);
  overlay?.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  const tbody = document.querySelector('.product-table tbody');

  const idsContainer = document.getElementById('moveIds');
  const countEl      = document.getElementById('moveCount');
  const newLocEl     = document.getElementById('move_NewLocation');

  const pv = {
    curLoc: document.getElementById('move_CurrentLoc'),
    sku:    document.getElementById('move_SKU'),
    batch:  document.getElementById('move_Batch'),
    expiry: document.getElementById('move_Expiry'),
    qtyctn: document.getElementById('move_QtyCtn'),
    total:  document.getElementById('move_Total'),
  };

btn.addEventListener('click', () => {
  const checked = [...tbody.querySelectorAll('input[type="checkbox"]:checked')];

  if (!checked.length) {
    alert('Select at least one row to move.');
    return;
  }

  const firstRow = checked[0].closest('tr');

  document.getElementById('move_InventoryType').value =
    firstRow?.getAttribute('data-inventory-type') || '';

  idsContainer.innerHTML = '';
  newLocEl.value = '';

  checked.forEach(cb => {
    const row = cb.closest('tr');
    const entryId = (cb.value || row?.dataset.id || '').trim();

    if (!/^\d+$/.test(entryId)) return;

    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'EntryID[]';
    inp.value = entryId;
    idsContainer.appendChild(inp);
  });

  const cell = i => (firstRow.children[i]?.innerText || '').trim();

  if (pv.curLoc) pv.curLoc.value = cell(1);
  if (pv.sku)    pv.sku.value    = cell(2);
  if (pv.batch)  pv.batch.value  = cell(3);
  if (pv.expiry) pv.expiry.value = cell(4);
  if (pv.qtyctn) pv.qtyctn.value = cell(6);
  if (pv.total)  pv.total.value  = cell(7);

  if (countEl) countEl.textContent = `(${checked.length} selected)`;

  open();

  document.getElementById('move_NewLocation')?.focus();
});
})();

