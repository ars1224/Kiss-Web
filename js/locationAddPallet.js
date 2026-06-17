// ---------- row template ----------
function makeRow(){
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td class="select-col"><input type="checkbox" class="row-check"></td>
    <td><input name="EntryCode6[]" readonly></td>
    <td><input name="Location[]" required></td>
    <td><input name="SKU_Code[]" required></td>
    <td><input name="BatchNo[]"></td>
    <td><input name="ExpiryDate[]" placeholder="MM/YYYY" inputmode="numeric"></td>
    <td>
      <select name="UnitType[]" class="text-center">
        <option value="pcs">pcs</option>
        <option value="pale">pale</option>
        <option value="kg">kg</option>
        <option value="gal">gal</option>
        <option value="other">Other…</option>
      </select>
    </td>
    <td><input type="number" name="QtyPerCtn[]" min="0" value="0" required></td>
    <td><input type="number" name="TotalQty[]" min="0" value="0" required></td>
    <td><input name="Comments[]"></td>
    <td><input name="DateAdded[]" readonly></td>
  `;

tr.querySelector('input[name="EntryCode6[]"]').value = generate6DigitCode();
tr.querySelector('input[name="DateAdded[]"]').value  = nowDateTimeString();

// ✅ set printable ID from EntryCode6 (works on add page)
tr.querySelector('.row-check').dataset.id =
  tr.querySelector('input[name="EntryCode6[]"]').value;


  wireExpiryMask(tr.querySelector('input[name="ExpiryDate[]"]'));
  wireUnitType(tr.querySelector('select[name="UnitType[]"]'));

  return tr;
}

// ---------- page wiring ----------
document.addEventListener('DOMContentLoaded', ()=>{
  const tbody = document.getElementById('entryTbody');
  const addRowBtn = document.getElementById('addRowBtn');
  const removeSelectedBtn = document.getElementById('removeSelectedBtn');
  const duplicateSelectedBtn = document.getElementById('duplicateSelectedBtn');
  const checkAll = document.getElementById('checkAll');
  const form = document.getElementById('multiForm');

  const addRow = ()=> tbody.appendChild(makeRow());
  const removeSelected = ()=>{
    [...tbody.querySelectorAll('tr')].forEach(tr=>{
      if (tr.querySelector('.row-check')?.checked) tr.remove();
    });
  };

const duplicateSelected = () => {
  const selectedRows = [...tbody.querySelectorAll('tr')]
    .filter(tr => tr.querySelector('.row-check')?.checked);

  if (!selectedRows.length) {
    alert('Please select row(s) to duplicate.');
    return;
  }

const duplicateCount =
  parseInt(document.getElementById('duplicateCount').value, 10) || 1;

  if (isNaN(duplicateCount) || duplicateCount <= 0) {
    alert('Invalid duplicate quantity.');
    return;
  }

  selectedRows.forEach(tr => {

    for (let i = 0; i < duplicateCount; i++) {

      const clone = tr.cloneNode(true);

      // generate NEW EntryCode
      const newCode = generate6DigitCode();

      clone.querySelector('input[name="EntryCode6[]"]').value = newCode;

      // update checkbox dataset id
      clone.querySelector('.row-check').dataset.id = newCode;

      // uncheck duplicated row
      clone.querySelector('.row-check').checked = false;

      // update DateAdded
      clone.querySelector('input[name="DateAdded[]"]').value = nowDateTimeString();

      tbody.appendChild(clone);
    }
  });

  refresh();
};

  addRowBtn.addEventListener('click', addRow);
  removeSelectedBtn.addEventListener('click', removeSelected);
  duplicateSelectedBtn.addEventListener('click', duplicateSelected);
  checkAll.addEventListener('change', ()=>{
    tbody.querySelectorAll('.row-check').forEach(cb => cb.checked = checkAll.checked);
  });

  // initial row
  addRow();

  // before submit: drop empty rows + validate ExpiryDate format again
  form.addEventListener('submit', (e)=>{
    const rows = [...tbody.querySelectorAll('tr')];
    rows.forEach(tr=>{
      const loc = tr.querySelector('[name="Location[]"]').value.trim();
      const sku = tr.querySelector('[name="SKU_Code[]"]').value.trim();
      if (!loc && !sku) tr.remove(); // ignore empty rows
    });
    if (!tbody.querySelector('tr')){
      e.preventDefault();
      alert('Add at least one row before saving.');
      return;
    }
    // Final check MM/YYYY
   const allOk = [...tbody.querySelectorAll('input[name="ExpiryDate[]"]')]
  .every(el => {
    const v = el.value.trim();
    return v === '' || /^(0[1-9]|1[0-2])\/\d{4}$/.test(v);
  });
if (!allOk) {
  e.preventDefault();
  alert('Please use MM/YYYY or leave ExpiryDate blank.');
}

  });
});

(function(){
  const btn = document.getElementById('btnPrintLabels');
  const form = document.getElementById('printLabelsForm');
  const mode = document.getElementById('labelMode');
  const ids  = document.getElementById('labelIds');
  const rows = document.getElementById('labelRows');
  const tbody = document.getElementById('entryTbody');
  if (!btn || !form || !mode || !ids || !rows || !tbody) return;

  function selectedDraftRows(){
    const out = [];
    const checks = tbody.querySelectorAll('.row-check:checked');

    checks.forEach(cb => {
      const tr = cb.closest('tr');
      if (!tr) return;

      const get = (name) => tr.querySelector(`[name="${name}[]"]`)?.value ?? '';

      out.push({
        EntryCode: get('EntryCode6'),
        Location:  get('Location'),
        SKU_Code:  get('SKU_Code'),
        BatchNo:   get('BatchNo'),
        ExpiryDate:get('ExpiryDate'),
        UnitType:  get('UnitType'),
        QtyPerCtn: parseInt(get('QtyPerCtn'), 10) || 0,
        TotalQty:  parseInt(get('TotalQty'), 10) || 0,
        Comments:  get('Comments'),
      });
    });

    return out;
  }

 function refresh(){
  const hasChecked =
    tbody.querySelectorAll('.row-check:checked').length > 0;

  const duplicateSelectedBtn = document.getElementById('duplicateSelectedBtn');
  const removeSelectedBtn = document.getElementById('removeSelectedBtn');
  const duplicateCount = document.getElementById('duplicateCount');
  const printCount = document.getElementById('printCount');

  btn.classList.toggle('d-none', !hasChecked);

  if (duplicateSelectedBtn) {
    duplicateSelectedBtn.classList.toggle('d-none', !hasChecked);
  }

  if (removeSelectedBtn) {
    removeSelectedBtn.classList.toggle('d-none', !hasChecked);
  }

  if (duplicateCount) {
    duplicateCount.classList.toggle('d-none', !hasChecked);
  }

  if (printCount) {
    printCount.classList.toggle('d-none', !hasChecked);
  }
}

  tbody.addEventListener('change', (e)=>{
    if (e.target && e.target.classList && e.target.classList.contains('row-check')) refresh();
  });

btn.addEventListener('click', ()=>{

  let data = selectedDraftRows();

  if (!data.length) return;

const copies =
  parseInt(document.getElementById('printCount').value, 10) || 1;

  if (isNaN(copies) || copies <= 0) {
    alert('Invalid print quantity.');
    return;
  }

  // duplicate rows for printing
  const expanded = [];

  data.forEach(row => {
    for (let i = 0; i < copies; i++) {
      expanded.push(row);
    }
  });

  mode.value = 'draft';
  ids.value  = '';
  rows.value = JSON.stringify(expanded);
  form.requestSubmit();
});

  refresh();
})();

document.getElementById('btnImportCSV').addEventListener('click', () => {
  document.getElementById('importFileReal').click();
});

document.getElementById('importFileReal').addEventListener('change', function () {
  if (this.files.length > 0) {
    document.getElementById('importForm').submit();
  }
});

document.addEventListener('DOMContentLoaded', () => {
  const btnImportCSV = document.getElementById('btnImportCSV');
  const importFile = document.getElementById('importFile');
  const importForm = document.getElementById('importForm');

  if (btnImportCSV && importFile && importForm) {
    btnImportCSV.addEventListener('click', () => {
      importFile.click();
    });

    importFile.addEventListener('change', () => {
      if (importFile.files && importFile.files.length > 0) {
        importForm.submit();
      }
    });
  }
});