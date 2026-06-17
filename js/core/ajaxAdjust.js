// Split from original designScript.js

// Ajax hook function kept (not used by default)
async function postAdjust(form, payload) {
  const res = await fetch(form.action, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(payload)
  });
  return res.json();
}

function hookForm(formId, modeFieldId, entryIdFieldId, amountFieldId) {
  const form = document.getElementById(formId);
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const mode    = document.getElementById(modeFieldId)?.value || 'deduct';
    const entryId = document.getElementById(entryIdFieldId)?.value;
    const amount  = Math.abs(parseNum(document.getElementById(amountFieldId)?.value));

    if (!entryId || !amount) {
      alert('Please enter a valid positive number.');
      document.getElementById(amountFieldId)?.focus();
      return;
    }

    try {
      const data = await postAdjust(form, { entryId, mode, delta: amount });
      console.log('update_qty response:', data);

      if (!data || data.ok !== true) {
        alert(data && data.message ? data.message : 'Update failed.');
        return;
      }

      const tr = document.querySelector(`tr[data-entryid="${entryId}"]`);
      if (data.deleted) {
        tr?.remove();
      } else if (tr && typeof data.newQty !== 'undefined') {
        const qtyCell = tr.querySelector('[data-col="total_qty"]');
        if (qtyCell) qtyCell.textContent = String(data.newQty);
      }

      document.getElementById('qtySheet')?.setAttribute('aria-hidden','true');
      document.getElementById('deductSheet')?.setAttribute('aria-hidden','true');
      document.querySelector('.overlay')?.classList.remove('active');
    } catch (err) {
      console.error('fetch/update error:', err);
      alert('Request failed – check console for details.');
    }
  });

  document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        // Bootstrap hide animation
        alert.classList.remove('show');

        // Remove the element AFTER the fade-out animation completes (300ms)
        setTimeout(() => {
            alert.remove();
        }, 5);
    }, 100); // 5 seconds
});

}

// (function(){
//   const btn  = document.getElementById('btnPrintLabels');
//   const form = document.getElementById('printLabelsForm');
//   const mode = document.getElementById('labelMode');
//   const ids  = document.getElementById('labelIds');
//   const rows = document.getElementById('labelRows');

//   const tbody = document.querySelector('.product-table tbody');
//   if (!btn || !form || !mode || !ids || !rows || !tbody) return;

//   function resolveId(cb){
//     const tr = cb.closest('tr');
//     // ✅ match your project's existing patterns
//     return (cb.value || tr?.dataset.id || cb.dataset.entryid || '').trim();
//   }

//   function getSelectedIds(){
//     return [...tbody.querySelectorAll('input[type="checkbox"]:checked')]
//       .map(resolveId)
//       .filter(v => /^\d+$/.test(v))
//       .map(v => parseInt(v, 10));
//   }

//   function refresh(){
//     btn.classList.toggle('d-none', getSelectedIds().length === 0);
//   }

//   // Your table toggles on row click, so listen to BOTH click + change
//   tbody.addEventListener('click',  () => setTimeout(refresh, 0));
//   tbody.addEventListener('change', () => setTimeout(refresh, 0));
//   document.getElementById('checkAll')?.addEventListener('change', () => setTimeout(refresh, 0));

//   btn.addEventListener('click', ()=>{
//     const selected = getSelectedIds();
//     if (!selected.length) return;

//     mode.value = 'saved';
//     ids.value  = JSON.stringify(selected);
//     rows.value = '';
//     form.submit();
//   });

//   refresh();
// })();

