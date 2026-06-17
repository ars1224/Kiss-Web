let manualLines = [];
let manualLinesBody = null;
let previewBody = null;

document.addEventListener('DOMContentLoaded', () => {
    manualLinesBody = document.getElementById('manualLinesBody');
    previewBody = document.getElementById('previewBody');

    const orderDate = document.getElementById('order_date');
    if (orderDate && !orderDate.value) {
        orderDate.value = new Date().toISOString().split('T')[0];
    }

    hidePreview();

    const params = new URLSearchParams(window.location.search);
    const editId = params.get('edit');

    if (editId) {
        loadOrderForEdit(editId);
    }
});

document.getElementById('addLineBtn').addEventListener('click', () => {
    const sku = document.getElementById('line_sku').value.trim();
    const desc = document.getElementById('line_desc').value.trim();
    const qty = parseFloat(document.getElementById('line_qty').value);

    if (!sku || Number.isNaN(qty) || qty <= 0) {
        alert('Please enter SKU and a valid quantity.');
        return;
    }

    manualLines.push({
        sku_code: sku,
        description: desc,
        quantity: qty
    });

    document.getElementById('line_sku').value = '';
    document.getElementById('line_desc').value = '';
    document.getElementById('line_qty').value = '';

    renderManualLines();
});

document.getElementById('importOrderBtn').addEventListener('click', async () => {
    const fileInput = document.getElementById('orderFile');

    if (!fileInput.files || !fileInput.files.length) {
        alert('Please choose a file first.');
        return;
    }

    const formData = new FormData();
    formData.append('order_file', fileInput.files[0]);

    try {
        const response = await fetch('php/functions/import_order_file.php', {
            method: 'POST',
            body: formData
        });

        const result = await parseJsonResponse(response, 'Raw import response');

        if (!result.success) {
            alert(result.message || 'Import failed.');
            return;
        }

        const header = result.order_header || {};
        const lines = result.order_lines || [];

        document.getElementById('invoice_no').value = header.invoice_no || '';
        document.getElementById('order_date').value = header.order_date || new Date().toISOString().split('T')[0];
        document.getElementById('delivery_date').value = header.delivery_date || '';
        document.getElementById('customer_code').value = header.customer_code || '';
        document.getElementById('customer_name').value = header.customer_name || '';
        document.getElementById('customer_address').value = header.customer_address || '';
        document.getElementById('order_number').value = header.order_number || '';
        document.getElementById('packing_slip').value = header.packing_slip || '';
        document.getElementById('internal_reference').value = header.internal_reference || '';
        document.getElementById('purchase_number').value = header.purchase_number || '';
        document.getElementById('sales_person').value = header.sales_person || '';

        manualLines = lines.map(line => ({
            sku_code: String(line.sku_code || '').trim(),
            description: String(line.description || '').trim(),
            quantity: parseFloat(line.quantity || 0)
        })).filter(line => line.sku_code && line.quantity > 0);

        renderManualLines();
        renderPreview([]);
        hidePreview();

        showSuccessMessage('File imported successfully.');
    } catch (error) {
        console.error(error);
        alert(error.message || 'Import request failed. Check browser console.');
    }
});

