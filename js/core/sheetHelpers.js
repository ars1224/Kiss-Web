// Split from original designScript.js

// ===== Helpers: focus / sheets / qty preview =====
function focusAndSelect(id) {
  const el = document.getElementById(id);
  if (!el) return;
  setTimeout(() => { el.focus(); el.select(); }, 0);
}

function observeSheet(sheetId, inputId) {
  const sheet = document.getElementById(sheetId);
  if (!sheet) return;

  const observer = new MutationObserver(() => {
    const hidden = sheet.getAttribute('aria-hidden');
    const isVisible =
      hidden === null ||
      hidden === 'false' ||
      sheet.classList.contains('active') ||
      sheet.style.display !== 'none';
    if (isVisible) focusAndSelect(inputId);
  });
  observer.observe(sheet, { attributes: true, attributeFilter: ['class','aria-hidden','style'] });
}

observeSheet('qtySheet',    'qty_Amount');
observeSheet('deductSheet', 'deduct_Amount');

const qtyMode    = document.getElementById('qty_Mode');
const qtyCurrent = document.getElementById('qty_Current');
const qtyAmount  = document.getElementById('qty_Amount');
const qtyNew     = document.getElementById('qty_NewTotal');

function updateQtyPreview() {
  if (!qtyCurrent || !qtyAmount || !qtyNew || !qtyMode) return;
  const cur   = parseNum(qtyCurrent.value);
  const delta = Math.abs(parseNum(qtyAmount.value));
  const mode  = (qtyMode.value || 'add').toLowerCase();
  const next  = mode === 'add' ? cur + delta : cur - delta;
  qtyNew.value = String(next < 0 ? 0 : next);
}
qtyAmount?.addEventListener('input', updateQtyPreview);
qtyMode?.addEventListener('change', updateQtyPreview);

const dCur = document.getElementById('deduct_Current');
const dAmt = document.getElementById('deduct_Amount');
const dNew = document.getElementById('deduct_NewTotal');

function updateDeductPreview() {
  if (!dCur || !dAmt || !dNew) return;
  const cur   = parseNum(dCur.value);
  const delta = Math.abs(parseNum(dAmt.value));
  const next  = cur - delta;
  dNew.value = String(next < 0 ? 0 : next);
}
dAmt?.addEventListener('input', updateDeductPreview);

