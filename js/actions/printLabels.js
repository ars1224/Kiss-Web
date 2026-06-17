// Split from original designScript.js

// =================== PRINT LABELS (NO PREVIEW, SILENT PRINT) ===================
document.addEventListener('DOMContentLoaded', () => {
  const btn  = document.getElementById('btnPrintLabels');
  const form = document.getElementById('printLabelsForm');
  const mode = document.getElementById('labelMode');
  const ids  = document.getElementById('labelIds');
  const rows = document.getElementById('labelRows');

  const tbody = document.querySelector('.product-table tbody');

  if (!btn || !form || !mode || !ids || !rows || !tbody) {
    console.error('Print elements missing:', {
      btn: !!btn, form: !!form, mode: !!mode, ids: !!ids, rows: !!rows, tbody: !!tbody
    });
    return;
  }

  // ---- UI message helper (Bootstrap alert) ----
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[c]));
  }

  function showAlert(type, message, autoHideMs = 5000) {
    const box = document.getElementById('printMsg');
    if (!box) return;

    box.className = `alert alert-${type} alert-dismissible fade show`;
    box.innerHTML = `
      ${escapeHtml(message)}
      <button type="button" class="btn-close" aria-label="Close"></button>
    `;
    box.classList.remove('d-none');

    // close button
    const closeBtn = box.querySelector('.btn-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        box.classList.add('d-none');
        box.className = 'alert d-none';
        box.innerHTML = '';
      }, { once: true });
    }

    // auto hide
    if (autoHideMs > 0) {
      setTimeout(() => {
        box.classList.add('d-none');
        box.className = 'alert d-none';
        box.innerHTML = '';
      }, autoHideMs);
    }
  }

  // ---- Selection helpers ----
  function resolveId(cb) {
    const tr = cb.closest('tr');
    return String(cb.value || tr?.dataset.id || tr?.dataset.entryid || cb.dataset.entryid || '').trim();
  }

  function getSelectedIds() {
    return [...tbody.querySelectorAll('input[type="checkbox"]:checked')]
      .map(resolveId)
      .filter(v => /^\d+$/.test(v))
      .map(v => parseInt(v, 10));
  }

  // ---- Print ----
  btn.addEventListener('click', async (e) => {
    e.preventDefault();

    const selected = getSelectedIds();
    if (!selected.length) {
      showAlert('warning', 'No rows selected.');
      return;
    }

    // form action must exist
    const url = (form.action || '').trim();
    if (!url) {
      console.error('printLabelsForm action is empty');
      showAlert('danger', 'Print failed: form action is empty.');
      return;
    }

    // Fill hidden inputs for PHP
    mode.value = 'saved';
    ids.value  = JSON.stringify(selected);
    rows.value = '';

    const fd = new FormData(form);

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = 'Printing...';

    try {
      const res = await fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });

      const text = await res.text();
      let data = null;

      try { data = JSON.parse(text); } catch {}

      // Not JSON -> show raw in console
      if (!data) {
        console.error('Print response not JSON:', text);
        showAlert('danger', 'Print failed ❌ (server did not return JSON). Check console.');
        return;
      }

      // PHP returned error JSON OR non-200
      if (!res.ok || data.ok !== true) {
        console.error('Print failed response:', data);
        showAlert('danger', data.message || 'Print failed ❌');
        return;
      }

      // Success
      showAlert('success', data.message || 'Printed ✅');

    } catch (err) {
      console.error('Print request error:', err);
      showAlert('danger', 'Print failed ❌ (network/server error)');
    } finally {
      btn.disabled = false;
      btn.textContent = oldText;
    }
  });
});




// To enable Ajax mode later, just uncomment:
// hookForm('qtyForm',    'qty_Mode',    'qty_EntryID',    'qty_Amount');
// hookForm('deductForm', 'deduct_Mode', 'deduct_EntryID', 'deduct_Amount');
