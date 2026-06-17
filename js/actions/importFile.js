// Split from original designScript.js

// ===== Import CSV (strict header check) =====
(function(){
  const btn  = document.getElementById('btnImportCSV');
  const form = document.getElementById('importForm');
  const file = document.getElementById('importFile');
  if (!btn || !form || !file) return;

  btn.addEventListener('click', () => file.click());
  file.addEventListener('change', () => {
    if (!file.files || !file.files[0]) return;
    form.submit();
  });
})();

