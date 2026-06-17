document.addEventListener('DOMContentLoaded', () => {

  // ----------------------------
  // Optional: client-side table search (only runs if elements exist)
  // ----------------------------
  (function(){
    const form  = document.getElementById('txSearchForm');
    const input = document.getElementById('txSearchInput');
    const clearX = document.getElementById('txSearchClearX');
    const meta  = document.getElementById('txSearchMeta');
    const tbody = document.querySelector('tbody');

    // If your page doesn't have these, skip this feature safely
    if (!form || !input || !clearX || !meta || !tbody) return;

    const rows = [...tbody.querySelectorAll('tr')].map(tr => {
      const raw = [...tr.children].map(td => td.innerText);
      tr.dataset.raw = raw.join(' ').toLowerCase();
      return tr;
    });

    function apply(){
      const q = (input.value || '').toLowerCase().trim();
      let vis = 0;

      rows.forEach(tr => {
        const show = !q || (tr.dataset.raw || '').includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) vis++;
      });

      meta.textContent = q ? `${vis} / ${rows.length} match "${q}"` : '';
      clearX.classList.toggle('d-none', !q);
    }

    input.addEventListener('input', apply);
    form.addEventListener('submit', e => { e.preventDefault(); apply(); });
    clearX.addEventListener('click', () => { input.value = ''; apply(); input.focus(); });

    apply();
  })();


  // ----------------------------
  // Advanced Filters toggle (this is what you want)
  // ----------------------------
  (function () {
    const btn = document.getElementById('btnAdvToggle');
    const adv = document.getElementById('advFilters');
    if (!btn || !adv) return;

    function isOpen() { return adv.style.display !== 'none'; }

  function open() {
  adv.style.display = '';
  btn.classList.remove('btn-outline-primary');
  btn.classList.add('btn-primary');
  btn.setAttribute('aria-expanded', 'true');
}

function close() {
  adv.style.display = 'none';
  btn.classList.remove('btn-primary');
  btn.classList.add('btn-outline-primary');
  btn.setAttribute('aria-expanded', 'false');
}


    btn.addEventListener('click', () => {
      isOpen() ? close() : open();
    });

    // Auto-open if filters already active (set by PHP as data-has-adv="1")
    if (btn.dataset.hasAdv === '1') open();
    else close();
  })();

});
