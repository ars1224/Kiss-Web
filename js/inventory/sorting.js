// Split from original designScript.js

// ===== Click-to-sort (text, number, date) =====
document.querySelectorAll('.product-table thead th').forEach((th, index) => {
  if (th.classList.contains('select')) return;

  th.style.cursor = 'pointer';
  th.title = 'Click to sort';
  let asc = true;

  th.addEventListener('click', () => {
    const table = th.closest('table');
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));

    const type = (th.dataset.type || 'text').toLowerCase();

    const getText = (row) => (row.children[index]?.innerText ?? '').trim();
    const getNum  = (row) => {
      const s = getText(row).replace(/,/g, '');
      const n = parseFloat(s);
      return Number.isNaN(n) ? 0 : n;
    };
    const getDateTs = (row) => {
      const td  = row.children[index];
      const raw = td?.getAttribute('data-full-date') || getText(row);
      const ts  = parseDate(raw);
      return Number.isNaN(ts)
        ? (asc ? Number.POSITIVE_INFINITY : Number.NEGATIVE_INFINITY)
        : ts;
    };

    rows.sort((a, b) => {
      if (type === 'number') return asc ? getNum(a) - getNum(b) : getNum(b) - getNum(a);
      if (type === 'date')   return asc ? getDateTs(a) - getDateTs(b) : getDateTs(b) - getDateTs(a);
      const A = getText(a), B = getText(b);
      return asc ? A.localeCompare(B, undefined, { sensitivity: 'base' })
                 : B.localeCompare(A, undefined, { sensitivity: 'base' });
    });

    rows.forEach(r => tbody.appendChild(r));
    asc = !asc;

    document.querySelectorAll('.product-table thead th')
      .forEach(t => t.classList.remove('sorted-asc', 'sorted-desc'));
    th.classList.add(asc ? 'sorted-desc' : 'sorted-asc');

    if (typeof window.colorizeExpiry === 'function') {
      setTimeout(window.colorizeExpiry, 0);
    }
  });
});

