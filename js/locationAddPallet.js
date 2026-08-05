const ADD_PALLET_DRAFT_PREFIX = 'kiss-web:add-pallet-draft';
const ADD_PALLET_RESTORE_PARAM = 'restoreDraft';
const ADD_PALLET_RESTORE_TTL_MS = 60 * 60 * 1000;

function getAddPalletDraftKey() {
  const inventoryInput = document.querySelector('input[name="inventory"]');
  const inventory = (
    inventoryInput?.value ||
    new URLSearchParams(window.location.search).get('inventory') ||
    'default'
  ).toLowerCase();

  return `${ADD_PALLET_DRAFT_PREFIX}:${inventory}`;
}

function readStorage(key) {
  try {
    return window.sessionStorage.getItem(key);
  } catch (err) {
    return null;
  }
}

function writeStorage(key, value) {
  try {
    window.sessionStorage.setItem(key, value);
  } catch (err) {
    // Draft restore is a convenience feature; storage errors should not block data entry.
  }
}

function removeStorage(key) {
  try {
    window.sessionStorage.removeItem(key);
  } catch (err) {
    // Ignore storage errors.
  }
}

function setSelectValue(select, value) {
  if (!select) return;

  const normalized = value || 'pcs';
  let option = [...select.options].find(opt => opt.value === normalized);

  if (!option && normalized) {
    option = document.createElement('option');
    option.value = normalized;
    option.textContent = normalized;
    select.insertBefore(option, select.querySelector('option[value="other"]'));
  }

  select.value = option ? normalized : 'pcs';
}

