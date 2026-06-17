// Split from original designScript.js

// ---- Bottom-sheet Edit (open, prefill, close) ----
(function(){
  const sheet   = document.getElementById('editSheet');
  const overlay = document.querySelector('.overlay');

  function openSheet(){ sheet?.classList.add('open'); overlay?.classList.add('show'); }
  function closeSheet(){ sheet?.classList.remove('open'); overlay?.classList.remove('show'); }

  document.getElementById('editClose')?.addEventListener('click', closeSheet);
  document.getElementById('editCancel')?.addEventListener('click', closeSheet);
  overlay?.addEventListener('click', closeSheet);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSheet(); });

  const editBtn =
    document.getElementById('editBtn') ||
    document.querySelector('.functionBtn-1 button:has(i.fa-pencil)') ||
    [...document.querySelectorAll('.functionBtn-1 button')].find(b => /edit/i.test(b.textContent));

  if (!editBtn) return;

  editBtn.addEventListener('click', () => {
  const tbody = document.querySelector('.product-table tbody');
  const checked = [...tbody.querySelectorAll('input[type="checkbox"]:checked')];
  if (checked.length !== 1) { alert('Please select exactly ONE row to edit.'); return; }
  const row = checked[0].closest('tr');
  if (!row) return;

  const cell = (i) => (row.children[i]?.innerText || '').trim();

  const cb = checked[0];
  const entryId = cb.value || row?.dataset.id || '';
  if (!/^\d+$/.test(entryId)) {
    alert('EntryID missing on this row. Ensure checkbox value or tr[data-id] has the numeric EntryID.');
    return;
  }
  document.getElementById('edit_EntryID').value = entryId;

  document.getElementById('edit_InventoryType').value =
  row.dataset.inventoryType || '';

    document.getElementById('edit_Location').value = cell(1);
    document.getElementById('edit_SKU').value      = cell(2);
    document.getElementById('edit_Batch').value    = cell(3);

    const exp = document.getElementById('edit_Expiry');
    exp.value = cell(4);
    exp.addEventListener('input', () => {
      let v = exp.value.replace(/[^\d]/g,'').slice(0,6);
      if (v.length >= 3) v = v.slice(0,2) + '/' + v.slice(2);
      exp.value = v;
    }, { once: true });

    const unitSel = document.getElementById('edit_Unit');
    const unitTxt = cell(5);
    const unitVal = (unitTxt || '').toLowerCase();
    if (![...unitSel.options].some(o => o.value === unitVal) && unitVal) {
      const opt = document.createElement('option');
      opt.value = unitVal;
      opt.textContent = unitTxt || unitVal;
      unitSel.insertBefore(opt, unitSel.querySelector('option[value="other"]'));
    }
    unitSel.value = unitVal || 'pcs';

    document.getElementById('edit_QtyCtn').value   = cell(6).replace(/,/g,'') || 0;
    document.getElementById('edit_Total').value    = cell(7).replace(/,/g,'') || 0;
    document.getElementById('edit_Comments').value = cell(8);

    openSheet();
  });
})();

// ===== Confirm delete when Total Qty = 0 in Edit sheet =====
(function () {
  const form  = document.getElementById('editForm');
  const total = document.getElementById('edit_Total');
  if (!form || !total) return;

  form.addEventListener('submit', (e) => {
    // strip commas, parse number
    const raw = (total.value || '').replace(/,/g, '').trim();
    const num = raw === '' ? 0 : Number(raw);

    if (!Number.isFinite(num)) return; // let backend validate

    if (num <= 0) {
      const ok = window.confirm(
        'Total Qty is 0.\n\nIf you save this, the entry will be automatically deleted.\n\nDo you want to continue?'
      );
      if (!ok) {
        e.preventDefault();
      }
    }
  });
})();

