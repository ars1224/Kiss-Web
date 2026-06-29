const params = new URLSearchParams(window.location.search);
const orderId = params.get('id');

let currentOrder = null;
let currentItems = [];
let orderRefreshTimer = null;
let isLoadingOrder = false;
let pendingOrderRefresh = false;
const ORDER_REFRESH_INTERVAL = 10000;

document.addEventListener('DOMContentLoaded', () => {
    if (!orderId) {
        document.getElementById('orderViewBody').innerHTML = 'Missing order ID.';
        return;
    }

    const msg = params.get('msg');
    if (msg === 'packing_uploaded') {
        showSuccessMessage('Packing slip uploaded. Order marked as sent.');
        window.history.replaceState({}, document.title, `order_view.php?id=${encodeURIComponent(orderId)}`);
    }

    document.getElementById('startPickingBtn')?.addEventListener('click', startPicking);
    document.getElementById('savePickingBtn')?.addEventListener('click', savePicking);
    document.getElementById('checkedBtn')?.addEventListener('click', checkOrder);
    document.getElementById('bookCourierBtn')?.addEventListener('click', bookCourier);
    document.getElementById('uploadPackingSlipBtn')?.addEventListener('click', uploadPackingSlip);
    document.getElementById('downloadPickSlipBtn')?.addEventListener('click', downloadCurrentPickSlip);

    document.getElementById('courierName')?.addEventListener('change', () => {
        const courier = document.getElementById('courierName').value;
        document.getElementById('customCourierName').style.display =
            courier === 'Other' ? 'inline-block' : 'none';
    });

    loadOrder();
    startOrderAutoRefresh();
});

function startOrderAutoRefresh() {
    if (orderRefreshTimer) {
        clearInterval(orderRefreshTimer);
    }

    orderRefreshTimer = setInterval(() => {
        loadOrder({ silent: true });
    }, ORDER_REFRESH_INTERVAL);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            loadOrder({ silent: true });
        }
    });
}

function isOrderEntryActive() {
    const active = document.activeElement;

    return Boolean(
        active &&
        active.closest &&
        active.closest('#orderViewBody') &&
        active.matches('input, select, textarea')
    );
}

async function loadOrder(options = {}) {
    if (isLoadingOrder) {
        return;
    }

    const silent = Boolean(options.silent);

    if (silent && isOrderEntryActive()) {
        pendingOrderRefresh = true;
        return;
    }

    isLoadingOrder = true;

    try {
        const response = await fetch(
            `php/functions/get_order.php?id=${encodeURIComponent(orderId)}&t=${Date.now()}`,
            { cache: 'no-store' }
        );
        const result = await response.json();

        if (!result.success) {
            if (!silent) {
                document.getElementById('orderViewBody').innerHTML = escapeHtml(result.message || 'Failed to load order.');
            }
            return;
        }

        currentOrder = result.order;
        currentItems = result.items || [];
        renderOrder(currentOrder, currentItems);
        pendingOrderRefresh = false;
    } catch (error) {
        console.error(error);
        if (!silent) {
            document.getElementById('orderViewBody').innerHTML = 'Error loading order.';
        }
    } finally {
        isLoadingOrder = false;
    }
}

document.addEventListener('focusout', () => {
    if (pendingOrderRefresh) {
        window.setTimeout(() => {
            if (!isOrderEntryActive()) {
                loadOrder({ silent: true });
            }
        }, 150);
    }
});

async function startPicking() {
    const response = await fetch('php/functions/start_picking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: orderId })
    });

    const result = await response.json();
    if (!result.success) {
        alert(result.message || 'Failed to start picking.');
        return;
    }

    await loadOrder();
}