document.getElementById('previewOrderBtn').addEventListener('click', async () => {
    if (manualLines.length === 0) {
        alert('Add at least one line first.');
        return;
    }

    const payload = collectOrderData();
    payload.action = 'preview';

    try {
        const response = await fetch('php/functions/orders.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const result = await parseJsonResponse(response, 'Raw preview response');

        if (!result.success) {
            alert(result.message || 'Preview failed.');
            return;
        }

        renderPreview(result.preview_rows || []);
        showPreview();
    } catch (error) {
        console.error(error);
        alert(error.message || 'Preview request failed. Check browser console.');
    }
});

document.getElementById('ordersForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    if (manualLines.length === 0) {
        alert('Add at least one line first.');
        return;
    }

    const payload = collectOrderData();
    payload.action = 'save';

    try {
        const response = await fetch('php/functions/orders.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const result = await parseJsonResponse(response, 'Raw save response');

        if (result.success) {

            const isEdit = new URLSearchParams(window.location.search).has('edit');

            alert(isEdit
                ? 'Order updated successfully.'
                : 'Order created successfully.'
            );

            if (isEdit) {

                // back to orders list
                window.location.href = 'orders_list.php';

            } else {

                // reload clean form
                window.location.reload();
            }

            return;
        }


        document.getElementById('ordersForm').reset();

        const orderDate = document.getElementById('order_date');
        if (orderDate) {
            orderDate.value = new Date().toISOString().split('T')[0];
        }

        manualLines = [];
        renderManualLines();
        renderPreview([]);
        hidePreview();
    } catch (error) {
        console.error(error);
        alert(error.message || 'Save request failed. Check browser console.');
    }
});

async function parseJsonResponse(response, label) {
    const rawText = await response.text();

    try {
        return JSON.parse(rawText);
    } catch (e) {
        console.error(label + ':', rawText);
        throw new Error('Server returned invalid JSON. Check console.');
    }
}

function showPreview() {
    const previewSection = document.getElementById('previewSection');
    if (previewSection) {
        previewSection.style.display = 'block';
        previewSection.scrollIntoView({ behavior: 'smooth' });
    } else {
        console.error('previewSection not found. Add id="previewSection" to the preview card.');
    }
}

function hidePreview() {
    const previewSection = document.getElementById('previewSection');
    if (previewSection) {
        previewSection.style.display = 'none';
    }
}

