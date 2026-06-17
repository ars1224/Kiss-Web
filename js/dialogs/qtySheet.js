// Split from original designScript.js

// ===== Add/Deduct (qty) sheets =====
(function(){
  const btnAdd = document.getElementById('btnAddQty');
  const btnDed = document.getElementById('btnDeduct');
  if (!btnAdd && !btnDed) return;

  const sheet   = document.getElementById('qtySheet');
  const overlay = document.querySelector('.overlay');
  const titleEl = document.getElementById('qtyTitle');
  const submitEl= document.getElementById('qtySubmit');
  const modeEl  = document.getElementById('qty_Mode');

  const open  = () => { sheet?.classList.add('open'); overlay?.classList.add('show'); };
  const close = () => { sheet?.classList.remove('open'); overlay?.classList.remove('show'); };

  document.getElementById('qtyClose')?.addEventListener('click', close);
  document.getElementById('qtyCancel')?.addEventListener('click', close);
  overlay?.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  const tbody = document.querySelector('.product-table tbody');
  const getRow = () => {
    const cbs = tbody?.querySelectorAll('input[type="checkbox"]:checked') || [];
    if (cbs.length !== 1) { alert('Select exactly ONE row.'); return null; }
    return cbs[0].closest('tr');
  };

  const f = {
    id:  document.getElementById('qty_EntryID'),
    inv: document.getElementById('qty_InventoryType'),
    loc: document.getElementById('qty_Location'),
    sku: document.getElementById('qty_SKU'),
    bat: document.getElementById('qty_Batch'),
    exp: document.getElementById('qty_Expiry'),
    unt: document.getElementById('qty_Unit'),
    qpc: document.getElementById('qty_QtyCtn'),
    cur: document.getElementById('qty_Current'),
    amt: document.getElementById('qty_Amount'),
    new: document.getElementById('qty_NewTotal'),
    com: document.getElementById('qty_Comments'),
  };

  function prefill(row){
    const cb = row.querySelector('input[type="checkbox"]');
    const entryId = (cb?.value || row.dataset.id || '').trim();
    if (!/^\d+$/.test(entryId)) {
      alert('Row missing numeric EntryID (checkbox value or tr[data-id]).');
      return false;
    }

    f.id.value = entryId;
const checked = document.querySelector('.row-check:checked');
const realRow = checked ? checked.closest('tr') : row;

f.inv.value = realRow.getAttribute('data-inventory-type') || '';

console.log('QTY InventoryType:', f.inv.value);
    const cell = i => (row.children[i]?.innerText || '').trim();
    f.loc.value = cell(1);
    f.sku.value = cell(2);
    f.bat.value = cell(3);
    f.exp.value = cell(4);
    f.unt.value = cell(5);
    f.qpc.value = cell(6);
    f.cur.value = cell(7);
    f.com.value = cell(8);
    f.amt.value = '';
    f.new.value = f.cur.value;
    return true;
  }

  function recalc(){
    const cur = parseNum(f.cur.value);
    const amt = Math.max(0, Math.floor(parseNum(f.amt.value)));
    const isAdd = modeEl.value === 'add';
    let nt = isAdd ? (cur + amt) : (cur - amt);
    if (!isAdd && nt < 0) nt = 0;
    f.amt.value = amt ? String(amt) : '';
    f.new.value = nt.toLocaleString();
  }
  f.amt?.addEventListener('input', recalc);

  function openAdjust(mode){
    const row = getRow();
    if (!row) return;
    if (!prefill(row)) return;

    modeEl.value = mode; // 'add' | 'deduct'
    if (mode === 'add')   { titleEl.textContent = 'Add Quantity';    submitEl.textContent = 'Add & Save'; }
    if (mode === 'deduct'){ titleEl.textContent = 'Deduct Quantity'; submitEl.textContent = 'Deduct & Save'; }
    open();
  }

  btnAdd?.addEventListener('click',   () => openAdjust('add'));
  btnDed?.addEventListener('click',   () => openAdjust('deduct'));
})();