async function savePicking() {
    const mobileView = window.matchMedia('(max-width: 768px)').matches;
    const rowSelector = mobileView
        ? '[data-mobile-order-item-row]'
        : '[data-order-item-row]';
    const rows = document.querySelectorAll(rowSelector);
    const grouped = new Map();

    rows.forEach(row => {
        const itemId = Number(row.dataset.itemId);
        if (!itemId) return;

        if (!grouped.has(itemId)) {
            grouped.set(itemId, {
                id: itemId,
                ctnValues: [],
                doneChecked: false,
                hasDoneCheckbox: false
            });
        }

        const group = grouped.get(itemId);

        row.querySelectorAll('.pick-ctn-input').forEach(input => {
            group.ctnValues.push(input.value.trim());
        });

        const doneInput =
            row.querySelector('.pick-done-input') ||
            row.querySelector('.pick-done-checkbox');

        if (doneInput) {
            group.hasDoneCheckbox = true;
            if (doneInput.checked) {
                group.doneChecked = true;
            }
        }
    });

    const items = [];

grouped.forEach(group => {
    if (!group.hasDoneCheckbox) {
        return;
    }

    items.push({
        id: group.id,
        picked_ctn_no: group.ctnValues.join(' | '),
        picked_done: group.doneChecked ? '1' : '0'
    });
});

    try {
        const response = await fetch('php/functions/save_picking_lines.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: Number(orderId),
                items: items
            })
        });

        const rawText = await response.text();
        console.log('Save picking raw response:', rawText);

        let result;

        try {
            result = JSON.parse(rawText);
        } catch (e) {
            alert('Save picking failed. Server returned invalid JSON. Check console.');
            return;
        }

        if (!result.success) {
            alert(result.message || 'Failed to save picking.');
            return;
        }

        await loadOrder();

    } catch (error) {
        console.error(error);
        alert('Save picking request failed.');
    }
}

async function checkOrder() {
    const checkerName = document.getElementById('checkerName').value.trim();

    if (!checkerName) {
        alert('Please enter checker name.');
        return;
    }

    try {
        const response = await fetch('php/functions/check_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: Number(orderId),
                checker_name: checkerName
            })
        });

        const rawText = await response.text();
        console.log('Check order raw response:', rawText);

        let result;

        try {
            result = JSON.parse(rawText);
        } catch (e) {
            alert('Check failed. Server returned invalid JSON. Check console.');
            return;
        }

        if (!result.success) {
            alert(result.message || 'Check failed.');
            return;
        }

        await loadOrder();

    } catch (error) {
        console.error(error);
        alert('Check request failed.');
    }
}

async function bookCourier() {
    let courierName = document.getElementById('courierName').value;
    const customCourier = document.getElementById('customCourierName').value.trim();
    const courierReference = document.getElementById('courierReference').value.trim();

    if (courierName === 'Other') courierName = customCourier;

    if (!courierName || !courierReference) {
        alert('Please enter courier and reference.');
        return;
    }

    const response = await fetch('php/functions/book_courier.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: orderId,
            courier_name: courierName,
            courier_reference: courierReference
        })
    });

    const result = await response.json();
    if (!result.success) {
        alert(result.message || 'Booking failed.');
        return;
    }

    await loadOrder();
}

async function uploadPackingSlip() {
    const fileInput = document.getElementById('packingSlipFile');
    const uploadButton = document.getElementById('uploadPackingSlipBtn');

    if (!fileInput.files.length) {
        alert('Please choose a packing slip file.');
        return;
    }

    uploadButton.disabled = true;
    uploadButton.textContent = 'Uploading...';

    try {
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('packing_slip', fileInput.files[0]);

        const response = await fetch('php/functions/upload_packing_slip.php', {
            method: 'POST',
            body: formData,
            cache: 'no-store'
        });

        const rawText = await response.text();
        let result;

        try {
            result = JSON.parse(rawText);
        } catch (error) {
            console.error('Upload response:', rawText);
            throw new Error('Server returned an invalid upload response.');
        }

        if (!result.success) {
            throw new Error(result.message || 'Upload failed.');
        }

        window.location.assign(
            `order_view.php?id=${encodeURIComponent(orderId)}&msg=packing_uploaded&t=${Date.now()}`
        );
    } catch (error) {
        console.error(error);
        alert(error.message || 'Upload failed.');
        uploadButton.disabled = false;
        uploadButton.textContent = 'Upload & Mark Sent';
    }
}

