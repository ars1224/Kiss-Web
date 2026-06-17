// shared-helpers.js
// Put this in a <script> tag BEFORE designScript.js and locationAddPallet.js

// ----- generic numeric helper -----
function parseNum(value) {
  if (value == null) return 0;
  const cleaned = String(value).replace(/[^0-9.-]/g, '').trim();
  const n = parseFloat(cleaned);
  return isNaN(n) ? 0 : n;
}

// ----- small date/time helpers for codes -----
const pad2 = (n) => String(n).padStart(2, '0');

function nowDateTimeString() {
  const d = new Date();
  return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()} `
       + `${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`;
}

// 6-digit random code for EntryCode6
function generate6DigitCode() {
  return Math.floor(100000 + Math.random() * 900000).toString();
}

// ----- expiry input mask (MM/YYYY) -----
function wireExpiryMask(input) {
  if (!input) return;
  input.addEventListener('input', () => {
    let v = input.value.replace(/[^\d]/g, '').slice(0, 6); // MMYYYY
    if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2);
    input.value = v;
  });
  input.addEventListener('blur', () => {
    const v = input.value.trim();
    const ok = v === '' || /^(0[1-9]|1[0-2])\/\d{4}$/.test(v);
    input.setCustomValidity(ok ? '' : 'Use MM/YYYY (e.g. 03/2027) or leave blank');
  });
}

// ----- unit type selector -----
function wireUnitType(select) {
  if (!select) return;
  select.addEventListener('change', () => {
    if (select.value === 'other') {
      const custom = prompt('Enter a new unit type:');
      if (custom && custom.trim()) {
        const val = custom.trim();
        const opt = document.createElement('option');
        opt.value = val.toLowerCase();
        opt.textContent = val;
        select.insertBefore(opt, select.querySelector('option[value="other"]'));
        select.value = opt.value;
      } else {
        select.value = 'pcs';
      }
    }
  });
}
