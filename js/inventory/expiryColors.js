// Split from original designScript.js

// === Expiry coloring (red = expired, orange = within 18 months) ===
(function () {
  const tableEl = document.querySelector('.product-table');
  if (!tableEl) return;

  function findExpiryCol() {
    const ths = [...tableEl.querySelectorAll('thead th')];
    const byType = ths.findIndex(th => (th.dataset.type || '').toLowerCase() === 'date');
    if (byType >= 0) return byType;
    const byText = ths.findIndex(th => /expiry/i.test(th.textContent));
    return byText >= 0 ? byText : 4;
  }
  let EXP_COL = findExpiryCol();

  function parseExpiry(val) {
    if (!val) return null;
    const t = String(val).trim();
    const m = t.match(/^(\d{1,2})[\/\-](\d{4})$/);
    if (m) {
      const mm = +m[1], yy = +m[2];
      if (mm >= 1 && mm <= 12) return new Date(yy, mm - 1, 1);
      return null;
    }
    const d = new Date(t);
    return isNaN(d.getTime()) ? null : d;
  }

  const monthStart = d => new Date(d.getFullYear(), d.getMonth(), 1);
  const addMonths  = (d,n) => new Date(d.getFullYear(), d.getMonth()+n, 1);

  function colorize() {
    EXP_COL = findExpiryCol();
    const todayM     = monthStart(new Date());
    const soonCutoff = addMonths(todayM, 18);

    tableEl.querySelectorAll('tbody tr').forEach(tr => {
      const td = tr.children[EXP_COL];
      if (!td) return;

      td.classList.remove('expired', 'expiring');

      const raw = td.getAttribute('data-full-date') || td.textContent;
      const exp = parseExpiry(raw);
      if (!exp) return;

      const expM = monthStart(exp);
      if (expM < todayM) {
        td.classList.add('expired');
        td.title = 'Expired';
      } else if (expM <= soonCutoff) {
        td.classList.add('expiring');
        td.title = 'Expiring within 18 months';
      } else {
        td.removeAttribute('title');
      }
    });
  }

  colorize();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', colorize);
  }
  window.colorizeExpiry = colorize;
})();