function renderOrder(order, items) {
    const isPicking = order.status === 'ongoing';
    const status = order.status || 'pending';

    document.getElementById('startPickingBtn').style.display =
        order.status === 'pending' ? 'inline-block' : 'none';

    document.getElementById('savePickingBtn').style.display =
        order.status === 'ongoing' ? 'inline-block' : 'none';

    const downloadBtn = document.getElementById('downloadPickSlipBtn');
    if (downloadBtn) {
        const downloadUrl = `php/functions/export_pick_slip.php?id=${encodeURIComponent(orderId)}`;
        downloadBtn.href = downloadUrl;
        downloadBtn.dataset.downloadUrl = downloadUrl;
        downloadBtn.style.display =
            ['waiting_packing_slip', 'sent', 'not_sent'].includes(order.status) ? 'inline-block' : 'none';
    }

    document.getElementById('checkingPanel').style.display =
        order.status === 'ongoing' && isPickingComplete(items) ? 'block' : 'none';

    document.getElementById('bookingPanel').style.display =
        order.status === 'booking' ? 'block' : 'none';

    document.getElementById('packingSlipPanel').style.display =
        order.status === 'waiting_packing_slip' ? 'block' : 'none';

    document.getElementById('orderViewBody').innerHTML = `
        <div class="print-area order-view-shell">
            <section class="order-summary-card">
                <div class="order-summary-header">
                    <div>
                        <span class="order-summary-kicker">Picking List</span>
                        <h2>Invoice ${formatEmpty(order.invoice_no)}</h2>
                        <p>${formatEmpty(order.customer_name)}${order.customer_code ? ` - ${escapeHtml(order.customer_code)}` : ''}</p>
                    </div>
                    <span class="status-badge status-${escapeHtml(status)}">${escapeHtml(formatStatus(status))}</span>
                </div>

                <div class="order-meta-grid">
                    ${renderMetaItem('Order No', order.order_number)}
                    ${renderMetaItem('Order Date', order.order_date)}
                    ${renderMetaItem('Delivery Date', order.completed_at ? formatDateTime(order.completed_at) : order.delivery_date)}
                    ${renderMetaItem('Sales Person', order.sales_person)}
                    ${renderMetaItem('Packed By', order.picker_name)}
                    ${renderMetaItem('Checked By', order.checker_name)}
                    ${renderMetaItem('Checked At', order.checked_at ? formatDateTime(order.checked_at) : '')}
                    ${renderMetaItem('Courier', order.courier_name)}
                    ${renderMetaItem('Courier Ref', order.courier_reference)}
                    ${order.packing_slip_file ? `
                        <div class="order-meta-item">
                            <span>Packing Slip</span>
                            <strong><a href="${escapeHtml(order.packing_slip_file)}" target="_blank">View File</a></strong>
                        </div>
                    ` : ''}
                </div>

                <div class="order-address-block">
                    <span>Delivery Address</span>
                    <strong>${formatEmpty(order.customer_address)}</strong>
                </div>
            </section>

            <div class="order-print-header print-only-summary">
                <div>
                    <h2>Picking List</h2>
                    <p><strong>Invoice:</strong> ${escapeHtml(order.invoice_no || '')}</p>
                    <p><strong>Order No:</strong> ${escapeHtml(order.order_number || '')}</p>
                    <p><strong>Status:</strong> ${escapeHtml(formatStatus(order.status || 'pending'))}</p>
                </div>
                <div>
                    <p><strong>Date:</strong> ${escapeHtml(order.order_date || '')}</p>
                    ${order.checked_at ? `<p><strong>Checked At:</strong> ${formatDateTime(order.checked_at)}</p>` : ''}
                    <p><strong>Delivery Date:</strong> ${order.completed_at? formatDateTime(order.completed_at): escapeHtml(order.delivery_date || '')}</p>
                </div>
            </div>

            <div class="order-customer-box print-only-block">
                <p><strong>Customer:</strong> ${escapeHtml(order.customer_name || '')}</p>
                <p><strong>Address:</strong> ${escapeHtml(order.customer_address || '')}</p>
                <p><strong>Customer Code:</strong> ${escapeHtml(order.customer_code || '')}</p>
                <p><strong>Sales Person:</strong> ${escapeHtml(order.sales_person || '')}</p>

                ${order.picker_name ? `<p><strong>Packed By:</strong> ${escapeHtml(order.picker_name)}</p>` : ''}

                ${order.checker_name ? `<p><strong>Checked By:</strong> ${escapeHtml(order.checker_name)}</p>` : ''}

                ${order.courier_name ? `<p><strong>Courier:</strong> ${escapeHtml(order.courier_name)}</p>` : ''}
                
                ${order.packing_slip_file ? `<p><strong>Packing Slip:</strong> <a href="${escapeHtml(order.packing_slip_file)}" target="_blank">View File</a></p>` : ''}
            </div>

            <section class="order-lines-card">
                <div class="order-lines-header">
                    <div>
                        <h3>Items</h3>
                        <p>${items.length} ${items.length === 1 ? 'line' : 'lines'}</p>
                    </div>
                </div>

            <div class="table-wrap order-items-table-wrap">
                <table class="orders-table preview-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>BATCH EXPIRY</th>
                            <th>Description</th>
                            <th>Qty Ordered</th>
                            <th>TOTAL Qty Ordered</th>
                            <th>TOTAL QTY SUPPLIED</th>
                            <th>QTY SUPPLIED</th>
                            <th>UNITS/CTN</th>
                            <th class="full-ctn-cell">NO. FULL CTN</th>
                            <th class="ctn-number-cell">CTN #</th>
                            <th>LOCATION</th>
                            <th>COMMENT</th>
                            ${['pending', 'ongoing', 'booking', 'waiting_packing_slip'].includes(currentOrder.status || '')
                                ? '<th>Print</th>'
                                : ''
                            }
                            <th class="no-print">Done</th>
                        </tr>
                    </thead>
                    <tbody>${renderItems(items, isPicking)}</tbody>
                </table>
            </div>
            <div class="mobile-order-items" aria-label="Order items">
                ${renderMobileItems(items, isPicking)}
            </div>
            </section>
        </div>
    `;
}