function renderManualLines() {
    if (manualLines.length === 0) {
        manualLinesBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="4">No order lines added yet.</td>
            </tr>
        `;
        return;
    }

    manualLinesBody.innerHTML = manualLines.map((line, index) => `
        <tr>
            <td data-label="SKU">
                <input
                    type="text"
                    class="manual-line-input"
                    value="${escapeHtml(line.sku_code || '')}"
                    onchange="updateManualLine(${index}, 'sku_code', this.value)"
                >
            </td>

            <td data-label="Description">
                <input
                    type="text"
                    class="manual-line-input"
                    value="${escapeHtml(line.description || '')}"
                    onchange="updateManualLine(${index}, 'description', this.value)"
                >
            </td>
            <td data-label="Requested Qty">
                <input
                    type="number"
                    min="0"
                    step="any"
                    class="manual-qty-input"
                    value="${escapeHtml(formatNumber(line.quantity))}"
                    onchange="updateManualQty(${index}, this.value)"
                >
            </td>
            <td data-label="Action">
                <button type="button" class="btn btn-danger" onclick="removeManualLine(${index})">Remove</button>
            </td>
        </tr>
    `).join('');
}

function removeManualLine(index) {
    manualLines.splice(index, 1);
    renderManualLines();
}

window.removeManualLine = removeManualLine;

function collectOrderData() {
    const params = new URLSearchParams(window.location.search);
    const editId = params.get('edit');

    return {
        order_id: editId ? Number(editId) : null,

        order_header: {
            invoice_no: document.getElementById('invoice_no').value.trim(),
            order_date: document.getElementById('order_date').value,
            delivery_date: document.getElementById('delivery_date').value,
            customer_code: document.getElementById('customer_code').value.trim(),
            customer_name: document.getElementById('customer_name').value.trim(),
            customer_address: document.getElementById('customer_address').value.trim(),
            order_number: document.getElementById('order_number').value.trim(),
            packing_slip: document.getElementById('packing_slip').value.trim(),
            internal_reference: document.getElementById('internal_reference').value.trim(),
            purchase_number: document.getElementById('purchase_number').value.trim(),
            sales_person: document.getElementById('sales_person').value.trim(),
            rounding_mode: document.getElementById('rounding_mode').value,
            min_shelf_life_months: document.getElementById('min_shelf_life_months').value
        },

        order_lines: manualLines
    };
}

function renderPreview(rows) {
    if (!rows.length) {
        previewBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="13">No preview yet.</td>
            </tr>
        `;
        return;
    }

    let html = '';

    rows.forEach(row => {
        const batchLines = row.batch_expiry_lines || [];
        const qtyOrderedLines = row.quantity_lines || [];
        const qtySuppliedLines = row.qty_supplied_lines
            || splitAlignedLines(row.qty_supplied_per_batch || row.qty_supplied || '');
        const unitsLines = row.units_per_ctn_lines
            || splitAlignedLines(row.units_per_ctn || '');
        const fullCtnLines = row.no_full_ctn_lines
            || splitAlignedLines(row.no_full_ctn || '');
        const locationLines = row.location_lines || [];
        const ctnLines = row.ctn_no_lines || [];
        const commentLines = row.comment_lines || splitAlignedLines(row.comment || '');

        const isNoStock =
            batchLines.some(v => String(v).toUpperCase().includes('NO STOCK')) ||
            locationLines.some(v => String(v).toUpperCase().includes('NO STOCK'));

        const hasManyLocations = locationLines.filter(Boolean).length > 1;
        const hasPartBoxLine = qtySuppliedLines.filter(Boolean).length > 1;

        const qtySuppliedShouldSpan = !hasManyLocations && !hasPartBoxLine;
        const unitsShouldSpan = !hasManyLocations && unitsLines.filter(Boolean).length <= 1;
        const fullCtnShouldSpan = !hasManyLocations && fullCtnLines.filter(Boolean).length <= 1;
        const locationShouldSpan = !hasManyLocations;

        const qtyOrderedShouldSpan = qtyOrderedLines.filter(Boolean).length <= 1;
        const ctnShouldSpan = !hasPartBoxLine && ctnLines.length <= 1;

        const commentHasAnyValue = commentLines.some(v => String(v || '').trim() !== '');
        const commentShouldSpan = isNoStock || !commentHasAnyValue;

        const maxLines = Math.max(
            batchLines.length,
            qtyOrderedLines.length,
            qtySuppliedLines.length,
            unitsLines.length,
            fullCtnLines.length,
            locationLines.length,
            ctnLines.length,
            commentLines.length,
            1
        );
        const batchRowSpans = getConsecutiveRowSpans(batchLines, maxLines);

        for (let i = 0; i < maxLines; i++) {
            html += `<tr>`;

            if (i === 0) {
                html += `
                    <td rowspan="${maxLines}" class="merged-cell">${escapeHtml(row.sku_code ?? '')}</td>

                    ${renderBatchCell(batchLines, batchRowSpans, i)}

                    <td rowspan="${maxLines}" class="merged-cell">${escapeHtml(row.description ?? '')}</td>

                    ${qtyOrderedShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(qtyOrderedLines[0] ?? '')}</td>`
                        : `<td class="center-cell">${escapeHtml(qtyOrderedLines[i] ?? '')}</td>`
                    }

                    <td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(row.total_qty ?? '')}</td>
                    <td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(row.total_qty_supplied ?? row.qty_supplied ?? '')}</td>

                    ${qtySuppliedShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(qtySuppliedLines[0] ?? row.qty_supplied ?? '')}</td>`
                        : `<td class="center-cell">${escapeHtml(qtySuppliedLines[i] ?? '')}</td>`
                    }

                    ${unitsShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(unitsLines[0] ?? '')}</td>`
                        : `<td class="center-cell">${escapeHtml(unitsLines[i] ?? '')}</td>`
                    }

                    ${fullCtnShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(fullCtnLines[0] ?? '')}</div></td>`
                        : `<td class="center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(fullCtnLines[i] ?? '')}</div></td>`
                    }

                    ${ctnShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${escapeHtml(ctnLines[0] ?? '')}</div></td>`
                        : `<td class="center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${escapeHtml(ctnLines[i] ?? '')}</div></td>`
                    }

                    ${locationShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(locationLines[0] ?? '')}</td>`
                        : `<td class="center-cell">${escapeHtml(locationLines[i] ?? '')}</td>`
                    }

                    ${commentShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(commentLines[0] ?? '')}</td>`
                        : `<td class="center-cell">${escapeHtml(commentLines[i] ?? '')}</td>`
                    }
                `;
            } else {
                html += `
                    ${renderBatchCell(batchLines, batchRowSpans, i)}
                    ${!qtyOrderedShouldSpan ? `<td class="center-cell">${escapeHtml(qtyOrderedLines[i] ?? '')}</td>` : ''}
                    ${!qtySuppliedShouldSpan ? `<td class="center-cell">${escapeHtml(qtySuppliedLines[i] ?? '')}</td>` : ''}
                    ${!unitsShouldSpan ? `<td class="center-cell">${escapeHtml(unitsLines[i] ?? '')}</td>` : ''}
                    ${!fullCtnShouldSpan ? `<td class="center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(fullCtnLines[i] ?? '')}</div></td>` : ''}
                    ${!ctnShouldSpan ? `<td class="center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${escapeHtml(ctnLines[i] ?? '')}</div></td>` : ''}
                    ${!locationShouldSpan ? `<td class="center-cell">${escapeHtml(locationLines[i] ?? '')}</td>` : ''}
                    ${!commentShouldSpan ? `<td class="center-cell">${escapeHtml(commentLines[i] ?? '')}</td>` : ''}
                `;
            }

            html += `</tr>`;
        }
    });

    previewBody.innerHTML = html;
}

function getConsecutiveRowSpans(lines, totalRows) {
    const values = Array.from(
        { length: totalRows },
        (_, index) => String(lines[index] ?? '').trim()
    );
    const nonEmptyValues = values.filter(Boolean);
    const spans = Array(totalRows).fill(1);

    if (new Set(nonEmptyValues).size <= 1) {
        spans.fill(0);
        spans[0] = totalRows;
        return spans;
    }

    for (let start = 0; start < totalRows;) {
        const value = values[start];

        if (value === '') {
            start++;
            continue;
        }

        let end = start + 1;
        while (end < totalRows && values[end] === value) {
            end++;
        }

        spans[start] = end - start;
        for (let index = start + 1; index < end; index++) {
            spans[index] = 0;
        }

        start = end;
    }

    return spans;
}

function renderBatchCell(lines, spans, index) {
    const rowSpan = spans[index] ?? 1;

    if (rowSpan === 0) {
        return '';
    }

    const rowspanAttribute = rowSpan > 1 ? ` rowspan="${rowSpan}"` : '';
    const mergedClass = rowSpan > 1 ? ' merged-cell' : '';

    return `<td${rowspanAttribute} class="${mergedClass} center-cell">${escapeHtml(lines[index] ?? '')}</td>`;
}

function renderMultiLine(lines) {
    if (!Array.isArray(lines) || !lines.length) {
        return '';
    }

    return lines.map(line => `<div class="stack-line">${escapeHtml(line ?? '')}</div>`).join('');
}

function formatNumber(value) {
    const num = Number(value);
    if (Number.isNaN(num)) return '';
    if (Math.floor(num) === num) return String(num);
    return num.toFixed(6).replace(/\.?0+$/, '');
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function showSuccessMessage(message, type = 'success') {
    const existing = document.querySelector('.success-message');
    if (existing) existing.remove();

    const box = document.createElement('div');
    box.className = `success-message ${type}`;

    box.innerHTML = `
        <span>${escapeHtml(message)}</span>
        <button type="button" onclick="this.parentElement.remove()">×</button>
    `;

    const header = document.querySelector('.orders-header')
        || document.querySelector('.page-content');

    header.insertAdjacentElement('afterend', box);
}

async function loadOrderForEdit(id) {
    try {
        const response = await fetch(`php/functions/get_order.php?id=${encodeURIComponent(id)}`);
        const result = await response.json();

        if (!result.success) {
            alert(result.message || 'Failed to load order for edit.');
            return;
        }

        const order = result.order || {};
        const items = result.items || [];

        document.getElementById('invoice_no').value = order.invoice_no || '';
        document.getElementById('order_date').value = order.order_date || '';
        document.getElementById('delivery_date').value = order.delivery_date || '';
        document.getElementById('customer_code').value = order.customer_code || '';
        document.getElementById('customer_name').value = order.customer_name || '';
        document.getElementById('customer_address').value = order.customer_address || '';
        document.getElementById('order_number').value = order.order_number || '';
        document.getElementById('packing_slip').value = order.packing_slip || '';
        document.getElementById('internal_reference').value = order.internal_reference || '';
        document.getElementById('purchase_number').value = order.purchase_number || '';
        document.getElementById('sales_person').value = order.sales_person || '';
        document.getElementById('rounding_mode').value = String(order.rounding_mode ?? '1');
        document.getElementById('min_shelf_life_months').value =
            String(order.min_shelf_life_months ?? '6');

        const editableItems = items.filter(item =>
            String(item.picked_done || '') !== '1'
        );

        if (editableItems.length === 0) {
            alert('No editable lines left. All lines are already done.');
            window.location.href = 'orders_list.php';
            return;
        }

        manualLines = editableItems.map(item => ({
            sku_code: String(item.sku_code || '').trim(),
            description: String(item.description || '').trim(),
            quantity: Number(item.total_qty || item.order_qty || 0)
        })).filter(line => line.sku_code && line.quantity > 0);

        renderManualLines();
        renderPreview([]);
        hidePreview();

        showSuccessMessage('Order loaded for editing.');
    } catch (error) {
        console.error(error);
        alert('Edit load failed. Check console.');
    }
}

function splitLines(value) {

    if (!value) {
        return [];
    }

    return String(value)
        .split(/[|,]/)
        .map(v => v.trim())
        .filter(v => v !== '');
}

function splitAlignedLines(value) {
    if (value === null || typeof value === 'undefined' || value === '') {
        return [];
    }

    return String(value)
        .split(/[|,]/)
        .map(v => v.trim());
}
const skuInput = document.getElementById('line_sku');
const descInput = document.getElementById('line_desc');
const skuList = document.getElementById('sku_list');

let skuData = [];

// LOAD ALL SKUS
async function loadSkus() {

    try {

        const response = await fetch('php/functions/get_skus.php');
        const result = await response.json();

        if (!result.success) return;

        skuData = result.data;

        skuList.innerHTML = '';

        skuData.forEach(item => {

            const option = document.createElement('option');

            option.value = item.SKU_Code;
            option.label = item.ProductDescription;

            skuList.appendChild(option);

        });

    } catch (error) {
        console.error(error);
    }
}

// AUTO DESCRIPTION
skuInput.addEventListener('input', () => {

    const sku = skuInput.value.trim();

    const matched = skuData.find(item => item.SKU_Code === sku);

    if (matched) {
        descInput.value = matched.ProductDescription;
    } else {
        descInput.value = '';
    }

});

loadSkus();

function updateManualQty(index, value) {
    const qty = parseFloat(value);

    if (Number.isNaN(qty) || qty <= 0) {
        alert('Please enter a valid quantity.');
        renderManualLines();
        return;
    }

    manualLines[index].quantity = qty;
}

window.updateManualQty = updateManualQty;

function updateManualLine(index, field, value) {
    if (!manualLines[index]) return;

    manualLines[index][field] = value.trim();
}

window.updateManualLine = updateManualLine;