function makeRow(data = {}) {
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
        <option value="other">Other...</option>
      </select>
    </td>
    <td><input type="number" name="QtyPerCtn[]" min="0" value="0" required></td>
    <td><input type="number" name="TotalQty[]" min="0" value="0" required></td>
    <td><input name="Comments[]"></td>
    <td><input name="DateAdded[]" readonly></td>
  `;

  const entryCode = data.EntryCode6 || generate6DigitCode();
  tr.querySelector('input[name="EntryCode6[]"]').value = entryCode;
  tr.querySelector('input[name="Location[]"]').value = data.Location || '';
  tr.querySelector('input[name="SKU_Code[]"]').value = data.SKU_Code || '';
  tr.querySelector('input[name="BatchNo[]"]').value = data.BatchNo || '';
  tr.querySelector('input[name="ExpiryDate[]"]').value = data.ExpiryDate || '';
  tr.querySelector('input[name="QtyPerCtn[]"]').value = data.QtyPerCtn ?? '0';
  tr.querySelector('input[name="TotalQty[]"]').value = data.TotalQty ?? '0';
  tr.querySelector('input[name="Comments[]"]').value = data.Comments || '';
  tr.querySelector('input[name="DateAdded[]"]').value = data.DateAdded || nowDateTimeString();

  const checkbox = tr.querySelector('.row-check');
  checkbox.dataset.id = entryCode;
  checkbox.checked = Boolean(data.Selected);

  wireExpiryMask(tr.querySelector('input[name="ExpiryDate[]"]'));
  wireUnitType(tr.querySelector('select[name="UnitType[]"]'));
  setSelectValue(tr.querySelector('select[name="UnitType[]"]'), data.UnitType || 'pcs');

  return tr;
}

function rowToObject(tr) {
  const get = (name) => tr.querySelector(`[name="${name}[]"]`)?.value ?? '';

  return {
    EntryCode6: get('EntryCode6'),
    Location: get('Location'),
    SKU_Code: get('SKU_Code'),
    BatchNo: get('BatchNo'),
    ExpiryDate: get('ExpiryDate'),
    UnitType: get('UnitType'),
    QtyPerCtn: get('QtyPerCtn'),
    TotalQty: get('TotalQty'),
    Comments: get('Comments'),
    DateAdded: get('DateAdded'),
    Selected: Boolean(tr.querySelector('.row-check')?.checked),
  };
}

function rowHasUserData(row) {
  return Boolean(
    row.Location.trim() ||
    row.SKU_Code.trim() ||
    row.BatchNo.trim() ||
    row.ExpiryDate.trim() ||
    row.Comments.trim() ||
    row.UnitType !== 'pcs' ||
    parseNum(row.QtyPerCtn) > 0 ||
    parseNum(row.TotalQty) > 0
  );
}

function shouldRestoreDraft() {
  return new URLSearchParams(window.location.search).get(ADD_PALLET_RESTORE_PARAM) === '1';
}

function cleanRestoreParam() {
  const url = new URL(window.location.href);
  if (!url.searchParams.has(ADD_PALLET_RESTORE_PARAM)) return;

  url.searchParams.delete(ADD_PALLET_RESTORE_PARAM);
  window.history.replaceState({}, document.title, url.toString());
}

// ---------- page wiring ----------
document.addEventListener('DOMContentLoaded', () => {
  const tbody = document.getElementById('entryTbody');
  const addRowBtn = document.getElementById('addRowBtn');
  const removeSelectedBtn = document.getElementById('removeSelectedBtn');
  const duplicateSelectedBtn = document.getElementById('duplicateSelectedBtn');
  const duplicateCount = document.getElementById('duplicateCount');
  const printCount = document.getElementById('printCount');
  const checkAll = document.getElementById('checkAll');
  const form = document.getElementById('multiForm');
  const btnPrintLabels = document.getElementById('btnPrintLabels');
  const printForm = document.getElementById('printLabelsForm');
  const labelMode = document.getElementById('labelMode');
  const labelIds = document.getElementById('labelIds');
  const labelRows = document.getElementById('labelRows');
  const btnImportCSV = document.getElementById('btnImportCSV');
  const importFile = document.getElementById('importFile');
  const importForm = document.getElementById('importForm');

  if (!tbody || !form) return;

  const draftKey = getAddPalletDraftKey();
  let saveTimer = null;

  const showTableMessage = (message, type = 'danger') => {
    let alert = document.getElementById('addPalletMessage');

    if (!alert) {
      alert = document.createElement('div');
      alert.id = 'addPalletMessage';
      alert.setAttribute('role', 'alert');
      form.parentElement?.insertBefore(alert, form);
    }

    alert.className = `alert alert-${type}`;
    alert.textContent = message;
    alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };

  const collectRows = () => [...tbody.querySelectorAll('tr')].map(rowToObject);

  const markInvalid = (tr, fieldName) => {
    const field = tr?.querySelector(`[name="${fieldName}[]"]`);
    field?.classList.add('is-invalid');
  };

  const highlightServerError = () => {
    const message = document.querySelector('.addForm > .alert-danger')?.textContent || '';
    const match = message.match(/Row\s+(\d+):/i);
    if (!match) return;

    const tr = tbody.querySelectorAll('tr')[Number(match[1]) - 1];
    if (!tr) return;

    if (/Location and SKU/i.test(message)) {
      if (!tr.querySelector('[name="Location[]"]')?.value.trim()) markInvalid(tr, 'Location');
      if (!tr.querySelector('[name="SKU_Code[]"]')?.value.trim()) markInvalid(tr, 'SKU_Code');
    }
    if (/Expiry/i.test(message)) markInvalid(tr, 'ExpiryDate');
    if (/TotalQty|Total Qty/i.test(message)) markInvalid(tr, 'TotalQty');
  };

  const saveDraft = (forAuthRestore = false) => {
    const rows = collectRows();
    const meaningfulRows = rows.filter(rowHasUserData);

    if (!meaningfulRows.length) {
      removeStorage(draftKey);
      return;
    }

    const now = Date.now();
    writeStorage(draftKey, JSON.stringify({
      savedAt: now,
      restoreUntil: forAuthRestore ? now + ADD_PALLET_RESTORE_TTL_MS : 0,
      rows,
    }));
  };

  const queueSaveDraft = () => {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(saveDraft, 150);
  };

  const refreshActions = () => {
    const checks = [...tbody.querySelectorAll('.row-check')];
    const checked = checks.filter(cb => cb.checked);
    const hasChecked = checked.length > 0;

    btnPrintLabels?.classList.toggle('d-none', !hasChecked);
    duplicateSelectedBtn?.classList.toggle('d-none', !hasChecked);
    removeSelectedBtn?.classList.toggle('d-none', !hasChecked);
    duplicateCount?.classList.toggle('d-none', !hasChecked);
    printCount?.classList.toggle('d-none', !hasChecked);

    if (checkAll) {
      checkAll.checked = checks.length > 0 && checked.length === checks.length;
      checkAll.indeterminate = checked.length > 0 && checked.length < checks.length;
    }
  };

  const addRow = (data = {}, shouldSave = true) => {
    tbody.appendChild(makeRow(data));
    refreshActions();
    if (shouldSave) saveDraft();
  };

  const restoreServerDraft = () => {
    const dataElement = document.getElementById('addPalletServerDraft');
    if (!dataElement?.textContent.trim()) return false;

    try {
      const rows = JSON.parse(dataElement.textContent);
      if (!Array.isArray(rows) || !rows.length) return false;

      tbody.innerHTML = '';
      rows.forEach(row => addRow(row, false));
      refreshActions();
      saveDraft();
      return true;
    } catch (err) {
      return false;
    }
  };

  const restoreDraft = () => {
    if (!shouldRestoreDraft()) return false;

    const raw = readStorage(draftKey);
    cleanRestoreParam();
    if (!raw) return false;

    try {
      const draft = JSON.parse(raw);
      if (!draft.restoreUntil || draft.restoreUntil < Date.now()) {
        removeStorage(draftKey);
        return false;
      }

      const rows = Array.isArray(draft.rows) ? draft.rows : [];
      const meaningfulRows = rows.filter(rowHasUserData);

      if (!meaningfulRows.length) return false;

      tbody.innerHTML = '';
      rows.forEach(row => addRow(row, false));
      refreshActions();
      return true;
    } catch (err) {
      removeStorage(draftKey);
      return false;
    }
  };

  const removeSelected = () => {
    [...tbody.querySelectorAll('tr')].forEach(tr => {
      if (tr.querySelector('.row-check')?.checked) tr.remove();
    });

    if (!tbody.querySelector('tr')) addRow({}, false);
    refreshActions();
    saveDraft();
  };

  const duplicateSelected = () => {
    const selectedRows = [...tbody.querySelectorAll('tr')]
      .filter(tr => tr.querySelector('.row-check')?.checked);

    if (!selectedRows.length) {
      alert('Please select row(s) to duplicate.');
      return;
    }

    const copies = parseInt(duplicateCount?.value, 10) || 1;
    if (isNaN(copies) || copies <= 0) {
      alert('Invalid duplicate quantity.');
      return;
    }

    selectedRows.forEach(tr => {
      const source = rowToObject(tr);

      for (let i = 0; i < copies; i++) {
        addRow({
          ...source,
          EntryCode6: generate6DigitCode(),
          DateAdded: nowDateTimeString(),
          Selected: false,
        }, false);
      }
    });

    refreshActions();
    saveDraft();
  };

  const selectedDraftRows = () => collectRows()
    .filter(row => row.Selected)
    .map(row => ({
      EntryCode: row.EntryCode6,
      Location: row.Location,
      SKU_Code: row.SKU_Code,
      BatchNo: row.BatchNo,
      ExpiryDate: row.ExpiryDate,
      UnitType: row.UnitType,
      QtyPerCtn: parseInt(row.QtyPerCtn, 10) || 0,
      TotalQty: parseInt(row.TotalQty, 10) || 0,
      Comments: row.Comments,
    }));

  addRowBtn?.addEventListener('click', () => addRow());
  removeSelectedBtn?.addEventListener('click', removeSelected);
  duplicateSelectedBtn?.addEventListener('click', duplicateSelected);

  checkAll?.addEventListener('change', () => {
    tbody.querySelectorAll('.row-check').forEach(cb => {
      cb.checked = checkAll.checked;
    });
    refreshActions();
    saveDraft();
  });

  tbody.addEventListener('input', (event) => {
    event.target?.classList?.remove('is-invalid');
    queueSaveDraft();
  });
  tbody.addEventListener('change', (event) => {
    if (event.target?.classList?.contains('row-check')) refreshActions();
    queueSaveDraft();
  });

  form.addEventListener('submit', (event) => {
    saveDraft(true);

    const meaningfulRows = collectRows()
      .map((row, index) => ({ row, number: index + 1 }))
      .filter(({ row }) => rowHasUserData(row));

    if (!meaningfulRows.length) {
      event.preventDefault();
      const firstRow = tbody.querySelector('tr');
      markInvalid(firstRow, 'Location');
      markInvalid(firstRow, 'SKU_Code');
      markInvalid(firstRow, 'TotalQty');
      showTableMessage('Add at least one row before saving.');
      return;
    }

    for (const { row, number } of meaningfulRows) {
      if (!row.Location.trim() || !row.SKU_Code.trim()) {
        event.preventDefault();
        const tr = tbody.querySelectorAll('tr')[number - 1];
        if (!row.Location.trim()) markInvalid(tr, 'Location');
        if (!row.SKU_Code.trim()) markInvalid(tr, 'SKU_Code');
        showTableMessage(`Row ${number}: Location and SKU are required. Your entered data has been kept.`);
        return;
      }

      if (row.ExpiryDate.trim() && !/^(0[1-9]|1[0-2])\/\d{4}$/.test(row.ExpiryDate.trim())) {
        event.preventDefault();
        markInvalid(tbody.querySelectorAll('tr')[number - 1], 'ExpiryDate');
        showTableMessage(`Row ${number}: Expiry Date must use MM/YYYY or be left blank. Your entered data has been kept.`);
        return;
      }

      if (parseNum(row.TotalQty) <= 0) {
        event.preventDefault();
        markInvalid(tbody.querySelectorAll('tr')[number - 1], 'TotalQty');
        showTableMessage(`Row ${number}: Total Qty must be greater than 0. Your entered data has been kept.`);
        return;
      }
    }
  });
  form.addEventListener('reset', () => {
    window.setTimeout(() => {
      tbody.innerHTML = '';
      removeStorage(draftKey);
      addRow({}, false);
      refreshActions();
    }, 0);
  });

  btnPrintLabels?.addEventListener('click', () => {
    const data = selectedDraftRows();
    if (!data.length || !printForm || !labelMode || !labelIds || !labelRows) return;

    const copies = parseInt(printCount?.value, 10) || 1;
    if (isNaN(copies) || copies <= 0) {
      alert('Invalid print quantity.');
      return;
    }

    const expanded = [];
    data.forEach(row => {
      for (let i = 0; i < copies; i++) expanded.push(row);
    });

    saveDraft(true);
    labelMode.value = 'draft';
    labelIds.value = '';
    labelRows.value = JSON.stringify(expanded);
    printForm.requestSubmit();
  });

  if (btnImportCSV && importFile && importForm) {
    btnImportCSV.addEventListener('click', () => {
      importFile.click();
    });

    importFile.addEventListener('change', () => {
      if (importFile.files && importFile.files.length > 0) {
        saveDraft(true);
        importForm.submit();
      }
    });
  }

  if (!restoreServerDraft() && !restoreDraft()) addRow({}, false);
  refreshActions();
  highlightServerError();
});
