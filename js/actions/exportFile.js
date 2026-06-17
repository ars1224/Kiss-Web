// Split from original designScript.js

// ===== Export CSV/XLSX with real "Save As" dialog (with fallback) =====
(function () {
  const btn   = document.getElementById('btnExportSelected');
  const tbody = document.querySelector('.product-table tbody');
  const form  = document.getElementById('exportSelectedForm');
  if (!btn || !tbody || !form) return;

  const EXPORT_URL = form.getAttribute('action') || 'php/export_file.php';

  btn.addEventListener('click', async (e) => {
    e.preventDefault();

    const checked = Array.from(
      tbody.querySelectorAll('input[type="checkbox"]:checked')
    );
    if (!checked.length) {
      alert('Select at least one row to export.');
      return;
    }

    const ids = checked
      .map(cb => {
        const row = cb.closest('tr');
        const id  = (cb.value || row?.dataset.id || '').trim();
        return /^\d+$/.test(id) ? id : null;
      })
      .filter(Boolean);

    if (!ids.length) {
      alert('Selected rows are missing EntryID values.');
      return;
    }

    try {
      const payload = new FormData();
      payload.append('ids_csv', ids.join(','));

     const response = await fetch(EXPORT_URL, {
  method: 'POST',
  body: payload
});

console.log('Export response status:', response.status, response.statusText);

if (!response.ok) {
  const errorBody = await response.text();
  console.error('Export error body:', errorBody);

  alert('Export failed (server error). Falling back to normal download.');

  // fall back to old behaviour so at least you still get a file
  form.innerHTML = '';
  const inp = document.createElement('input');
  inp.type = 'hidden';
  inp.name = 'ids_csv';
  inp.value = ids.join(',');
  form.appendChild(inp);
  form.submit();
  return;
}


      const blob = await response.blob();

      if ('showSaveFilePicker' in window && window.showSaveFilePicker) {
        const d = new Date();
        const day   = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year  = d.getFullYear();
        const suggestedName = `WHL ${day}-${month}-${year}.xlsx`;

        const handle = await window.showSaveFilePicker({
          suggestedName,
          types: [
            {
              description: 'Excel Workbook',
              accept: {
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx']
              }
            }
          ]
        });

        const writable = await handle.createWritable();
        await writable.write(blob);
        await writable.close();

        alert('File saved successfully!');
        return;
      }

      const url = URL.createObjectURL(blob);
      const a   = document.createElement('a');
      a.href = url;

      const d = new Date();
      const day   = String(d.getDate()).padStart(2, '0');
      const month = String(d.getMonth() + 1).padStart(2, '0');
      const year  = d.getFullYear();
      a.download = `WHL ${day}-${month}-${year}.xlsx`;

      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      console.error('Export fetch failed:', err);
    }
  });
})();

