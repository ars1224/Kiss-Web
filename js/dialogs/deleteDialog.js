// Split from original designScript.js

// ===== Delete (multiple selected rows) =====
(function(){
  const btn = document.getElementById('btnDelete');
  if (!btn) return;

  const dlg     = document.getElementById('deleteDialog');
  const overlay = document.querySelector('.overlay');
  const open  = () => { dlg?.classList.add('open'); overlay?.classList.add('show'); };
  const close = () => { dlg?.classList.remove('open'); overlay?.classList.remove('show'); };

  document.getElementById('delClose')?.addEventListener('click', close);
  document.getElementById('delCancel')?.addEventListener('click', close);
  overlay?.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  const tbody        = document.querySelector('.product-table tbody');
  const idsContainer = document.getElementById('delIds');
  const countEl      = document.getElementById('delCount');

  btn.addEventListener('click', () => {
    const checked = [...document.querySelectorAll('.row-check:checked')];
    const firstRow = checked[0]?.closest('tr');

    document.getElementById('delete_InventoryType').value =
        firstRow?.getAttribute('data-inventory-type') || '';
    if (!checked.length) {
      alert('Select at least one row to delete.');
      return;
    }

    idsContainer.innerHTML = '';
    checked.forEach(cb => {
      const row = cb.closest('tr');
      const id  = (cb.value || row?.dataset.id || '').trim();
      if (!/^\d+$/.test(id)) return;
      const inp = document.createElement('input');
      inp.type  = 'hidden';
      inp.name  = 'EntryID[]';
      inp.value = id;
      idsContainer.appendChild(inp);
    });

    if (!idsContainer.children.length) {
      alert('Could not resolve EntryIDs for the selected rows.');
      return;
    }

    if (countEl) countEl.textContent = `(${idsContainer.children.length} selected)`;
    open();
  });
})();