function renderMobileItems(items, isPicking) {
    if (!items.length) return '<p class="mobile-order-empty">No items found.</p>';

    const canPrint = ['pending', 'ongoing', 'booking', 'waiting_packing_slip']
        .includes(currentOrder.status || '');

    return items.map(item => {
        const batch = splitAlignedLines(item.batch_no || '');
        const ordered = splitAlignedLines(item.order_qty || '');
        const supplied = splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || '');
        const units = splitAlignedLines(item.units_per_ctn || '');
        const fullCtn = splitAlignedLines(item.full_ctn || '');
        const locations = splitAlignedLines(item.location || '');
        const ctn = splitAlignedLines(item.picked_ctn_no || item.ctn_no || '');
        const comments = splitAlignedLines(item.comment || '');
        const isDone = String(item.picked_done || '') === '1';
        const canEdit = isPicking && !isDone;
        const ctnInputs = Array.from(
            { length: ctn.length > 1 ? ctn.length : 1 },
            (_, index) => renderSingleCtnInput(ctn[index] || '', canEdit, item, index)
        );
        const showActions = canPrint || isDone || canEdit;

        return `
            <article class="mobile-order-card ${isDone ? 'picked-row-done' : ''}"
                data-mobile-order-item-row data-item-id="${item.id}">
                <header class="mobile-order-card-header">
                    <h4>${formatEmpty(item.sku_code)}</h4>
                    ${isDone ? '<span class="mobile-order-done-badge">Done</span>' : ''}
                </header>
                <div class="mobile-order-description">
                    <span>Description</span>
                    <strong>${formatEmpty(item.description)}</strong>
                </div>
                <dl class="mobile-order-details">
                    ${renderMobileDetail('Qty Ordered', item.total_qty || ordered)}
                    ${renderMobileDetail('Qty Supplied', item.total_qty_supplied || item.qty_supplied || supplied)}
                    ${renderMobileDetail('Units/CTN', units)}
                    ${renderMobileDetail('Location', locations, 'mobile-location-value')}
                    ${renderMobileDetail('Batch / Expiry', batch)}
                    ${renderMobileDetail('No. Full CTN', fullCtn)}
                    ${renderMobileDetail('CTN #', ctnInputs, 'mobile-ctn-value', true)}
                    ${renderMobileDetail('Comment', comments, 'mobile-comment-value')}
                </dl>
                ${showActions ? `
                    <footer class="mobile-order-card-actions no-print">
                        ${canPrint ? `<button type="button" class="btn-mini btn-print"
                            onclick="printSkuLabels(${item.id})">Print</button>` : ''}
                        ${(isDone || canEdit) ? `<label class="mobile-done-control">
                            <span>Done</span>${renderLineDone(item, canEdit, 0)}
                        </label>` : ''}
                    </footer>
                ` : ''}
            </article>
        `;
    }).join('');
}

function renderMobileDetail(label, value, extraClass = '', isHtml = false) {
    const values = (Array.isArray(value) ? value : [value])
        .map(entry => String(entry ?? '').trim()).filter(Boolean);
    if (!values.length && label !== 'CTN #') return '';

    const content = values.length
        ? values.map(entry => `<span>${isHtml ? entry : escapeHtml(entry)}</span>`).join('')
        : '<span class="muted-dash">-</span>';

    return `<div class="mobile-order-detail ${extraClass}">
        <dt>${escapeHtml(label)}</dt><dd>${content}</dd>
    </div>`;
}

function renderItems(items, isPicking) {
    if (!items.length) {
        const canPrintColumn = ['pending', 'ongoing', 'booking', 'waiting_packing_slip'].includes(currentOrder.status || '');
        return `<tr class="empty-row"><td colspan="${canPrintColumn ? 14 : 13}">No items found.</td></tr>`;
    }

    let html = '';

    items.forEach(item => {
        const batchLines = splitAlignedLines(item.batch_no || '');
        const qtyOrderedLines = splitAlignedLines(item.order_qty || '');
        const qtySuppliedLines = splitAlignedLines(
            item.qty_supplied_per_batch || item.qty_supplied || ''
        );
        const unitsLines = splitAlignedLines(item.units_per_ctn || '');
        const fullCtnLines = splitAlignedLines(item.full_ctn || '');
        const locationLines = splitAlignedLines(item.location || '');
        const ctnLines = splitAlignedLines(item.picked_ctn_no || item.ctn_no || '');
        const commentLines = splitAlignedLines(item.comment || '');

        const itemFullyNoStock = isItemFullyNoStock(
            item,
            batchLines,
            locationLines,
            qtySuppliedLines
        );

        const hasManyLocations = locationLines.filter(Boolean).length > 1;
        const hasPartBoxLine = qtySuppliedLines.filter(Boolean).length > 1;

        const qtyOrderedShouldSpan = qtyOrderedLines.filter(Boolean).length <= 1;
        const qtySuppliedShouldSpan = !hasManyLocations && !hasPartBoxLine;
        const roundingMode = String(currentOrder?.rounding_mode ?? item.rounding_mode ?? '0');

        const unitsShouldSpan = roundingMode === '1' || unitsLines.filter(Boolean).length <= 1;
        const fullCtnShouldSpan =
            !String(item.full_ctn || '').includes('|')
            && fullCtnLines.filter(Boolean).length <= 1;
        const ctnShouldSpan = ctnLines.length <= 1;
        const locationShouldSpan = !hasManyLocations;

        const commentHasAnyValue = commentLines.some(v => String(v || '').trim() !== '');
        const commentShouldSpan = itemFullyNoStock || !commentHasAnyValue;

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

        const isDoneRow = String(item.picked_done || '') === '1';
        const canEditRow = isPicking && !isDoneRow;

        for (let i = 0; i < maxLines; i++) {
            const isDoneRow = String(item.picked_done || '') === '1';

            html += `<tr data-order-item-row data-item-id="${item.id}" class="${isDoneRow ? 'picked-row-done' : ''}">`;

            if (i === 0) {
                html += `
                    <td rowspan="${maxLines}" class="merged-cell">${escapeHtml(item.sku_code || '')}</td>

                    ${renderBatchCell(batchLines, batchRowSpans, i)}

                    <td rowspan="${maxLines}" class="merged-cell">${escapeHtml(item.description || '')}</td>

                    ${qtyOrderedShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(qtyOrderedLines[0] || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(qtyOrderedLines[i] || '')}</td>`
                    }

                    <td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(item.total_qty || '')}</td>
                    <td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(item.total_qty_supplied || item.qty_supplied || '')}</td>

                    ${qtySuppliedShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(qtySuppliedLines[0] || item.qty_supplied || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(qtySuppliedLines[i] || '')}</td>`
                    }

                    ${unitsShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(unitsLines[0] || item.units_per_ctn || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(unitsLines[i] || '')}</td>`
                    }
                    
                    ${fullCtnShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(item.full_ctn || '')}</div></td>`
                        : `<td class="center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(fullCtnLines[i] || '')}</div></td>`
                    }

                    ${ctnShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${renderSingleCtnInput(ctnLines[0] || '', isPicking, item, 0)}</div></td>`
                        : `<td class="center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${renderSingleCtnInput(ctnLines[i] || '', canEditRow, item, i)}</div></td>`
                    }

                    ${locationShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(locationLines[0] || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(locationLines[i] || '')}</td>`
                    }

                    ${commentShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(commentLines[0] || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(commentLines[i] || '')}</td>`
                    }

                    ${['pending', 'ongoing', 'booking', 'waiting_packing_slip'].includes(currentOrder.status || '')
                        ? `
                            <td rowspan="${maxLines}" class="merged-cell center-cell no-print">
                                <button type="button"
                                    class="btn-mini btn-print"
                                    onclick="printSkuLabels(${item.id})">
                                    Print
                                </button>
                            </td>
                        `
                        : ''
                    }

                    <td rowspan="${maxLines}" class="merged-cell center-cell no-print">
                        ${renderLineDone(item, canEditRow, 0)}
                    </td>
                `;
            } else {
                html += `
                    ${renderBatchCell(batchLines, batchRowSpans, i)}
                    ${!qtyOrderedShouldSpan ? `<td class="center-cell">${escapeHtml(qtyOrderedLines[i] || '')}</td>` : ''}
                    ${!qtySuppliedShouldSpan ? `<td class="center-cell">${escapeHtml(qtySuppliedLines[i] || '')}</td>` : ''}
                    ${!unitsShouldSpan ? `<td class="center-cell">${escapeHtml(unitsLines[i] || '')}</td>` : ''}
                    ${!fullCtnShouldSpan ? `<td class="center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(fullCtnLines[i] || '')}</div></td>` : ''}

                    ${!ctnShouldSpan
                        ? `<td class="center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${renderSingleCtnInput(ctnLines[i] || '', canEditRow, item, i)}</div></td>`
                        : ''
                    }

                    ${!locationShouldSpan ? `<td class="center-cell">${escapeHtml(locationLines[i] || '')}</td>` : ''}
                    ${!commentShouldSpan ? `<td class="center-cell">${escapeHtml(commentLines[i] || '')}</td>` : ''}
                `;
            }

            html += `</tr>`;
        }
    });

    return html;
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

    return lines
        .map(line => `<div class="stack-line">${escapeHtml(line ?? '')}</div>`)
        .join('');
}


function renderCtnInputs(lines, maxLines, isPicking, item) {
    const batchLines = splitAlignedLines(item.batch_no || '');
    const locationLines = splitAlignedLines(item.location || '');
    const qtySuppliedLines = splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || '');
    let html = '';

    for (let i = 0; i < maxLines; i++) {
        const isNoStock = isNoStockLine(batchLines, locationLines, qtySuppliedLines, i);

        if (isPicking && !isNoStock) {
            html += `<div class="stack-line"><input class="pick-ctn-input" type="text" value="${escapeHtml(lines[i] || '')}" placeholder="CTN #"></div>`;
        } else {
            html += `<div class="stack-line">${isNoStock ? 'NO STOCK' : escapeHtml(lines[i] || '')}</div>`;
        }
    }

    return html;
}

function renderDoneInputs(lines, maxLines, isPicking, item) {
    const batchLines = splitAlignedLines(item.batch_no || '');
    const locationLines = splitAlignedLines(item.location || '');
    const qtySuppliedLines = splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || '');
    let html = '';

    for (let i = 0; i < maxLines; i++) {
        const isNoStock = isNoStockLine(batchLines, locationLines, qtySuppliedLines, i);

        const checked = lines[i] === '1';

        if (isPicking && !isNoStock) {
            html += `<div class="stack-line"><input class="pick-done-input" type="checkbox" ${checked ? 'checked' : ''}></div>`;
        } else {
            html += `<div class="stack-line">${isNoStock ? 'N/A' : (checked ? 'Done' : '')}</div>`;
        }
    }

    return html;
}

function isPickingComplete(items) {
    return items.every(item => {
        if (isItemFullyNoStock(item)) {
            return true;
        }

        const ctnLines = splitLines(item.picked_ctn_no || '');
        const doneValue = (item.picked_done || '').includes('1');

        const hasCtn = ctnLines.some(v => v.trim() !== '');

        return hasCtn && doneValue;
    });
}

function splitLines(value) {

    if (!value) {
        return [];
    }

    return String(value)
        .split(/[|,]/) // split by | OR comma
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

function isNoStockValue(value) {
    return String(value ?? '').trim().toUpperCase().includes('NO STOCK');
}

function isNoStockLine(batchLines, locationLines, qtySuppliedLines, index) {
    return isNoStockValue(batchLines[index]) ||
        isNoStockValue(locationLines[index]) ||
        isNoStockValue(qtySuppliedLines[index]);
}

function hasStockedLine(batchLines, locationLines, qtySuppliedLines, index) {
    const batch = String(batchLines[index] ?? '').trim();
    const location = String(locationLines[index] ?? '').trim();
    const qtySupplied = String(qtySuppliedLines[index] ?? '').trim();

    if (isNoStockLine(batchLines, locationLines, qtySuppliedLines, index)) {
        return false;
    }

    return batch !== '' || location !== '' || qtySupplied !== '';
}

function isItemFullyNoStock(item, batchLinesArg, locationLinesArg, qtySuppliedLinesArg) {
    const totalSupplied = String(item.total_qty_supplied ?? item.qty_supplied ?? '').trim();

    if (totalSupplied !== '' && isNoStockValue(totalSupplied)) {
        return true;
    }

    const batchLines = batchLinesArg || splitAlignedLines(item.batch_no || '');
    const locationLines = locationLinesArg || splitAlignedLines(item.location || '');
    const qtySuppliedLines = qtySuppliedLinesArg || splitAlignedLines(
        item.qty_supplied_per_batch || item.qty_supplied || ''
    );
    const lineCount = Math.max(batchLines.length, locationLines.length, qtySuppliedLines.length, 1);

    let hasNoStock = false;
    let hasStock = false;

    for (let index = 0; index < lineCount; index++) {
        if (isNoStockLine(batchLines, locationLines, qtySuppliedLines, index)) {
            hasNoStock = true;
        }

        if (hasStockedLine(batchLines, locationLines, qtySuppliedLines, index)) {
            hasStock = true;
        }
    }

    return hasNoStock && !hasStock;
}

function isNoStockLineFromItem(item, lineIndex) {
    return isNoStockLine(
        splitAlignedLines(item.batch_no || ''),
        splitAlignedLines(item.location || ''),
        splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || ''),
        lineIndex
    );
}

function renderMultiLine(value) {
    const lines = splitLines(value);
    const clean = lines.filter(v => v !== '');
    const unique = [...new Set(clean)];

    if (unique.length === 1) {
        return `<div class="stack-line">${escapeHtml(unique[0])}</div>`;
    }

    return lines.map(v => `<div class="stack-line">${escapeHtml(v)}</div>`).join('');
}

function renderMergedColumn(value, maxLines) {
    const safe = escapeHtml(value ?? '');

    let html = '';

    for (let i = 0; i < maxLines; i++) {
        if (i === 0) {
            html += `<div class="stack-line merged">${safe}</div>`;
        } else {
            html += `<div class="stack-line empty"></div>`;
        }
    }

    return html;
}

function showSuccessMessage(message) {
    const existing = document.querySelector('.success-message');
    if (existing) existing.remove();

    const box = document.createElement('div');
    box.className = 'success-message';
    box.innerHTML = `
        <span>${escapeHtml(message)}</span>
        <button type="button" onclick="this.parentElement.remove()">×</button>
    `;

    const page = document.querySelector('.orders-page');
    const actions = document.querySelector('.order-top-actions');

    if (actions) {
        actions.insertAdjacentElement('afterend', box);
    } else if (page) {
        page.prepend(box);
    } else {
        document.body.prepend(box);
    }
}

function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    return isNaN(date) ? value : date.toLocaleString();
}

function formatStatus(status) {
    switch (status) {
        case 'pending': return 'Pending';
        case 'ongoing': return 'Ongoing';
        case 'booking': return 'Booking';
        case 'waiting_packing_slip': return 'Waiting Slip';
        case 'sent': return 'Sent';
        case 'not_sent': return 'Not Sent';
        default: return status;
    }
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatEmpty(value) {
    const text = String(value ?? '').trim();
    return text ? escapeHtml(text) : '<span class="muted-dash">-</span>';
}

function renderMetaItem(label, value) {
    return `
        <div class="order-meta-item">
            <span>${escapeHtml(label)}</span>
            <strong>${formatEmpty(value)}</strong>
        </div>
    `;
}

function shouldMergeCtnNo(fullCtnValue) {
    const value = String(fullCtnValue || '').trim();

    if (value === '' || value.toUpperCase().includes('NO STOCK')) {
        return false;
    }

    const num = Number(value);

    return Number.isFinite(num) && Number.isInteger(num);
}

function renderMergedCtnInput(lines, maxLines, isPicking, item) {
    const isNoStock = isItemFullyNoStock(item);

    const value = String(lines || '');

    let html = '';

    for (let i = 0; i < maxLines; i++) {
        if (i === 0) {
            if (isPicking && !isNoStock) {
                html += `
                    <div class="stack-line merged">
                        <input class="pick-ctn-input" type="text" value="${escapeHtml(value)}" placeholder="CTN #">
                    </div>
                `;
            } else {
                html += `<div class="stack-line merged">${isNoStock ? 'NO STOCK' : escapeHtml(value)}</div>`;
            }
        } else {
            html += `<div class="stack-line empty"></div>`;
        }
    }

    return html;
}

function renderLineDone(item, canEditRow, lineIndex) {
    if (isItemFullyNoStock(item)) {
        return `<span class="na-text">N/A</span>`;
    }

    if (String(item.picked_done || '') === '1') {
        return `<span class="done-text">Done</span>`;
    }

    if (!canEditRow) {
        return '';
    }

    return `
        <input
            type="checkbox"
            class="pick-done-checkbox"
            data-line-index="${lineIndex}"
        >
    `;
}



function renderSingleDone(item, isPicking) {

    const isDone =
        String(item.picked_done || '') === '1';

    if (isDone) {
        return `<span class="done-text">Done</span>`;
    }

    if (!isPicking) {
        return '';
    }

    return `
        <input
            type="checkbox"
            class="pick-done-checkbox"
        >
    `;
}

function formatQtySupplied(item) {
    const qty = Number(item.qty_supplied);

    if (isItemFullyNoStock(item) || qty === 0) {
        return 'NO STOCK';
    }

    return item.qty_supplied;
}

function renderSingleCtnInput(value, isPicking, item, lineIndex = 0) {
    if (isNoStockLineFromItem(item, lineIndex)) {
        return 'NO STOCK';
    }

    const isDone =
        String(item.picked_done || '') === '1';

    if (isPicking) {

        return `
            <input
                class="pick-ctn-input"
                type="text"
                value="${escapeHtml(value || '')}"
                placeholder="CTN #"
                ${isDone ? 'disabled' : ''}
            >
        `;
    }

    return escapeHtml(value || '');
}

async function printSkuLabels(itemId) {
    try {
        const response = await fetch(
            `php/functions/print_carton_labels.php?item_id=${encodeURIComponent(itemId)}`
        );

        const result = await response.json();

        if (!result.ok) {
            alert(result.message || 'Print failed.');
            return;
        }

        alert('SKU labels sent to printer.');
    } catch (error) {
        console.error(error);
        alert('Print request failed.');
    }
}

async function downloadCurrentPickSlip(event) {
    event.preventDefault();

    const button = event.currentTarget;
    const url = button.dataset.downloadUrl || button.getAttribute('href');

    if (!url) {
        return;
    }

    const originalText = button.textContent;
    const invoice = currentOrder?.invoice_no || orderId || 'order';

    button.style.pointerEvents = 'none';
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Preparing...';

    try {
        await downloadGeneratedOrderFile(
            url,
            `pick_slip_${sanitizeDownloadFilename(invoice)}.xlsx`
        );
    } catch (error) {
        console.error(error);
        alert(error.message || 'Pick slip download failed.');
    } finally {
        button.style.pointerEvents = '';
        button.removeAttribute('aria-busy');
        button.textContent = originalText;
    }
}

async function downloadGeneratedOrderFile(url, fallbackFilename) {
    const response = await fetch(url, { cache: 'no-store' });

    if (!response.ok) {
        const message = await response.text();
        throw new Error(message || 'Download failed.');
    }

    const blob = await response.blob();
    const filename = getDownloadFilename(response, fallbackFilename);
    saveBlob(blob, filename);
}

function getDownloadFilename(response, fallbackFilename) {
    const disposition = response.headers.get('Content-Disposition') || '';
    const filenameMatch = disposition.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i);
    const filename = filenameMatch ? (filenameMatch[1] || filenameMatch[2]) : '';

    if (!filename) {
        return fallbackFilename;
    }

    try {
        return decodeURIComponent(filename);
    } catch (error) {
        return filename;
    }
}

function saveBlob(blob, filename) {
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = objectUrl;
    link.download = filename;
    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    link.remove();

    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

function sanitizeDownloadFilename(value) {
    return String(value || 'file').replace(/[^A-Za-z0-9_-]+/g, '_');
}

